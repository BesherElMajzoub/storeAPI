<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ReorderMediaRequest;
use App\Http\Requests\Api\V1\Admin\UploadMediaRequest;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
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
    public function uploadProductImages(UploadMediaRequest $request, int $productId): JsonResponse
    {
        $product = Product::findOrFail($productId);

        $uploaded = [];
        foreach ($request->file('images') as $file) {
            $media = $product->addMedia($file)
                ->toMediaCollection('product_images');

            $uploaded[] = $this->formatMedia($media);
        }

        return $this->success($uploaded, 'Images uploaded successfully.', 201);
    }

    /**
     * POST /admin/products/{product}/media/reorder
     * Reorder the product gallery. Body: { "order": [3, 1, 2] } (media IDs in desired order).
     */
    public function reorderProductGallery(ReorderMediaRequest $request, int $productId): JsonResponse
    {
        $product = Product::findOrFail($productId);
        $order   = $request->validated()['order'];

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
    public function replaceCategoryImage(UploadMediaRequest $request, int $categoryId): JsonResponse
    {
        $category = Category::findOrFail($categoryId);

        // singleFile() collection — Spatie auto-clears the old one
        $media = $category->addMediaFromRequest('image')
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
    public function destroy(int $mediaId): JsonResponse
    {
        $media = Media::findOrFail($mediaId);
        $media->delete();

        return $this->success(null, 'Media deleted.');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Private helpers
    // ─────────────────────────────────────────────────────────────────────

    private function formatMedia(Media $media): array
    {
        return [
            'id'        => $media->id,
            'file_name' => $media->file_name,
            'mime_type' => $media->mime_type,
            'size'      => $media->size,
            'order'     => $media->order_column,
            'url'       => $media->getUrl(),
        ];
    }

    private function success($data, string $message, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
            'errors'  => null,
        ], $status);
    }
}
