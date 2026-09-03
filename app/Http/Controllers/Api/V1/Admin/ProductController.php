<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\BulkUpdateProductsRequest;
use App\Http\Requests\Api\V1\Admin\ImportProductsRequest;
use App\Http\Requests\Api\V1\Admin\ListProductsRequest;
use App\Http\Requests\Api\V1\Admin\StoreProductRequest;
use App\Http\Requests\Api\V1\Admin\UpdateProductRequest;
use App\Http\Resources\ProductDetailResource;
use App\Models\Product;
use App\Services\ProductImportService;
use App\Services\ProductService;
use App\Traits\LogsActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

class ProductController extends Controller
{
    use LogsActivity;

    #[OA\Get(
        path: '/api/v1/admin/products',
        summary: 'Admin List Products',
        description: 'List all products for admin, paginated',
        security: [['bearerAuth' => []]],
        tags: ['Admin Products']
    )]
    #[OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 20))]
    #[OA\Response(
        response: 200,
        description: 'Products fetched',
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(
                    property: 'data',
                    type: 'object',
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/Product')
                        ),
                    ]
                ),
            ]
        )
    )]
    public function index(ListProductsRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = (int) ($filters['per_page'] ?? 20);
        $sort = $filters['sort'] ?? 'created_desc';

        $products = Product::query()
            ->with(['category.media', 'media', 'variants'])
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->where(function ($subquery) use ($search) {
                    $subquery->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%");
                });
            })
            ->when(isset($filters['category_id']), fn ($query) => $query->where('category_id', $filters['category_id']))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(array_key_exists('is_featured', $filters), fn ($query) => $query->where('is_featured', $filters['is_featured']))
            ->when($sort === 'price_asc', fn ($query) => $query->orderBy('price'))
            ->when($sort === 'price_desc', fn ($query) => $query->orderByDesc('price'))
            ->when($sort === 'stock_asc', fn ($query) => $query->orderBy('stock_qty'))
            ->when($sort === 'name_asc', fn ($query) => $query->orderBy('name'))
            ->when($sort === 'created_desc', fn ($query) => $query->latest())
            ->paginate($perPage);

        return $this->success(
            ProductDetailResource::collection($products)->response()->getData(true),
            'Products fetched.'
        );
    }

    #[OA\Get(
        path: '/api/v1/admin/products/{product}',
        summary: 'Admin Show Product',
        description: "Show a single product's details",
        security: [['bearerAuth' => []]],
        tags: ['Admin Products']
    )]
    #[OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(
        response: 200,
        description: 'Product fetched',
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/Product'),
            ]
        )
    )]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFoundResponse')]
    public function show(int $id): JsonResponse
    {
        $product = Product::with(['variants', 'media', 'category.media'])->findOrFail($id);

        return $this->success(new ProductDetailResource($product), 'Product fetched.');
    }

    #[OA\Post(
        path: '/api/v1/admin/products',
        summary: 'Admin Create Product',
        description: 'Create a new product. Send as **multipart/form-data** to support image file uploads.',
        security: [['bearerAuth' => []]],
        tags: ['Admin Products']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(
                required: ['name', 'price', 'category_id'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'MacBook Pro'),
                    new OA\Property(property: 'slug', type: 'string', nullable: true, example: 'macbook-pro'),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'price', type: 'number', format: 'float', example: 999.99),
                    new OA\Property(property: 'discount_price', type: 'number', format: 'float', nullable: true),
                    new OA\Property(property: 'category_id', type: 'integer', example: 1),
                    new OA\Property(property: 'sku', type: 'string', nullable: true),
                    new OA\Property(property: 'stock_qty', type: 'integer', nullable: true, example: 50),
                    new OA\Property(property: 'weight_oz', type: 'number', nullable: true),
                    new OA\Property(property: 'length_in', type: 'number', nullable: true),
                    new OA\Property(property: 'width_in', type: 'number', nullable: true),
                    new OA\Property(property: 'height_in', type: 'number', nullable: true),
                    new OA\Property(property: 'status', type: 'string', enum: ['draft', 'published', 'archived'], nullable: true),
                    new OA\Property(property: 'in_stock', type: 'boolean', nullable: true),
                    new OA\Property(property: 'is_featured', type: 'boolean', nullable: true),
                    new OA\Property(property: 'meta_title', type: 'string', nullable: true),
                    new OA\Property(property: 'meta_description', type: 'string', nullable: true),
                    new OA\Property(
                        property: 'images[]',
                        description: 'One or more image files (jpg/png/webp). Max 5 MB each.',
                        type: 'array',
                        items: new OA\Items(type: 'string', format: 'binary'),
                        nullable: true
                    ),
                    new OA\Property(
                        property: 'variants',
                        type: 'array',
                        items: new OA\Items(type: 'object'),
                        nullable: true
                    ),
                ]
            )
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'Product created',
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/Product'),
            ]
        )
    )]
    #[OA\Response(response: 422, ref: '#/components/responses/ValidationErrorResponse')]
    public function store(StoreProductRequest $request, ProductService $service): JsonResponse
    {
        $data = $request->validated();
        $slugSource = $data['slug'] ?? $data['name'];
        $data['slug'] = $service->generateUniqueSlug($slugSource);

        if (! array_key_exists('in_stock', $data) && array_key_exists('stock_qty', $data)) {
            $data['in_stock'] = (int) $data['stock_qty'] > 0;
        }

        $uploadedImages = $request->file('images') ?? [];
        $variants = $data['variants'] ?? [];
        unset($data['images'], $data['variants']);

        $product = DB::transaction(function () use ($data, $uploadedImages, $variants) {
            $product = Product::create($data);

            // Add images to Spatie media collection
            foreach ($uploadedImages as $file) {
                $product->addMedia($file)
                    ->usingFileName((string) Str::uuid().'.'.$file->guessExtension())
                    ->toMediaCollection('product_images');
            }

            if ($variants) {
                foreach ($variants as $variant) {
                    $product->variants()->create([
                        'name' => $variant['name'],
                        'sku' => $variant['sku'] ?? null,
                        'price' => $variant['price'] ?? null,
                        'stock_qty' => $variant['stock_qty'] ?? 0,
                        'attributes' => $variant['attributes'] ?? null,
                        'weight_oz' => $variant['weight_oz'] ?? null,
                        'length_in' => $variant['length_in'] ?? null,
                        'width_in' => $variant['width_in'] ?? null,
                        'height_in' => $variant['height_in'] ?? null,
                    ]);
                }
            }

            return $product->load(['variants', 'media', 'category.media']);
        });

        return $this->success(new ProductDetailResource($product), 'Product created.', 201);
    }

    #[OA\Post(
        path: '/api/v1/admin/products/{product}',
        summary: 'Admin Update Product',
        description: "Update an existing product. Send as **multipart/form-data** and include `_method=PATCH` field (required because browsers/HTTP clients don't support file uploads with PATCH).",
        security: [['bearerAuth' => []]],
        tags: ['Admin Products']
    )]
    #[OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(
                properties: [
                    new OA\Property(property: '_method', type: 'string', enum: ['PATCH'], example: 'PATCH', description: 'Method override required for multipart PATCH'),
                    new OA\Property(property: 'name', type: 'string', nullable: true),
                    new OA\Property(property: 'slug', type: 'string', nullable: true),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'price', type: 'number', format: 'float', nullable: true),
                    new OA\Property(property: 'discount_price', type: 'number', format: 'float', nullable: true),
                    new OA\Property(property: 'category_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'sku', type: 'string', nullable: true),
                    new OA\Property(property: 'stock_qty', type: 'integer', nullable: true),
                    new OA\Property(property: 'weight_oz', type: 'number', nullable: true),
                    new OA\Property(property: 'length_in', type: 'number', nullable: true),
                    new OA\Property(property: 'width_in', type: 'number', nullable: true),
                    new OA\Property(property: 'height_in', type: 'number', nullable: true),
                    new OA\Property(property: 'status', type: 'string', enum: ['draft', 'published', 'archived'], nullable: true),
                    new OA\Property(property: 'in_stock', type: 'boolean', nullable: true),
                    new OA\Property(property: 'is_featured', type: 'boolean', nullable: true),
                    new OA\Property(property: 'meta_title', type: 'string', nullable: true),
                    new OA\Property(property: 'meta_description', type: 'string', nullable: true),
                    new OA\Property(
                        property: 'images[]',
                        description: 'New image files to replace existing ones (jpg/png/webp). Max 5 MB each. Omit to keep existing images.',
                        type: 'array',
                        items: new OA\Items(type: 'string', format: 'binary'),
                        nullable: true
                    ),
                    new OA\Property(
                        property: 'variants',
                        type: 'array',
                        items: new OA\Items(type: 'object'),
                        nullable: true
                    ),
                ]
            )
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Product updated',
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/Product'),
            ]
        )
    )]
    #[OA\Response(response: 422, ref: '#/components/responses/ValidationErrorResponse')]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFoundResponse')]
    public function update(UpdateProductRequest $request, int $id, ProductService $service): JsonResponse
    {
        $product = Product::findOrFail($id);
        $oldIndex = $product->toArray();

        $data = $request->validated();
        if (array_key_exists('slug', $data)) {
            $data['slug'] = $service->generateUniqueSlug($data['slug'], $product->id);
        }

        if (! array_key_exists('in_stock', $data) && array_key_exists('stock_qty', $data)) {
            $data['in_stock'] = (int) $data['stock_qty'] > 0;
        }

        $uploadedImages = $request->hasFile('images') ? $request->file('images') : null;
        $variants = $data['variants'] ?? null;
        unset($data['images'], $data['variants']);

        $product = DB::transaction(function () use ($product, $data, $uploadedImages, $variants) {
            $product->update($data);

            if ($uploadedImages !== null) {
                // Clear existing collection and re-upload via Spatie
                $product->clearMediaCollection('product_images');

                foreach ($uploadedImages as $file) {
                    $product->addMedia($file)
                        ->usingFileName((string) Str::uuid().'.'.$file->guessExtension())
                        ->toMediaCollection('product_images');
                }
            }

            if (is_array($variants)) {
                foreach ($variants as $variant) {
                    if (($variant['_delete'] ?? false) === true) {
                        $product->variants()->whereKey($variant['id'])->delete();

                        continue;
                    }

                    $attributes = [
                        'name' => $variant['name'],
                        'sku' => $variant['sku'] ?? null,
                        'price' => $variant['price'] ?? null,
                        'stock_qty' => $variant['stock_qty'] ?? 0,
                        'attributes' => $variant['attributes'] ?? null,
                        'weight_oz' => $variant['weight_oz'] ?? null,
                        'length_in' => $variant['length_in'] ?? null,
                        'width_in' => $variant['width_in'] ?? null,
                        'height_in' => $variant['height_in'] ?? null,
                    ];

                    if (isset($variant['id'])) {
                        $product->variants()->whereKey($variant['id'])->firstOrFail()->update($attributes);
                    } else {
                        $product->variants()->create($attributes);
                    }
                }
            }

            return $product->load(['variants', 'media', 'category.media']);
        });

        $this->logActivity('update_product', "Updated product {$product->name}", [
            'before' => $oldIndex,
            'after' => $product->toArray(),
        ]);

        return $this->success(new ProductDetailResource($product), 'Product updated.');
    }

    #[OA\Delete(
        path: '/api/v1/admin/products/{product}',
        summary: 'Admin Delete Product',
        description: 'Delete a product',
        security: [['bearerAuth' => []]],
        tags: ['Admin Products']
    )]
    #[OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Product deleted')]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFoundResponse')]
    public function destroy(int $id): JsonResponse
    {
        Product::findOrFail($id)->delete();

        return $this->success(null, 'Product deleted.');
    }

    public function bulkUpdate(BulkUpdateProductsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $products = DB::transaction(function () use ($validated) {
            $products = Product::query()
                ->whereKey($validated['ids'])
                ->lockForUpdate()
                ->get();

            foreach ($products as $product) {
                $product->update($validated['set']);
            }

            return Product::query()
                ->whereKey($validated['ids'])
                ->with(['category.media', 'media', 'variants'])
                ->get();
        });

        $results = $products->map(fn (Product $product) => [
            'id' => $product->id,
            'status' => 'updated',
            'product' => new ProductDetailResource($product),
        ]);

        return $this->success($results, 'Products updated atomically.');
    }

    #[OA\Post(
        path: '/api/v1/admin/products/import',
        summary: 'Preview or atomically import products and variants from CSV',
        security: [['bearerAuth' => []]],
        tags: ['Admin Products']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(
                required: ['file', 'dry_run'],
                properties: [
                    new OA\Property(property: 'file', type: 'string', format: 'binary'),
                    new OA\Property(property: 'dry_run', type: 'boolean'),
                ]
            )
        )
    )]
    #[OA\Response(response: 200, description: 'Preview completed or import committed')]
    #[OA\Response(response: 422, description: 'CSV or row validation failed; no writes performed')]
    public function import(ImportProductsRequest $request, ProductImportService $importer): JsonResponse
    {
        try {
            $result = $importer->process(
                $request->file('file'),
                (bool) $request->validated('dry_run')
            );
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
                'errors' => ['file' => [$e->getMessage()]],
            ], 422);
        }

        $hasErrors = $result['summary']['errors'] > 0;

        return response()->json([
            'success' => ! $hasErrors,
            'message' => $hasErrors
                ? 'CSV validation failed; no products were changed.'
                : ($result['committed'] ? 'Products imported successfully.' : 'CSV preview completed.'),
            'data' => $result,
            'errors' => $hasErrors ? ['rows' => $result['rows']] : null,
        ], $hasErrors ? 422 : 200);
    }

    private function success($data, string $message, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'errors' => null,
        ], $status);
    }
}
