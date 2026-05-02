<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ResolvesMediaUrls;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full category resource for GET /api/v1/categories/{slug} (detail / show).
 * Returns thumb + card + banner.
 */
class CategoryDetailResource extends JsonResource
{
    use ResolvesMediaUrls;

    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'name'             => $this->name,
            'slug'             => $this->slug,
            'meta_description' => $this->meta_description,
            'image'            => $this->buildImageBlock(
                $this->getFirstMedia('category_image'),
                ['category_thumb', 'category_card', 'category_banner']
            ),
        ];
    }
}
