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
            'id'     => $this->id,
            'thumb'  => $this->hasGeneratedConversion('product_thumb')  ? $this->getUrl('product_thumb')  : null,
            'card'   => $this->hasGeneratedConversion('product_card')   ? $this->getUrl('product_card')   : null,
            'detail' => $this->hasGeneratedConversion('product_detail') ? $this->getUrl('product_detail') : null,
            'zoom'   => $this->hasGeneratedConversion('product_zoom')   ? $this->getUrl('product_zoom')   : null,
            'order'  => $this->order_column,
        ];
    }
}
