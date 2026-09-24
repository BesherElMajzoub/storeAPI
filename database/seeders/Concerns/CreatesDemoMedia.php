<?php

namespace Database\Seeders\Concerns;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
            $fileName = sprintf('demo-%d-%d.png', $model->getKey(), $index);
            $media = Media::create([
                'model_type' => $model->getMorphClass(),
                'model_id' => $model->getKey(),
                'uuid' => (string) Str::uuid(),
                'collection_name' => $collection,
                'name' => $label.' '.$index,
                'file_name' => $fileName,
                'mime_type' => 'image/png',
                'disk' => 'public',
                'conversions_disk' => 'public',
                'size' => File::size($path),
                'manipulations' => [],
                'custom_properties' => ['demo' => true],
                'generated_conversions' => [],
                'responsive_images' => [],
                'order_column' => $index,
            ]);

            Storage::disk('public')->put($media->id.'/'.$fileName, File::get($path));
            File::delete($path);
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
