<?php

namespace Database\Seeders\Concerns;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

trait CreatesDemoMedia
{
    protected function attachDemoImages(HasMedia $model, string $collection, int $count, string $label): void
    {
        $existing = $model->getMedia($collection)->count();
        if ($existing >= $count) {
            return;
        }

        for ($index = $existing + 1; $index <= $count; $index++) {
            $path = $this->makeDemoPng($label, $index);

            // Go through Spatie (not Media::create) so the registered conversions are generated.
            // addMedia() moves the temp file into the media directory.
            $media = $model->addMedia($path)
                ->usingName($label.' '.$index)
                ->usingFileName(sprintf('demo-%d-%d.png', $model->getKey(), $index))
                ->withCustomProperties(['demo' => true])
                ->toMediaCollection($collection, 'public');

            $this->removeStaleMediaFiles($media);
        }
    }

    /**
     * A re-seed after migrate:fresh reuses media IDs, but the old files stay on disk in the
     * same {id}/ directory. Delete anything there that does not belong to this media item.
     */
    private function removeStaleMediaFiles(Media $media): void
    {
        $keep = [$media->getPathRelativeToRoot()];
        foreach (array_keys($media->generated_conversions ?? []) as $conversion) {
            $keep[] = $media->getPathRelativeToRoot($conversion);
        }

        $disk = Storage::disk('public');
        foreach ($disk->allFiles((string) $media->id) as $file) {
            if (! in_array($file, $keep, true)) {
                $disk->delete($file);
            }
        }
    }

    private function makeDemoPng(string $label, int $index): string
    {
        $directory = storage_path('app/demo-seed-temp');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/'.uniqid('demo-', true).'.png';

        if (! function_exists('imagecreatetruecolor')) {
            File::put(
                $path,
                base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=')
            );

            return $path;
        }

        [$width, $height] = match ($index % 3) {
            0 => [960, 640],
            1 => [800, 800],
            default => [720, 960],
        };
        $image = imagecreatetruecolor($width, $height);
        $seed = abs(crc32($label.'-'.$index));
        $background = imagecolorallocate(
            $image,
            55 + ($seed % 140),
            45 + (($seed >> 8) % 150),
            65 + (($seed >> 16) % 130)
        );
        $foreground = imagecolorallocate($image, 255, 255, 255);
        $accent = imagecolorallocate($image, 255, 221, 153);
        imagefilledrectangle($image, 0, 0, $width, $height, $background);
        imagefilledrectangle($image, 0, $height - 90, $width, $height, $accent);
        imagestring($image, 5, 30, 35, substr($label, 0, 55), $foreground);
        imagestring($image, 4, 30, 65, 'Frontend demo image '.$index, $foreground);
        imagestring($image, 3, 30, $height - 55, $width.' x '.$height, $background);
        imagepng($image, $path, 7);
        imagedestroy($image);

        return $path;
    }
}
