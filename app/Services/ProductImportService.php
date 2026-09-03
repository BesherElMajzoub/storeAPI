<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;
use SplFileObject;

class ProductImportService
{
    private const MAX_ROWS = 5000;

    private const PRODUCT_FIELDS = [
        'name', 'description', 'price', 'discount_price', 'stock_qty', 'status',
        'in_stock', 'is_featured', 'meta_title', 'meta_description', 'options',
        'weight_oz', 'length_in', 'width_in', 'height_in',
    ];

    private const VARIANT_FIELDS = [
        'name', 'price', 'stock_qty', 'attributes',
        'weight_oz', 'length_in', 'width_in', 'height_in',
    ];

    public function __construct(private readonly ProductService $products) {}

    public function process(UploadedFile $file, bool $dryRun): array
    {
        $rows = $this->read($file);
        $analysis = $this->analyze($rows);

        if ($analysis['summary']['errors'] > 0 || $dryRun) {
            unset($analysis['normalized']);

            return $analysis + ['committed' => false];
        }

        DB::transaction(function () use ($analysis) {
            foreach (collect($analysis['normalized'])->where('type', 'product') as $row) {
                $this->upsertProduct($row);
            }
            foreach (collect($analysis['normalized'])->where('type', 'variant') as $row) {
                $this->upsertVariant($row);
            }
        }, 3);

        unset($analysis['normalized']);

        return $analysis + ['committed' => true];
    }

    private function read(UploadedFile $file): array
    {
        $csv = new SplFileObject($file->getRealPath(), 'r');
        $csv->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
        $header = null;
        $rows = [];

        foreach ($csv as $index => $values) {
            if ($values === [null] || $values === false) {
                continue;
            }

            if ($header === null) {
                $header = array_map(fn ($value) => trim((string) $value), $values);
                $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0] ?? '');
                if (count($header) !== count(array_unique($header)) || in_array('', $header, true)) {
                    throw new RuntimeException('CSV headers must be non-empty and unique.');
                }

                continue;
            }

            if (count($rows) >= self::MAX_ROWS) {
                throw new RuntimeException('CSV files may contain at most 5,000 data rows.');
            }

