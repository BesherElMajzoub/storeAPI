<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;

class AdminWishlistItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $product = $this->whenLoaded('product');

        if (! $product || $product instanceof MissingValue) {
            return [];
        }

        return [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'price' => (float) $product->price,
            'final_price' => (float) $product->final_price,
            'image' => $product->images->first()?->url,
            'added_at' => $this->created_at?->toISOString(),
        ];
    }
}
