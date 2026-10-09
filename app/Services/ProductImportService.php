<?php

namespace App\Services;

use App\Exceptions\ProductImportConflictException;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;
use SplFileObject;
use stdClass;

class ProductImportService
{
    private const MAX_ROWS = 5000;

    private const CHUNK_SIZE = 1000;

    private const DIMENSIONS = ['weight_oz', 'length_in', 'width_in', 'height_in'];

    private const DECIMAL_FIELDS = ['price', 'discount_price', ...self::DIMENSIONS];

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

    private const KNOWN_COLUMNS = [...self::IDENTITY_COLUMNS, ...self::PRODUCT_FIELDS, ...self::VARIANT_FIELDS];

    public function __construct(private readonly ProductService $products) {}

    public function process(UploadedFile $file, bool $dryRun): array
    {
        $csv = $this->readCsv($file);
        $analysis = $this->analyze($csv['rows']);
        $normalized = $analysis['normalized'];
        unset($analysis['normalized']);
        $analysis['warnings'] = $csv['warnings'];

        if ($analysis['summary']['errors'] > 0 || $dryRun) {
            return $analysis + ['committed' => false];
        }

        try {
            DB::transaction(fn () => $this->commit($normalized), 3);
        } catch (UniqueConstraintViolationException $e) {
            throw new ProductImportConflictException($e);
        }

        return $analysis + ['committed' => true];
    }

