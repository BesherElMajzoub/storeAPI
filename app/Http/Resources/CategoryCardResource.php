<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ResolvesMediaUrls;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lightweight category resource for GET /api/v1/categories (listing / index).
 * Returns thumb + card images only — NO banner.
 */
class CategoryCardResource extends JsonResource
{
    use ResolvesMediaUrls;

    public function toArray(Request $request): array
    {
        return [
            'id'    => $this->id,
            'name'  => $this->name,
            'slug'  => $this->slug,
            'image' => $this->buildImageBlock(
                $this->getFirstMedia('category_image'),
                ['category_thumb', 'category_card']
            ),
        ];
    }
}
