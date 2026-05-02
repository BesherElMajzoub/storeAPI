<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * Set to true in the controller's show() method to enable detail mode
     * (returns full gallery instead of a single card image).
     */
    public static bool $detail = false;

    public function toArray(Request $request): array
    {
        $data = [
            'id'             => $this->id,
            'name'           => $this->name,
            'slug'           => $this->slug,
            'description'    => $this->description,
            'price'          => (float) $this->price,
            'discount_price' => $this->discount_price !== null ? (float) $this->discount_price : null,
            'final_price'    => (float) $this->final_price,
            'sku'            => $this->sku,
            'stock_qty'      => $this->stock_qty,
            'in_stock'       => $this->in_stock,
            'is_featured'    => $this->is_featured,
            'rating'         => (float) $this->rating,
            'reviews_count'  => (int) $this->reviews_count,
            'category'       => new CategoryResource($this->whenLoaded('category')),
            'variants'       => $this->whenLoaded('variants', function () {
                return $this->variants->map(fn ($variant) => [
                    'id'         => $variant->id,
                    'name'       => $variant->name,
                    'sku'        => $variant->sku,
                    'price'      => (float) $variant->price,
                    'stock_qty'  => $variant->stock_qty,
                    'attributes' => $variant->attributes,
                ]);
            }),
            'attributes'     => $this->options,
        ];

        // Build image block from Spatie media
        $data['image']   = $this->buildImageBlock();
        $data['gallery'] = static::$detail ? $this->buildGallery() : [];

        // Reviews only appear on detail responses
        if (static::$detail) {
            $data['reviews'] = $this->whenLoaded('reviews', function () {
                return ReviewResource::collection($this->reviews);
            });
        }

        return $data;
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Private helpers
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Return a flat image block with all available conversion URLs.
     * Returns null values for conversions that haven't been generated yet.
     */
    private function buildImageBlock(): array
    {
        $media = $this->getFirstMedia('product_images');

        if (! $media) {
            return [
                'thumb'  => null,
                'card'   => null,
                'detail' => null,
                'zoom'   => null,
            ];
        }

        return [
            'thumb'  => $media->hasGeneratedConversion('product_thumb')
                ? $media->getUrl('product_thumb')
                : $media->getUrl(),
            'card'   => $media->hasGeneratedConversion('product_card')
                ? $media->getUrl('product_card')
                : $media->getUrl(),
            'detail' => $media->hasGeneratedConversion('product_detail')
                ? $media->getUrl('product_detail')
                : $media->getUrl(),
            'zoom'   => $media->hasGeneratedConversion('product_zoom')
                ? $media->getUrl('product_zoom')
                : $media->getUrl(),
        ];
    }

    /**
     * Return the full gallery array for the detail (show) view.
     */
    private function buildGallery(): array
    {
        return $this->getMedia('product_images')
            ->map(fn ($media) => [
                'id'     => $media->id,
                'detail' => $media->hasGeneratedConversion('product_detail')
                    ? $media->getUrl('product_detail')
                    : $media->getUrl(),
                'zoom'   => $media->hasGeneratedConversion('product_zoom')
                    ? $media->getUrl('product_zoom')
                    : $media->getUrl(),
                'order'  => $media->order_column,
            ])
            ->values()
            ->all();
    }
}
