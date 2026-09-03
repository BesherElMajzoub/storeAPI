<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sku' => $this->sku,
            'price' => $this->price !== null ? (float) $this->price : null,
            'stock_qty' => $this->stock_qty,
            'attributes' => $this->attributes,
            'weight_oz' => $this->weight_oz !== null ? (float) $this->weight_oz : null,
            'length_in' => $this->length_in !== null ? (float) $this->length_in : null,
            'width_in' => $this->width_in !== null ? (float) $this->width_in : null,
            'height_in' => $this->height_in !== null ? (float) $this->height_in : null,
        ];
    }
}
