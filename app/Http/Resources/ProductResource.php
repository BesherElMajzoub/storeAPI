<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'price' => (float) $this->price,
            'discount_price' => $this->discount_price !== null ? (float) $this->discount_price : null,
            'final_price' => (float) $this->final_price,
            'sku' => $this->sku,
            'stock_qty' => $this->stock_qty,
            'in_stock' => $this->in_stock,
            'is_featured' => $this->is_featured,
            'rating' => (float) $this->rating,
            'reviews_count' => (int) $this->reviews_count,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'images' => $this->whenLoaded('images', function() {
                return $this->images->pluck('url');
            }),
            'variants' => $this->whenLoaded('variants'),
            'attributes' => $this->options,
        ];
    }
}
