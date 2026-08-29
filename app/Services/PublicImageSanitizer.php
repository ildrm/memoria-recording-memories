<?php

namespace App\Services;

use GdImage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PublicImageSanitizer
{
    public function __construct(private readonly StoredFileCleanup $storedFileCleanup) {}

    /**
     * @param  resource  $source
     */
    public function sanitizeAndStore(
        mixed $source,
        string $destinationDisk,
        string $destinationDirectory,
        ?int $maximumWidth = null,
        ?int $maximumHeight = null,
        ?int $maximumBytes = null,
        ?int $maximumOutputBytes = null,
        ?int $maximumPixels = null,
    ): SanitizedPublicImage {
        if (! is_resource($source)) {
            throw new RuntimeException('The image source is not readable.');
        }

        if ($destinationDisk === (string) config('memoria.disks.public', 'public')) {
            throw new RuntimeException('Sanitized images require a non-web-exposed storage disk.');
        }

        $maximumBytes ??= max(
            1024,
            (int) config('memoria.public_images.maximum_kilobytes', 20480) * 1024,
        );
        $sourcePath = $this->temporaryPath('memoria-source-');
        $outputPath = $this->temporaryPath('memoria-public-');

        try {
            $this->copySource($source, $sourcePath, $maximumBytes);
            $imageInfo = $this->validatedImageInfo($sourcePath, $maximumPixels);
            $image = $this->decode($sourcePath, $imageInfo['mime']);

            try {
                $image = $this->orient($image, $sourcePath, $imageInfo['mime']);
                $image = $this->resize($image, $maximumWidth, $maximumHeight);
                $width = imagesx($image);
                $height = imagesy($image);
                [$mimeType, $extension] = $this->encode($image, $imageInfo['mime'], $outputPath);
            } finally {
                imagedestroy($image);
            }

            $sizeBytes = filesize($outputPath);
            if (! is_int($sizeBytes) || $sizeBytes < 1) {
                throw new RuntimeException('The sanitized public image is empty.');
            }

            if ($maximumOutputBytes !== null && $sizeBytes > max(1024, $maximumOutputBytes)) {
                throw ValidationException::withMessages([
                    'image' => [__('The selected image could not be optimized to a safe public size.')],
                ]);
            }

            $destinationDirectory = trim($destinationDirectory, '/');
            if ($destinationDirectory === '' || str_contains($destinationDirectory, '..')) {
                throw new RuntimeException('The public image destination is invalid.');
            }

            $destinationPath = $destinationDirectory.'/'.Str::uuid()->toString().'.'.$extension;
            $output = fopen($outputPath, 'rb');
            if ($output === false) {
                throw new RuntimeException('The sanitized image could not be opened.');
            }

            try {
                $stored = Storage::disk($destinationDisk)->writeStream($destinationPath, $output);
            } finally {
                fclose($output);
            }

            if (! $stored) {
                $this->deleteOrSchedule($destinationDisk, $destinationPath, 'incomplete_sanitized_image');

                throw new RuntimeException('The sanitized public image could not be stored.');
            }

            return new SanitizedPublicImage(
                disk: $destinationDisk,
                path: $destinationPath,
                mimeType: $mimeType,
                extension: $extension,
                sizeBytes: $sizeBytes,
                width: $width,
                height: $height,
                encoder: 'gd',
            );
        } finally {
            @unlink($sourcePath);
            @unlink($outputPath);
        }
    }

    private function deleteOrSchedule(string $disk, string $path, string $reason): void
    {
        $storage = Storage::disk($disk);
        if ((! $storage->delete($path)) && $storage->exists($path)) {
            $this->storedFileCleanup->schedule($disk, $path, $reason);
        }
    }

    /** @param resource $source */
    private function copySource(mixed $source, string $destinationPath, int $maximumBytes): void
    {
        $destination = fopen($destinationPath, 'wb');
        if ($destination === false) {
            throw new RuntimeException('A temporary image could not be created.');
        }

        try {
            $copied = stream_copy_to_stream($source, $destination, $maximumBytes + 1);
        } finally {
            fclose($destination);
        }

        if (! is_int($copied) || $copied < 1) {
            throw ValidationException::withMessages([
                'image' => [__('The selected image is empty or unreadable.')],
            ]);
        }

        if ($copied > $maximumBytes) {
            throw ValidationException::withMessages([
                'image' => [__('The selected image is too large.')],
            ]);
        }
    }

    /** @return array{0: int, 1: int, mime: string} */
    private function validatedImageInfo(string $sourcePath, ?int $maximumPixels): array
    {
        $imageInfo = @getimagesize($sourcePath);
        if (! is_array($imageInfo)) {
            throw ValidationException::withMessages([
                'image' => [__('The selected file is not a valid image.')],
            ]);
        }

        $width = (int) $imageInfo[0];
        $height = (int) $imageInfo[1];
        $mimeType = (string) $imageInfo['mime'];
        $maximumPixels ??= (int) config('memoria.public_images.maximum_pixels', 40_000_000);
        $maximumPixels = max(1_000_000, $maximumPixels);

        if ($width < 1 || $height < 1 || ($width * $height) > $maximumPixels) {
            throw ValidationException::withMessages([
                'image' => [__('The selected image dimensions are not supported.')],
            ]);
        }

        if (! in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw ValidationException::withMessages([
                'image' => [__('Only JPEG, PNG, and WebP images can be made public.')],
            ]);
        }

        return [0 => $width, 1 => $height, 'mime' => $mimeType];
    }

    private function decode(string $sourcePath, string $mimeType): GdImage
    {
        $image = match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($sourcePath),
            'image/png' => @imagecreatefrompng($sourcePath),
            'image/webp' => function_exists('imagecreatefromwebp')
                ? @imagecreatefromwebp($sourcePath)
                : false,
            default => false,
        };

        if (! $image instanceof GdImage) {
            throw ValidationException::withMessages([
                'image' => [__('The selected image could not be decoded safely.')],
            ]);
        }

        return $image;
    }

    private function orient(GdImage $image, string $sourcePath, string $mimeType): GdImage
    {
        if ($mimeType !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($sourcePath, 'IFD0', true);
        $orientation = is_array($exif)
            ? (int) ($exif['IFD0']['Orientation'] ?? $exif['Orientation'] ?? 1)
            : 1;

        return match ($orientation) {
            2 => $this->flipped($image, IMG_FLIP_HORIZONTAL),
            3 => $this->rotated($image, 180),
            4 => $this->flipped($image, IMG_FLIP_VERTICAL),
            5 => $this->flipped($this->rotated($image, -90), IMG_FLIP_HORIZONTAL),
            6 => $this->rotated($image, -90),
            7 => $this->flipped($this->rotated($image, 90), IMG_FLIP_HORIZONTAL),
            8 => $this->rotated($image, 90),
            default => $image,
        };
    }

    private function flipped(GdImage $image, int $mode): GdImage
    {
        imageflip($image, $mode);

        return $image;
    }

    private function rotated(GdImage $image, int $degrees): GdImage
    {
        $rotated = imagerotate($image, $degrees, 0);
        if (! $rotated instanceof GdImage) {
            throw new RuntimeException('The image orientation could not be normalized.');
        }

        imagedestroy($image);

        return $rotated;
    }

    private function resize(
        GdImage $image,
        ?int $maximumWidth,
        ?int $maximumHeight,
    ): GdImage {
        $sourceWidth = imagesx($image);
        $sourceHeight = imagesy($image);
        $widthRatio = $maximumWidth === null ? 1.0 : $maximumWidth / $sourceWidth;
        $heightRatio = $maximumHeight === null ? 1.0 : $maximumHeight / $sourceHeight;
        $ratio = min(1.0, $widthRatio, $heightRatio);

        if ($ratio >= 1.0) {
            return $image;
        }

        $targetWidth = max(1, (int) floor($sourceWidth * $ratio));
        $targetHeight = max(1, (int) floor($sourceHeight * $ratio));
        $resized = imagecreatetruecolor($targetWidth, $targetHeight);
        if (! $resized instanceof GdImage) {
            throw new RuntimeException('The public image could not be resized.');
        }

        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefill($resized, 0, 0, $transparent);

        imagecopyresampled(
            $resized,
            $image,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $sourceWidth,
            $sourceHeight,
        );

        imagedestroy($image);

        return $resized;
    }

    /** @return array{0: string, 1: string} */
    private function encode(GdImage $image, string $sourceMimeType, string $outputPath): array
    {
        $encoded = match ($sourceMimeType) {
            'image/jpeg' => imagejpeg($image, $outputPath, 88),
            'image/png' => imagepng($image, $outputPath, 6),
            'image/webp' => function_exists('imagewebp') && imagewebp($image, $outputPath, 88),
            default => false,
        };

        if (! $encoded) {
            throw new RuntimeException('The image could not be re-encoded safely.');
        }

        return match ($sourceMimeType) {
            'image/jpeg' => ['image/jpeg', 'jpg'],
            'image/png' => ['image/png', 'png'],
            'image/webp' => ['image/webp', 'webp'],
            default => throw new RuntimeException('The image format is not supported.'),
        };
    }

    private function temporaryPath(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            throw new RuntimeException('A temporary image path could not be created.');
        }

        return $path;
    }
}
