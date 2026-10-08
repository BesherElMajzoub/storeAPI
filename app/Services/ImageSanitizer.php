<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Re-encodes an uploaded image so the stored original carries no metadata
 * (GPS position, camera serial, ...). Orientation is applied to the pixels
 * first, so photos still display upright without their EXIF tag.
 */
class ImageSanitizer
{
    public function clean(UploadedFile $file): UploadedFile
    {
        $path = $file->getPathname();
        $type = @exif_imagetype($path);

        $image = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default => false,
        };

        if (! $image) {
            // Unreadable or unsupported: leave it to the upload validation.
            return $file;
        }

        if ($type === IMAGETYPE_JPEG) {
            $image = $this->upright($image, (int) (@exif_read_data($path)['Orientation'] ?? 1));
        }

        $temp = tempnam(sys_get_temp_dir(), 'img');
        $written = match ($type) {
            IMAGETYPE_JPEG => imagejpeg($image, $temp, 90),
            IMAGETYPE_PNG => $this->savePng($image, $temp),
            default => imagewebp($image, $temp, 90),
        };
        imagedestroy($image);

        if (! $written) {
            @unlink($temp);
            throw new RuntimeException('The image could not be processed.');
        }

        return new UploadedFile($temp, $file->getClientOriginalName(), $file->getMimeType(), null, true);
    }

    private function savePng(\GdImage $image, string $path): bool
    {
        imagesavealpha($image, true);

        return imagepng($image, $path, 6);
    }

    private function upright(\GdImage $image, int $orientation): \GdImage
    {
        $angle = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        return $angle === 0 ? $image : (imagerotate($image, $angle, 0) ?: $image);
    }
}
