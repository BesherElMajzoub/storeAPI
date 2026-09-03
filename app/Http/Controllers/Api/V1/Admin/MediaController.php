<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ReorderMediaRequest;
use App\Http\Requests\Api\V1\Admin\UploadMediaRequest;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MediaController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────
    //  Products
    // ─────────────────────────────────────────────────────────────────────

    /**
     * POST /admin/products/{product}/media
     * Upload one or more additional images to a product's gallery.
     */
    public function uploadProductImages(UploadMediaRequest $request, Product $product): JsonResponse
    {
        $files = $request->file('images', []);
        if ($product->getMedia('product_images')->count() + count($files) > 8) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'data' => null,
                'errors' => ['images' => ['A product may have at most 8 images.']],
            ], 422);
        }

        $uploaded = [];
        foreach ($files as $file) {
            $media = $product->addMedia($file)
                ->usingFileName((string) Str::uuid().'.'.$file->guessExtension())
                ->toMediaCollection('product_images');

            $uploaded[] = $this->formatMedia($media);
        }

        return $this->success($uploaded, 'Images uploaded successfully.', 201);
    }

    /**
     * POST /admin/products/{product}/media/reorder
     * Reorder the product gallery. Body: { "order": [3, 1, 2] } (media IDs in desired order).
     */
    public function reorderProductGallery(ReorderMediaRequest $request, Product $product): JsonResponse
    {
        $order = $request->validated()['order'];

        // Validate all IDs belong to this product
        $mediaIds = $product->getMedia('product_images')->pluck('id')->all();
        foreach ($order as $id) {
            abort_unless(in_array($id, $mediaIds), 422, 'Invalid media ID in order array.');
        }

        Media::setNewOrder($order);

        return $this->success(null, 'Gallery reordered.');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Categories
    // ─────────────────────────────────────────────────────────────────────

    /**
     * POST /admin/categories/{category}/media
     * Replace (or set) the category image.
     */
    public function replaceCategoryImage(UploadMediaRequest $request, Category $category): JsonResponse
    {
        // singleFile() collection — Spatie auto-clears the old one
        $media = $category->addMediaFromRequest('image')
            ->usingFileName((string) Str::uuid().'.'.$request->file('image')->guessExtension())
            ->toMediaCollection('category_image');

        return $this->success($this->formatMedia($media), 'Category image replaced.', 201);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Shared
    // ─────────────────────────────────────────────────────────────────────

    /**
     * DELETE /admin/media/{media}
     * Delete a single media item (product image or category image).
     */
    public function destroy(Media $media): JsonResponse
    {
        $media->delete();

        return $this->success(null, 'Media deleted.');
    }

    public function destroyProductImage(Product $product, Media $media): JsonResponse
    {
        abort_unless(
            $media->model_type === Product::class && (int) $media->model_id === $product->id,
            404
        );

        $media->delete();

        return $this->success(null, 'Media deleted.');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Private helpers
    // ─────────────────────────────────────────────────────────────────────

    private function formatMedia(Media $media): array
    {
        return [
            'id' => $media->id,
            'file_name' => $media->file_name,
            'mime_type' => $media->mime_type,
            'size' => $media->size,
            'order' => $media->order_column,
            'url' => $media->getUrl(),
        ];
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
