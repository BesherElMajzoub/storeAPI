<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;
use SplFileObject;

class ProductImportService
{
    private const MAX_ROWS = 5000;

    private const CHUNK_SIZE = 1000;

    private const DIMENSIONS = ['weight_oz', 'length_in', 'width_in', 'height_in'];

    private const PRODUCT_FIELDS = [
        'name', 'description', 'price', 'discount_price', 'stock_qty', 'status',
        'in_stock', 'is_featured', 'meta_title', 'meta_description', 'options',
        'weight_oz', 'length_in', 'width_in', 'height_in',
    ];

    private const VARIANT_FIELDS = [
        'name', 'price', 'stock_qty', 'attributes',
        'weight_oz', 'length_in', 'width_in', 'height_in',
    ];

    private const IDENTITY_COLUMNS = ['type', 'sku', 'parent_sku', 'slug', 'category_slug'];

    public function __construct(private readonly ProductService $products) {}

    public function process(UploadedFile $file, bool $dryRun): array
    {
        $analysis = $this->analyze($this->readCsv($file));
        $normalized = $analysis['normalized'];
        unset($analysis['normalized']);

        if ($analysis['summary']['errors'] > 0 || $dryRun) {
            return $analysis + ['committed' => false];
        }

        DB::transaction(fn () => $this->commit($normalized), 3);

        return $analysis + ['committed' => true];
    }

    private function readCsv(UploadedFile $file): array
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
        $entries = array_map(fn ($csvRow) => ['row' => $csvRow['row']] + $this->normalize($csvRow['data']), $rows);
        $maps = $this->preload($entries);
        $seenSkus = [];

        foreach ($entries as $index => $entry) {
            $duplicateOf = $entry['key'] !== null ? ($seenSkus[$entry['key']] ?? null) : null;
            if ($entry['key'] !== null && $duplicateOf === null) {
                $seenSkus[$entry['key']] = $entry['row'];
            }

            $entry = $entry['data']['type'] === 'product'
                ? $this->validateProductRow($entry, $maps)
                : $this->validateVariantRow($entry, $maps);

            if ($duplicateOf !== null) {
                $entry['errors'] = array_merge_recursive(['sku' => ["SKU is duplicated in CSV row {$duplicateOf}."]], $entry['errors']);
            }
            $entries[$index] = $entry;
        }

