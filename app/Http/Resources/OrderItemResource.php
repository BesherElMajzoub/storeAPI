<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'product_name'       => $this->product_name,
            'variant_name'       => $this->variant_name,
            // Variant attribute snapshot — all four key aliases the Bazaar mapper accepts:
            'variant_attributes' => $this->variant_attributes,          // e.g. {"color":"Gold","size":"18in"}
            'attributes'         => $this->variant_attributes,
            'sku'                => $this->sku,
            'quantity'           => $this->quantity,
            'price'              => (float) $this->price,
            'total'              => (float) $this->total,
            'product'            => new ProductResource($this->whenLoaded('product')),
            'variant'            => $this->whenLoaded('variant', function () {
                return [
                    'id'         => $this->variant->id,
                    'name'       => $this->variant->name,
                    'sku'        => $this->variant->sku,
                    // Also exposed under variant.attributes and variant.options
                    'attributes' => $this->variant_attributes,
                    'options'    => $this->variant_attributes,
                ];
            }),
        ];
    }
}