    private function readCsv(UploadedFile $file): array
    {
        $contents = (string) file_get_contents($file->getRealPath());
        if (! mb_check_encoding($contents, 'UTF-8')) {
            throw new RuntimeException('CSV must be UTF-8 encoded. In Excel use "CSV UTF-8 (Comma delimited)".');
        }

        $csv = new SplFileObject($file->getRealPath(), 'r');
        $csv->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
        $csv->setCsvControl($this->detectDelimiter($contents), '"', '\\');
        $header = null;
        $rows = [];

        // SplFileObject::key() is the record index: a blank line counts as one
        // row and a quoted cell with line breaks stays one row, so key() + 1 is
        // the spreadsheet row number.
        foreach ($csv as $index => $values) {
            if ($values === false || collect($values)->every(fn ($value) => trim((string) $value) === '')) {
                continue;
            }

            if ($header === null) {
                $header = $this->readHeader($values);

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

        return ['rows' => $rows, 'warnings' => $this->unknownColumnWarnings($header)];
    }

    private function readHeader(array $values): array
    {
        $values[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $values[0]);
        $header = array_map(fn ($value) => mb_strtolower(trim((string) $value)), $values);
        if (count($header) !== count(array_unique($header)) || in_array('', $header, true)) {
            throw new RuntimeException('CSV headers must be non-empty and unique.');
        }

        return $header;
    }

    private function detectDelimiter(string $contents): string
    {
        $line = strtok(preg_replace('/^\xEF\xBB\xBF/', '', $contents), "\r\n");
        $counts = [',' => 0, ';' => 0, "\t" => 0];
        $quoted = false;
        foreach (str_split((string) $line) as $char) {
            if ($char === '"') {
                $quoted = ! $quoted;
            } elseif (! $quoted && isset($counts[$char])) {
                $counts[$char]++;
            }
        }
        arsort($counts);

        return array_key_first($counts);
    }

    private function unknownColumnWarnings(array $header): array
    {
        return collect($header)->reject(fn ($column) => in_array($column, self::KNOWN_COLUMNS, true))
            ->map(fn ($column) => "Unknown column \"{$column}\" is ignored.")
            ->values()->all();
    }

    private function analyze(array $rows): array
    {
        $entries = array_map(fn ($csvRow) => ['row' => $csvRow['row']] + $this->normalize($csvRow['data']), $rows);
        $maps = $this->preload($entries);
        $seenSkus = [];

        foreach ($entries as $index => $entry) {
            if (! in_array($entry['data']['type'], ['product', 'variant'], true)) {
                $entries[$index] = ['errors' => ['type' => ['Type must be product or variant.']], 'existing' => null] + $entry;

                continue;
            }

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
        $normalized = [];
        foreach ($row as $key => $value) {
            $value = is_string($value) ? trim($value) : $value;
            if ($value === '' || $value === null || ! in_array($key, self::KNOWN_COLUMNS, true)) {
                continue;
            }
            $normalized[$key] = strtoupper((string) $value) === 'NULL' ? null : $value;
        }

        $normalized['type'] = mb_strtolower((string) ($normalized['type'] ?? ''));
        foreach (['in_stock', 'is_featured'] as $field) {
            if (isset($normalized[$field])) {
                $normalized[$field] = filter_var($normalized[$field], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            }
        }
        $invalidJson = [];
        foreach (['options', 'attributes'] as $field) {
            if (isset($normalized[$field]) && is_string($normalized[$field])) {
                $decoded = json_decode($normalized[$field], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $normalized[$field] = $decoded;
                } else {
                    $invalidJson[] = $field;
                }
            }
        }

        return [
            'data' => $normalized,
            'invalid_json' => $invalidJson,
            'key' => $this->skuKey($normalized['sku'] ?? null),
            'parent_key' => $this->skuKey($normalized['parent_sku'] ?? null),
        ];
    }

    private function skuKey(mixed $sku): ?string
    {
        return is_string($sku) && $sku !== '' ? mb_strtolower($sku) : null;
    }

    private function find(array $map, ?string $key): mixed
    {
        return $key === null ? null : ($map[$key] ?? null);
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
        $errors = $this->validationErrors($entry, $this->productRules());
        $existing = $this->find($maps['products'], $entry['key']);

        if (array_key_exists('category_slug', $data)) {
            $data['category_id'] = $data['category_slug'] === null
                ? null
                : ($maps['categories'][mb_strtolower((string) $data['category_slug'])] ?? null);
            if ($data['category_slug'] !== null && $data['category_id'] === null) {
                $errors['category_slug'][] = 'Category slug was not found.';
                unset($data['category_id']);
            }
        }
        if ($this->find($maps['variants'], $entry['key'])) {
            $errors['sku'][] = 'SKU is already used by a product variant.';
        }
        if ($existing?->trashed()) {
            $errors['sku'][] = 'SKU belongs to a deleted product and cannot be imported automatically.';
        }

        $state = $this->effectiveProductState($data, $existing);
        $errors = array_merge_recursive($errors, $this->productStateErrors($state, [
            'creating' => ! $existing,
            'price_given' => array_key_exists('price', $data),
            'category_checked' => ! isset($errors['category_slug']),
        ]));

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

    private function productStateErrors(array $state, array $context): array
    {
        $errors = [];
        if ($context['creating'] && ! $context['price_given']) {
            $errors['price'][] = 'The price field is required.';
        }
        if (is_numeric($state['discount_price']) && is_numeric($state['price'])
            && (float) $state['discount_price'] >= (float) $state['price']) {
            $errors['discount_price'][] = 'The discount price must be less than price.';
        }
        if ($state['status'] === 'published') {
            if ($context['category_checked'] && $state['category_id'] === null) {
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
        $errors = $this->validationErrors($entry, $this->variantRules());
        $parent = $this->find($maps['products'], $entry['parent_key']);
        $parent = $parent?->trashed() ? null : $parent;
        $existing = $this->find($maps['variants'], $entry['key']);

        if ($entry['parent_key'] !== null && ! $parent && $this->find($maps['csvProducts'], $entry['parent_key']) === null) {
            $errors['parent_sku'][] = 'Parent product SKU was not found.';
        }
        if ($this->find($maps['products'], $entry['key'])) {
            $errors['sku'][] = 'SKU is already used by a product.';
        }
        if ($existing && (int) $existing->product_id !== (int) $parent?->id) {
            $errors['sku'][] = "SKU belongs to a variant of another product ({$existing->owner_sku}).";
        }

        return ['errors' => $errors, 'existing' => $existing] + $entry;
    }

    private function validationErrors(array $entry, array $rules): array
    {
        $errors = collect($entry['invalid_json'])->mapWithKeys(fn ($field) => [$field => ["{$field} must be valid JSON."]])->all();
        $data = collect($entry['data'])->except($entry['invalid_json'])->all();

        return array_merge_recursive($errors, Validator::make($data, $rules)->errors()->toArray());
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
            'changes' => $entry['errors'] || ! $entry['existing'] ? null : $this->diff(
                $entry['data']['type'] === 'product'
                    ? $this->productData($entry['data'])
                    : collect($entry['data'])->only(self::VARIANT_FIELDS)->all(),
                $entry['existing']
            ),
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

    private function diff(array $values, Model $existing): array|stdClass
    {
        $changes = [];
        foreach ($values as $field => $new) {
            $old = $existing->getAttribute($field);
            if (! $this->sameValue($field, $old, $new)) {
                $changes[$field] = [$this->displayValue($field, $old), $this->displayValue($field, $new)];
            }
        }

        return $changes === [] ? new stdClass : $changes;
    }

    private function sameValue(string $field, mixed $old, mixed $new): bool
    {
        return match (true) {
            $old === null || $new === null => $old === $new,
            is_array($old) || is_array($new) => $old == $new,
            is_bool($old) || is_bool($new) => (bool) $old === (bool) $new,
            default => (string) $this->displayValue($field, $old) === (string) $this->displayValue($field, $new),
        };
    }

    private function displayValue(string $field, mixed $value): mixed
    {
        return match (true) {
            ! is_numeric($value) => $value,
            in_array($field, self::DECIMAL_FIELDS, true) => number_format((float) $value, 2, '.', ''),
            in_array($field, ['stock_qty', 'category_id'], true) => (int) $value,
            default => $value,
        };
    }

    private function productRules(): array
    {
        return [
            'type' => ['required', Rule::in(['product'])],
            'sku' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'discount_price' => ['nullable', 'numeric', 'min:0'],
            'stock_qty' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', Rule::in(['draft', 'published', 'archived'])],
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
            'stock_qty' => ['sometimes', 'integer', 'min:0'],
            'attributes' => ['nullable', 'array'],
            'weight_oz' => ['nullable', 'numeric', 'gt:0', 'max:2400'],
            'length_in' => ['nullable', 'numeric', 'gt:0', 'max:200'],
            'width_in' => ['nullable', 'numeric', 'gt:0', 'max:200'],
            'height_in' => ['nullable', 'numeric', 'gt:0', 'max:200'],
        ];
    }

    private function commit(array $rows): void
    {
        $rows = collect($rows);
        $productIds = $this->chunked(
            $rows->where('type', 'variant')->pluck('parent_sku')->unique(),
            fn (array $skus) => Product::whereIn('sku', $skus)->lockForUpdate()->get(['id', 'sku'])
        )->mapWithKeys(fn (Product $product) => [mb_strtolower($product->sku) => $product->id])->all();

        foreach ($rows->where('type', 'product') as $row) {
            $productIds[mb_strtolower($row['sku'])] = $this->upsertProduct($row)->id;
        }
        foreach ($rows->where('type', 'variant') as $row) {
            $this->upsertVariant($row, $productIds[mb_strtolower($row['parent_sku'])] ?? throw new ProductImportConflictException);
        }
    }

    private function upsertProduct(array $row): Product
    {
        $product = Product::firstOrNew(['sku' => $row['sku']]);
        $data = $this->productData($row);
        $data['status'] = $data['status'] ?? ($product->exists ? $product->status : 'draft');
        $data['slug'] = isset($row['slug'])
            ? $this->products->generateUniqueSlug($row['slug'], $product->id)
            : ($product->exists ? $product->slug : $this->products->generateUniqueSlug($row['name']));
        $product->fill($data)->save();

        return $product;
    }

    private function productData(array $row): array
    {
        $data = collect($row)->only(self::PRODUCT_FIELDS)->all();
        if (array_key_exists('category_id', $row)) {
            $data['category_id'] = $row['category_id'];
        }
        if (! array_key_exists('in_stock', $data) && array_key_exists('stock_qty', $data)) {
            $data['in_stock'] = (int) $data['stock_qty'] > 0;
        }

        return $data;
    }

    private function upsertVariant(array $row, int $productId): void
    {
        $variant = ProductVariant::firstOrNew(['sku' => $row['sku']]);
        if ($variant->exists && (int) $variant->product_id !== $productId) {
            throw new ProductImportConflictException;
        }
        $variant->fill(collect($row)->only(self::VARIANT_FIELDS)->all());
        $variant->product_id = $productId;
        $variant->save();
    }
}
