<?php

namespace Database\Seeders\Concerns;

use Spatie\MediaLibrary\HasMedia;

/**
 * Attaches real image files to a model without touching the source files
 * (unlike CreatesDemoMedia, which generates and moves placeholder PNGs).
 */
trait AttachesCatalogImages
{
    protected function imagesPath(string $relative = ''): string
    {
        $base = rtrim((string) env('CATALOG_IMAGES_PATH', base_path('catalog-images')), '/\\');

        return $relative === '' ? $base : $base.DIRECTORY_SEPARATOR.$relative;
    }

    /** @return list<string> absolute paths, natural order (slug-1, slug-2, ... slug-10) */
    protected function productImageFiles(string $slug): array
    {
        $files = glob($this->imagesPath('products').DIRECTORY_SEPARATOR.$slug.'-*.{png,jpg,jpeg,webp}', GLOB_BRACE) ?: [];
        natsort($files);

        return array_values($files);
    }

    /**
     * Idempotent: does nothing when the collection already holds exactly these files
     * (matched by file name), otherwise rebuilds the collection in the given order.
     *
     * @param  list<string>  $files
     */
    protected function syncImages(HasMedia $model, string $collection, array $files, string $label): void
    {
        $wanted = array_map('basename', $files);
        $existing = $model->getMedia($collection)
            ->map(fn ($media) => $media->getCustomProperty('source'))
            ->all();

        if ($wanted === $existing) {
            return;
        }

        $model->clearMediaCollection($collection);

        foreach ($files as $position => $file) {
            $model->addMedia($file)
                ->preservingOriginal()
                ->usingName($label.' '.($position + 1))
                ->withCustomProperties(['source' => basename($file)])
                ->toMediaCollection($collection, 'public');
        }
    }
}
