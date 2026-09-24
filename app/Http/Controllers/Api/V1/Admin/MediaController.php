<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ReorderMediaRequest;
use App\Http\Requests\Api\V1\Admin\UploadMediaRequest;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MediaController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────
    //  Products
    // ─────────────────────────────────────────────────────────────────────

    #[OA\Post(
        path: '/api/v1/admin/products/{product}/images',
        summary: 'Append Product Images',
        description: 'Append one or more images to a product gallery without touching existing images. A product may have at most 8 images total. `/media` remains a deprecated alias for this path.',
        security: [['bearerAuth' => []]],
        tags: ['Admin Products']
    )]
    #[OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(
                required: ['images'],
                properties: [
                    new OA\Property(
                        property: 'images[]',
                        description: 'One or more image files (jpg/png/webp). Max 5 MB each.',
                        type: 'array',
                        items: new OA\Items(type: 'string', format: 'binary')
                    ),
                ]
            )
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'Images uploaded',
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(
                    property: 'data',
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'id', type: 'integer'),
                            new OA\Property(property: 'file_name', type: 'string'),
                            new OA\Property(property: 'mime_type', type: 'string'),
                            new OA\Property(property: 'size', type: 'integer'),
                            new OA\Property(property: 'order', type: 'integer'),
                            new OA\Property(property: 'url', type: 'string', format: 'uri'),
                        ]
                    )
                ),
            ]
        )
    )]
    #[OA\Response(response: 422, ref: '#/components/responses/ValidationErrorResponse')]
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

    #[OA\Post(
        path: '/api/v1/admin/products/{product}/images/order',
        summary: 'Reorder Product Gallery',
        description: 'Reorder the product gallery; the first ID becomes the primary/cover image. `/images/reorder` remains a deprecated alias for this path.',
        security: [['bearerAuth' => []]],
        tags: ['Admin Products']
    )]
    #[OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['order'],
            properties: [
                new OA\Property(
                    property: 'order',
                    type: 'array',
                    description: 'Media IDs belonging to this product, in the desired order. `image_ids` is accepted as an alias.',
                    items: new OA\Items(type: 'integer'),
                    example: [3, 1, 2]
                ),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Gallery reordered')]
    #[OA\Response(response: 422, description: 'A media ID does not belong to this product')]
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

    #[OA\Delete(
        path: '/api/v1/admin/products/{product}/images/{media}',
        summary: 'Delete a Single Product Image',
        description: 'Delete one image from a product gallery, leaving the rest untouched.',
        security: [['bearerAuth' => []]],
        tags: ['Admin Products']
    )]
    #[OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'media', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Image deleted')]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFoundResponse')]
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