            $values = array_pad($values, count($header), null);
            $rows[] = ['row' => $index + 1, 'data' => array_combine($header, array_slice($values, 0, count($header)))];
        }

        if ($header === null || ! collect(['type', 'sku', 'name'])->every(fn ($field) => in_array($field, $header, true))) {
            throw new RuntimeException('CSV must include type, sku, and name headers.');
        }

        if ($rows === []) {
            throw new RuntimeException('CSV must contain at least one data row.');
        }

        return $rows;
    }

    private function analyze(array $rows): array
    {
        $results = [];
        $normalizedRows = [];
        $seenSkus = [];

        foreach ($rows as $csvRow) {
            $row = $this->normalize($csvRow['data']);
            $type = $row['type'] ?? null;
            $sku = $row['sku'] ?? null;
            $errors = [];

            if ($sku && isset($seenSkus[mb_strtolower($sku)])) {
                $errors['sku'][] = "SKU is duplicated in CSV row {$seenSkus[mb_strtolower($sku)]}.";
            } elseif ($sku) {
                $seenSkus[mb_strtolower($sku)] = $csvRow['row'];
            }

            $validator = Validator::make($row, $type === 'variant' ? $this->variantRules() : $this->productRules());
            if ($validator->fails()) {
                $errors = array_merge_recursive($errors, $validator->errors()->toArray());
            }

            if ($type === 'product') {
                $category = isset($row['category_slug'])
                    ? Category::where('slug', $row['category_slug'])->first()
                    : null;
                if (isset($row['category_slug']) && ! $category) {
                    $errors['category_slug'][] = 'Category slug was not found.';
                }
                $row['category_id'] = $category?->id;

                if (ProductVariant::where('sku', $sku)->exists()) {
                    $errors['sku'][] = 'SKU is already used by a product variant.';
                }
                $existing = Product::withTrashed()->where('sku', $sku)->first();
                if ($existing?->trashed()) {
                    $errors['sku'][] = 'SKU belongs to a deleted product and cannot be imported automatically.';
                }
            } else {
                $parentExists = Product::where('sku', $row['parent_sku'] ?? null)->exists()
                    || collect($rows)->contains(fn ($candidate) => ($candidate['data']['type'] ?? null) === 'product'
                        && ($candidate['data']['sku'] ?? null) === ($row['parent_sku'] ?? null));
                if (! $parentExists) {
                    $errors['parent_sku'][] = 'Parent product SKU was not found.';
                }
                if (Product::withTrashed()->where('sku', $sku)->exists()) {
                    $errors['sku'][] = 'SKU is already used by a product.';
                }
                $existing = ProductVariant::where('sku', $sku)->first();
            }

            $action = $errors ? 'error' : ($existing ? 'update' : 'create');
            $results[] = [
                'row' => $csvRow['row'],
                'type' => $type,
                'sku' => $sku,
                'action' => $action,
                'errors' => $errors,
            ];

            if (! $errors) {
                $normalizedRows[] = $row;
            }
        }

        return [
            'summary' => [
                'rows' => count($results),
                'creates' => collect($results)->where('action', 'create')->count(),
                'updates' => collect($results)->where('action', 'update')->count(),
                'errors' => collect($results)->where('action', 'error')->count(),
            ],
            'rows' => $results,
            'normalized' => $normalizedRows,
        ];
    }

    private function normalize(array $row): array
    {
        $normalized = [];
        foreach ($row as $key => $value) {
            $value = is_string($value) ? trim($value) : $value;
            if ($value === '' || $value === null) {
                continue;
            }
            $normalized[$key] = strtoupper((string) $value) === 'NULL' ? null : $value;
        }

        $normalized['type'] = mb_strtolower((string) ($normalized['type'] ?? ''));
        foreach (['in_stock', 'is_featured'] as $field) {
            if (array_key_exists($field, $normalized)) {
                $parsed = filter_var($normalized[$field], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                $normalized[$field] = $parsed;
            }
        }
        foreach (['options', 'attributes'] as $field) {
            if (isset($normalized[$field]) && is_string($normalized[$field])) {
                $decoded = json_decode($normalized[$field], true);
                $normalized[$field] = json_last_error() === JSON_ERROR_NONE ? $decoded : $normalized[$field];
            }
        }

        return $normalized;
    }

    private function productRules(): array
    {
        return [
            'type' => ['required', Rule::in(['product'])],
            'sku' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'price' => ['required', 'numeric', 'min:0'],
            'discount_price' => ['nullable', 'numeric', 'min:0', 'lt:price'],
            'stock_qty' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', Rule::in(['draft', 'published', 'archived'])],
            'category_slug' => ['required_if:status,published', 'nullable', 'string', 'max:255'],
            'in_stock' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:1000'],
            'options' => ['nullable', 'array'],
            'weight_oz' => ['required_if:status,published', 'nullable', 'numeric', 'gt:0', 'max:2400'],
            'length_in' => ['required_if:status,published', 'nullable', 'numeric', 'gt:0', 'max:200'],
            'width_in' => ['required_if:status,published', 'nullable', 'numeric', 'gt:0', 'max:200'],
            'height_in' => ['required_if:status,published', 'nullable', 'numeric', 'gt:0', 'max:200'],
        ];
    }

    private function variantRules(): array
    {
        return [
            'type' => ['required', Rule::in(['variant'])],
            'sku' => ['required', 'string', 'max:255'],
            'parent_sku' => ['required', 'string', 'max:255', 'different:sku'],
            'name' => ['required', 'string', 'max:255'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'stock_qty' => ['nullable', 'integer', 'min:0'],
            'attributes' => ['nullable', 'array'],
            'weight_oz' => ['nullable', 'numeric', 'gt:0', 'max:2400'],
            'length_in' => ['nullable', 'numeric', 'gt:0', 'max:200'],
            'width_in' => ['nullable', 'numeric', 'gt:0', 'max:200'],
            'height_in' => ['nullable', 'numeric', 'gt:0', 'max:200'],
        ];
    }

    private function upsertProduct(array $row): void
    {
        $product = Product::firstOrNew(['sku' => $row['sku']]);
        $data = collect($row)->only(self::PRODUCT_FIELDS)->all();
        $data['status'] = $data['status'] ?? ($product->exists ? $product->status : 'draft');
        $data['slug'] = isset($row['slug'])
            ? $this->products->generateUniqueSlug($row['slug'], $product->id)
            : ($product->exists ? $product->slug : $this->products->generateUniqueSlug($row['name']));
        if (array_key_exists('category_id', $row)) {
            $data['category_id'] = $row['category_id'];
        }
        if (! array_key_exists('in_stock', $data) && array_key_exists('stock_qty', $data)) {
            $data['in_stock'] = (int) $data['stock_qty'] > 0;
        }
        $product->fill($data)->save();
    }

    private function upsertVariant(array $row): void
    {
        $product = Product::where('sku', $row['parent_sku'])->firstOrFail();
        $variant = ProductVariant::firstOrNew(['sku' => $row['sku']]);
        $variant->fill(collect($row)->only(self::VARIANT_FIELDS)->all());
        $variant->product()->associate($product);
        $variant->save();
    }
}
