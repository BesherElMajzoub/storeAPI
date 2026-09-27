<?php

namespace App\Http\Resources\Concerns;

/**
 * Shared helper for building Spatie media conversion URL blocks in API resources.
 */
trait ResolvesMediaUrls
{
    /**
     * Build an image block from a single media item.
     * Returns null if no media exists.
     * Strips the model prefix (product_ / category_) from conversion names for clean keys.
     * A conversion that has not been generated yet falls back to the original file URL.
     */
    protected function buildImageBlock($media, array $conversions): ?array
    {
        if (! $media) {
            return null;
        }

        $block = [];

        foreach ($conversions as $conversion) {
            $key = preg_replace('/^(product_|category_)/', '', $conversion);
            $block[$key] = $this->conversionUrl($media, $conversion);
        }

        return $block;
    }

    protected function conversionUrl($media, string $conversion): string
    {
        return $media->hasGeneratedConversion($conversion)
            ? $media->getUrl($conversion)
            : $media->getUrl();
    }
}
