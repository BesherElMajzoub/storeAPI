<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ResolvesMediaUrls;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single gallery item for the product detail view.
 * Each gallery item shows all 4 conversions.
 */
class ProductGalleryImageResource extends JsonResource
{
    use ResolvesMediaUrls;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'thumb' => $this->conversionUrl($this->resource, 'product_thumb'),
            'card' => $this->conversionUrl($this->resource, 'product_card'),
            'detail' => $this->conversionUrl($this->resource, 'product_detail'),
            'zoom' => $this->conversionUrl($this->resource, 'product_zoom'),
            'order' => $this->order_column,
        ];
    }
}