        return $this->summarize($this->validateVariantStock($entries, $maps));
    }

    private function normalize(array $row): array
    {
        $known = [...self::IDENTITY_COLUMNS, ...self::PRODUCT_FIELDS, ...self::VARIANT_FIELDS];
        $normalized = [];
        foreach ($row as $key => $value) {
            $value = is_string($value) ? trim($value) : $value;
            if ($value === '' || $value === null || ! in_array($key, $known, true)) {
                continue;
            }
            $normalized[$key] = strtoupper((string) $value) === 'NULL' ? null : $value;
        }

        $normalized['type'] = mb_strtolower((string) ($normalized['type'] ?? ''));
        foreach (['in_stock', 'is_featured'] as $field) {
            if (array_key_exists($field, $normalized)) {
                $normalized[$field] = filter_var($normalized[$field], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            }
        }
        foreach (['options', 'attributes'] as $field) {
            if (isset($normalized[$field]) && is_string($normalized[$field])) {
                $decoded = json_decode($normalized[$field], true);
                $normalized[$field] = json_last_error() === JSON_ERROR_NONE ? $decoded : $normalized[$field];
            }
        }

        return [
            'data' => $normalized,
            'key' => $this->skuKey($normalized['sku'] ?? null),
            'parent_key' => $this->skuKey($normalized['parent_sku'] ?? null),
        ];
    }

    private function skuKey(mixed $sku): ?string
    {
        return is_string($sku) && $sku !== '' ? mb_strtolower($sku) : null;
    }

    private function preload(array $entries): array
    {
        $entries = collect($entries);
        $productKeys = $entries->where('data.type', 'product')->pluck('key')->filter()->unique();
        $variantKeys = $entries->where('data.type', 'variant')->pluck('key')->filter()->unique();
        $parentKeys = $entries->where('data.type', 'variant')->pluck('parent_key')->filter()->unique();
        $slugs = $entries->where('data.type', 'product')->pluck('data.category_slug')->filter()
            ->map(fn ($slug) => mb_strtolower((string) $slug))->unique();

        $products = $this->chunked(
            $productKeys->merge($parentKeys)->merge($variantKeys)->unique(),
            fn (array $skus) => Product::withTrashed()
                ->select(['id', 'sku', 'category_id', 'deleted_at', ...self::PRODUCT_FIELDS])
                ->whereIn('sku', $skus)->get()
        )->keyBy(fn (Product $product) => mb_strtolower($product->sku));

        $variants = $this->chunked(
            $variantKeys->merge($productKeys)->unique(),
            fn (array $skus) => ProductVariant::query()
                ->leftJoin('products', 'products.id', '=', 'product_variants.product_id')
                ->whereIn('product_variants.sku', $skus)
                ->get([
                    ...array_map(fn ($column) => "product_variants.{$column}", ['id', 'product_id', 'sku', ...self::VARIANT_FIELDS]),
                    'products.sku as owner_sku',
                ])
        )->keyBy(fn (ProductVariant $variant) => mb_strtolower($variant->sku));

        $parentIds = $productKeys->merge($parentKeys)->map(fn ($key) => $products->get($key)?->id)->filter()->unique();
        $parentVariants = $this->chunked(
            $parentIds,
            fn (array $ids) => ProductVariant::whereIn('product_id', $ids)->get(['id', 'product_id', 'sku', 'stock_qty'])
        )->groupBy('product_id')->map(fn (Collection $group) => $group->mapWithKeys(
            fn (ProductVariant $variant) => [($this->skuKey($variant->sku) ?? "#{$variant->id}") => $variant->stock_qty]
        )->all());

        return [
            'categories' => $this->chunked($slugs, fn (array $chunk) => Category::whereIn('slug', $chunk)->get(['id', 'slug']))
                ->mapWithKeys(fn (Category $category) => [mb_strtolower($category->slug) => $category->id])->all(),
            'products' => $products->all(),
            'variants' => $variants->all(),
            'parentVariants' => $parentVariants->all(),
            'csvProducts' => $productKeys->flip()->all(),
        ];
    }

    private function chunked(Collection $values, callable $query): Collection
    {
        return $values->values()->chunk(self::CHUNK_SIZE)
            ->flatMap(fn (Collection $chunk) => $query($chunk->values()->all()));
    }

    private function validateProductRow(array $entry, array $maps): array
    {
        $data = $entry['data'];
        $errors = $this->validationErrors($data, $this->productRules());
        $existing = $maps['products'][$entry['key']] ?? null;

        if (array_key_exists('category_slug', $data)) {
            $data['category_id'] = $data['category_slug'] === null
                ? null
                : ($maps['categories'][mb_strtolower((string) $data['category_slug'])] ?? null);
            if ($data['category_slug'] !== null && $data['category_id'] === null) {
                $errors['category_slug'][] = 'Category slug was not found.';
                unset($data['category_id']);
            }
        }
        if (isset($maps['variants'][$entry['key']])) {
            $errors['sku'][] = 'SKU is already used by a product variant.';
        }
        if ($existing?->trashed()) {
            $errors['sku'][] = 'SKU belongs to a deleted product and cannot be imported automatically.';
        }

        $state = $this->effectiveProductState($data, $existing);
        $errors = array_merge_recursive($errors, $this->productStateErrors($state, ! isset($errors['category_slug'])));

        return ['data' => $data, 'errors' => $errors, 'existing' => $existing] + $entry;
    }

    private function effectiveProductState(array $data, ?Product $existing): array
    {
        $fields = ['status', 'category_id', 'price', 'discount_price', 'stock_qty', ...self::DIMENSIONS];
        $base = $existing
            ? collect($fields)->mapWithKeys(fn ($field) => [$field => $existing->getAttribute($field)])->all()
            : ['status' => 'draft', 'stock_qty' => 0] + array_fill_keys($fields, null);

        return array_merge($base, array_intersect_key($data, $base));
    }

    private function productStateErrors(array $state, bool $checkCategory): array
    {
        $errors = [];
        if ($state['status'] === 'published') {
            if ($checkCategory && $state['category_id'] === null) {
                $errors['category_slug'][] = 'Published products require a category.';
            }
            foreach (self::DIMENSIONS as $field) {
                if (! is_numeric($state[$field]) || (float) $state[$field] <= 0) {
                    $errors[$field][] = 'Published products require complete shipping weight and dimensions.';
                }
            }
        }

        return $errors;
    }

    private function validateVariantRow(array $entry, array $maps): array
    {
        $errors = $this->validationErrors($entry['data'], $this->variantRules());
        $parent = $maps['products'][$entry['parent_key']] ?? null;
        $parent = $parent?->trashed() ? null : $parent;
        $existing = $maps['variants'][$entry['key']] ?? null;

        if ($entry['parent_key'] !== null && ! $parent && ! isset($maps['csvProducts'][$entry['parent_key']])) {
            $errors['parent_sku'][] = 'Parent product SKU was not found.';
        }
        if (isset($maps['products'][$entry['key']])) {
            $errors['sku'][] = 'SKU is already used by a product.';
        }
        if ($existing && (int) $existing->product_id !== (int) $parent?->id) {
            $errors['sku'][] = "SKU belongs to a variant of another product ({$existing->owner_sku}).";
        }

        return ['errors' => $errors, 'existing' => $existing] + $entry;
    }

    private function validationErrors(array $data, array $rules): array
    {
        return Validator::make($data, $rules)->errors()->toArray();
    }

    private function validateVariantStock(array $entries, array $maps): array
    {
        foreach ($this->groupByParent($entries) as $parentKey => $group) {
            $parent = $maps['products'][$parentKey] ?? null;
            $productRow = $group['product'] !== null ? $entries[$group['product']]['data'] : [];
            $productStock = array_key_exists('stock_qty', $productRow) ? $productRow['stock_qty'] : ($parent?->stock_qty ?? 0);

            $variantStock = $parent ? ($maps['parentVariants'][$parent->id] ?? []) : [];
            foreach ($group['variants'] as $index) {
                $variant = $entries[$index];
                $variantStock[$variant['key'] ?? "row-{$index}"] = array_key_exists('stock_qty', $variant['data'])
                    ? $variant['data']['stock_qty']
                    : ($variant['existing']?->stock_qty ?? 0);
            }

            $values = [...array_values($variantStock), $productStock];
            if ($variantStock === [] || collect($values)->contains(fn ($value) => filter_var($value, FILTER_VALIDATE_INT) === false)) {
                continue;
            }

            $sum = array_sum(array_map('intval', $variantStock));
            if ($sum <= (int) $productStock) {
                continue;
            }

            $message = "The total stock quantity of variants ({$sum}) cannot exceed the product's stock quantity (".(int) $productStock.').';
            foreach (array_filter([...$group['variants'], $group['product']], fn ($index) => $index !== null) as $index) {
                $entries[$index]['errors']['stock_qty'][] = $message;
            }
        }

        return $entries;
    }

    private function groupByParent(array $entries): array
    {
        $groups = [];
        foreach ($entries as $index => $entry) {
            $type = $entry['data']['type'];
            $key = $type === 'product' ? $entry['key'] : ($type === 'variant' ? $entry['parent_key'] : null);
            if ($key === null) {
                continue;
            }
            $groups[$key] ??= ['product' => null, 'variants' => []];
            if ($type === 'product') {
                $groups[$key]['product'] = $index;
            } else {
                $groups[$key]['variants'][] = $index;
            }
        }

        return $groups;
    }

    private function summarize(array $entries): array
    {
        $results = collect($entries)->map(fn (array $entry) => [
            'row' => $entry['row'],
            'type' => $entry['data']['type'],
            'sku' => $entry['data']['sku'] ?? null,
            'action' => $entry['errors'] ? 'error' : ($entry['existing'] ? 'update' : 'create'),
            'errors' => $entry['errors'],
        ]);

        return [
            'summary' => [
                'rows' => $results->count(),
                'creates' => $results->where('action', 'create')->count(),
                'updates' => $results->where('action', 'update')->count(),
                'errors' => $results->where('action', 'error')->count(),
            ],
            'rows' => $results->all(),
            'normalized' => collect($entries)->reject(fn (array $entry) => $entry['errors'])->pluck('data')->all(),
        ];
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
            'category_slug' => ['nullable', 'string', 'max:255'],
            'in_stock' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:1000'],
            'options' => ['nullable', 'array'],
            'weight_oz' => ['nullable', 'numeric', 'gt:0', 'max:2400'],
            'length_in' => ['nullable', 'numeric', 'gt:0', 'max:200'],
            'width_in' => ['nullable', 'numeric', 'gt:0', 'max:200'],
            'height_in' => ['nullable', 'numeric', 'gt:0', 'max:200'],
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

    private function commit(array $rows): void
    {
        foreach (collect($rows)->where('type', 'product') as $row) {
            $this->upsertProduct($row);
        }
        foreach (collect($rows)->where('type', 'variant') as $row) {
            $this->upsertVariant($row);
        }
    }

    private function upsertProduct(array $row): Product
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

        return $product;
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
