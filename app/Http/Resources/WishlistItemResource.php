<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WishlistItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'slug'        => $this->slug,
            'price'       => (float) $this->price,
            'final_price' => (float) $this->final_price,
            'discount_price' => $this->discount_price !== null ? (float) $this->discount_price : null,
            'rating'      => (float) $this->rating,
            'in_stock'    => (bool) $this->in_stock,
            'image'       => $this->whenLoaded('images', function () {
                return $this->images->first()?->url;
            }),
            'category'    => new CategoryResource($this->whenLoaded('category')),
            'added_at'    => $this->pivot?->created_at?->toISOString(),
        ];
    }
}
