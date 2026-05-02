<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ResolvesMediaUrls;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lightweight product resource for GET /api/v1/products (listing / index).
 *
 * Returns only the data needed for product cards:
 * NO description, variants, attributes, gallery, or detail/zoom images.
 */
class ProductCardResource extends JsonResource
{
    use ResolvesMediaUrls;

    public function toArray(Request $request): array
    {
        $hasDiscount = $this->discount_price && $this->discount_price > 0 && $this->discount_price < $this->price;

        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'slug'           => $this->slug,
            'price'          => (float) $this->price,
            'discount_price' => $hasDiscount ? (float) $this->discount_price : null,
            'final_price'    => (float) $this->final_price,
            'sku'            => $this->sku,
            'stock_qty'      => $this->stock_qty,
            'in_stock'       => $this->in_stock,
            'is_featured'    => $this->is_featured,
            'rating'         => (float) $this->rating,
            'reviews_count'  => (int) $this->reviews_count,
            'category'       => new CategoryBasicResource($this->whenLoaded('category')),
            'image'          => $this->buildImageBlock(
                $this->getFirstMedia('product_images'),
                ['product_thumb', 'product_card']
            ),
        ];
    }
}
