<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreProductRequest;
use App\Http\Requests\Api\V1\Admin\UpdateProductRequest;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    use \App\Traits\LogsActivity;

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->get('per_page', 20), 1), 100);
        $products = Product::with('category')->latest()->paginate($perPage);

        return $this->success($products, 'Products fetched.');
    }

    public function show(int $id): JsonResponse
    {
        $product = Product::with(['variants', 'images', 'category'])->findOrFail($id);

        return $this->success($product, 'Product fetched.');
    }

    public function store(StoreProductRequest $request, ProductService $service): JsonResponse
    {
        $data = $request->validated();
        $slugSource = $data['slug'] ?? $data['name'];
        $data['slug'] = $service->generateUniqueSlug($slugSource);

        if (!array_key_exists('in_stock', $data) && array_key_exists('stock_qty', $data)) {
            $data['in_stock'] = (int) $data['stock_qty'] > 0;
        }

        $images = $data['images'] ?? [];
        $variants = $data['variants'] ?? [];
        unset($data['images'], $data['variants']);

        $product = DB::transaction(function () use ($data, $images, $variants) {
            $product = Product::create($data);

            if ($images) {
                $rows = [];
                foreach ($images as $index => $url) {
                    $rows[] = [
                        'product_id' => $product->id,
                        'url' => $url,
                        'sort_order' => $index,
                    ];
                }
                ProductImage::insert($rows);
            }

            if ($variants) {
                foreach ($variants as $variant) {
                    $product->variants()->create([
                        'name' => $variant['name'],
                        'sku' => $variant['sku'] ?? null,
                        'price' => $variant['price'] ?? null,
                        'stock_qty' => $variant['stock_qty'] ?? 0,
                        'attributes' => $variant['attributes'] ?? null,
                    ]);
                }
            }

            return $product->load(['variants', 'images', 'category']);
        });

        return $this->success($product, 'Product created.', 201);
    }

    public function update(UpdateProductRequest $request, int $id, ProductService $service): JsonResponse
    {
        $product = Product::findOrFail($id);
        $oldIndex = $product->toArray();

        $data = $request->validated();
        if (array_key_exists('slug', $data)) {
            $data['slug'] = $service->generateUniqueSlug($data['slug'], $product->id);
        }

        if (!array_key_exists('in_stock', $data) && array_key_exists('stock_qty', $data)) {
            $data['in_stock'] = (int) $data['stock_qty'] > 0;
        }

        $images = $data['images'] ?? null;
        $variants = $data['variants'] ?? null;
        unset($data['images'], $data['variants']);

        $product = DB::transaction(function () use ($product, $data, $images, $variants) {
            $product->update($data);

            if (is_array($images)) {
                $product->images()->delete();
                $rows = [];
                foreach ($images as $index => $url) {
                    $rows[] = [
                        'product_id' => $product->id,
                        'url' => $url,
                        'sort_order' => $index,
                    ];
                }
                if ($rows) {
                    ProductImage::insert($rows);
                }
            }

            if (is_array($variants)) {
                $product->variants()->delete();
                foreach ($variants as $variant) {
                    $product->variants()->create([
                        'name' => $variant['name'],
                        'sku' => $variant['sku'] ?? null,
                        'price' => $variant['price'] ?? null,
                        'stock_qty' => $variant['stock_qty'] ?? 0,
                        'attributes' => $variant['attributes'] ?? null,
                    ]);
                }
            }

            return $product->load(['variants', 'images', 'category']);
        });
        
        $this->logActivity('update_product', "Updated product {$product->name}", [
            'before' => $oldIndex,
            'after' => $product->toArray()
        ]);
        
        return $this->success($product, 'Product updated.');
    }

    public function destroy(int $id): JsonResponse
    {
        Product::findOrFail($id)->delete();
        return $this->success(null, 'Product deleted.');
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
