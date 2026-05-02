<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    /**
     * Set to true in the controller's show() method to include the banner
     * conversion in the response.
     */
    public static bool $detail = false;

    public function toArray(Request $request): array
    {
        return [
            'id'       => $this->id,
            'name'     => $this->name,
            'slug'     => $this->slug,
            'image'    => $this->buildImageBlock(),
            'children' => CategoryResource::collection($this->whenLoaded('children')),
        ];
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Private helpers
    // ──────────────────────────────────────────────────────────────────────

    private function buildImageBlock(): array
    {
        $media = $this->getFirstMedia('category_image');

        if (! $media) {
            return [
                'thumb'  => null,
                'card'   => null,
                'banner' => null,
            ];
        }

        return [
            'thumb'  => $media->hasGeneratedConversion('category_thumb')
                ? $media->getUrl('category_thumb')
                : $media->getUrl(),
            'card'   => $media->hasGeneratedConversion('category_card')
                ? $media->getUrl('category_card')
                : $media->getUrl(),
            'banner' => static::$detail
                ? ($media->hasGeneratedConversion('category_banner')
                    ? $media->getUrl('category_banner')
                    : $media->getUrl())
                : null,
        ];
    }
}
