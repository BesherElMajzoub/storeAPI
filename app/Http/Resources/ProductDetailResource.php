<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ResolvesMediaUrls;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full product resource for GET /api/v1/products/{slug} (detail / show).
 *
 * Returns everything: description, variants, attributes, all image conversions, gallery.
 */
class ProductDetailResource extends JsonResource
{
    use ResolvesMediaUrls;

    public function toArray(Request $request): array
    {
        $hasDiscount = $this->discount_price && $this->discount_price > 0 && $this->discount_price < $this->price;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'price' => (float) $this->price,
            'discount_price' => $hasDiscount ? (float) $this->discount_price : null,
            'final_price' => (float) $this->final_price,
            'sku' => $this->sku,
            'stock_qty' => $this->stock_qty,
            'weight_oz' => $this->weight_oz !== null ? (float) $this->weight_oz : null,
            'length_in' => $this->length_in !== null ? (float) $this->length_in : null,
            'width_in' => $this->width_in !== null ? (float) $this->width_in : null,
            'height_in' => $this->height_in !== null ? (float) $this->height_in : null,
            'status' => $this->status,
            'in_stock' => $this->in_stock,
            'is_featured' => $this->is_featured,
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'rating' => (float) $this->rating,
            'reviews_count' => (int) $this->reviews_count,
            'category' => new CategoryCardResource($this->whenLoaded('category')),
            'variants' => ProductVariantResource::collection($this->whenLoaded('variants')),
            'attributes' => $this->options,
            'image' => $this->buildImageBlock(
                $this->getFirstMedia('product_images'),
                ['product_thumb', 'product_card', 'product_detail', 'product_zoom']
            ),
            'gallery' => ProductGalleryImageResource::collection(
                $this->getMedia('product_images')
            ),
            'reviews' => $this->whenLoaded('reviews', function () {
                return ReviewResource::collection($this->reviews);
            }),
        ];
    }
}
