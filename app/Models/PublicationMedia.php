<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Database\Factories\PublicationMediaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicationMedia extends Model
{
    /** @var array<int, string> */
    public const RESPONSIVE_VARIANT_NAMES = ['thumbnail', 'medium', 'large'];

    /** @use HasFactory<PublicationMediaFactory> */
    use HasFactory;

    use OwnedByUser;

    protected $table = 'publication_media';

    protected $fillable = [
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size_bytes',
        'alt_text',
        'sort_order',
        'is_featured',
        'metadata_stripped',
        'metadata',
    ];

    protected $attributes = [
        'disk' => 'local',
        'sort_order' => 0,
        'is_featured' => false,
        'metadata_stripped' => false,
    ];

    /** @return BelongsTo<Publication, $this> */
    public function publication(): BelongsTo
    {
        return $this->belongsTo(Publication::class);
    }

    /** @return BelongsTo<Attachment, $this> */
    public function sourceAttachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'source_attachment_id')->withTrashed();
    }

    /**
     * @return array{
     *     name: string,
     *     disk: string,
     *     path: string,
     *     mime_type: string,
     *     size_bytes: int,
     *     width: int,
     *     height: int
     * }|null
     */
    public function imageVariant(?string $variantName = null): ?array
    {
        if (! $this->metadata_stripped) {
            return null;
        }

        $variantName = $variantName === null || $variantName === ''
            ? 'original'
            : $variantName;
        if ($variantName === 'original') {
            return $this->validatedImageVariant(
                name: 'original',
                path: (string) $this->path,
                mimeType: (string) $this->mime_type,
                sizeBytes: (int) $this->size_bytes,
                width: (int) data_get($this->metadata, 'width', 0),
                height: (int) data_get($this->metadata, 'height', 0),
            );
        }

        if (! in_array($variantName, self::RESPONSIVE_VARIANT_NAMES, true)) {
            return null;
        }

        $variants = data_get($this->metadata, 'variants');
        if (! is_array($variants)) {
            return null;
        }

        $variant = data_get($variants, $variantName);
        if (! is_array($variant) || data_get($variant, 'metadata_stripped', false) !== true) {
            return null;
        }

        return $this->validatedImageVariant(
            name: $variantName,
            path: (string) ($variant['path'] ?? ''),
            mimeType: (string) ($variant['mime_type'] ?? ''),
            sizeBytes: (int) ($variant['size_bytes'] ?? 0),
            width: (int) ($variant['width'] ?? 0),
            height: (int) ($variant['height'] ?? 0),
            requirePrimaryDirectory: true,
        );
    }

    /**
     * @return array<string, array{
     *     name: string,
     *     disk: string,
     *     path: string,
     *     mime_type: string,
     *     size_bytes: int,
     *     width: int,
     *     height: int
     * }>
     */
    public function responsiveImageVariants(): array
    {
        $variants = [];

        foreach ([...self::RESPONSIVE_VARIANT_NAMES, 'original'] as $variantName) {
            $variant = $this->imageVariant($variantName);
            if ($variant !== null) {
                $variants[$variantName] = $variant;
            }
        }

        uasort(
            $variants,
            fn (array $left, array $right): int => $left['width'] <=> $right['width'],
        );

        return $variants;
    }

    /** @return array<int, array{disk: string, path: string}> */
    public function storedImageFiles(): array
    {
        $paths = [];
        $primaryPath = (string) $this->path;
        if ($this->pathIsSafe($primaryPath)) {
            $paths[$primaryPath] = ['disk' => (string) $this->disk, 'path' => $primaryPath];
        }

        $variants = data_get($this->metadata, 'variants');
        if (! is_array($variants)) {
            return array_values($paths);
        }

        foreach (self::RESPONSIVE_VARIANT_NAMES as $variantName) {
            $variant = data_get($variants, $variantName);
            $variantPath = is_array($variant) ? (string) ($variant['path'] ?? '') : '';
            if ($this->pathIsSafe($variantPath) && dirname($variantPath) === dirname($primaryPath)) {
                $paths[$variantPath] = ['disk' => (string) $this->disk, 'path' => $variantPath];
            }
        }

        return array_values($paths);
    }

    /**
     * @return array{
     *     name: string,
     *     disk: string,
     *     path: string,
     *     mime_type: string,
     *     size_bytes: int,
     *     width: int,
     *     height: int
     * }|null
     */
    private function validatedImageVariant(
        string $name,
        string $path,
        string $mimeType,
        int $sizeBytes,
        int $width,
        int $height,
        bool $requirePrimaryDirectory = false,
    ): ?array {
        if (! $this->pathIsSafe($path)
            || ($requirePrimaryDirectory && dirname($path) !== dirname((string) $this->path))
            || ! in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)
            || $sizeBytes < 1
            || $width < 1
            || $height < 1) {
            return null;
        }

        return [
            'name' => $name,
            'disk' => (string) $this->disk,
            'path' => $path,
            'mime_type' => $mimeType,
            'size_bytes' => $sizeBytes,
            'width' => $width,
            'height' => $height,
        ];
    }

    private function pathIsSafe(string $path): bool
    {
        return $path !== ''
            && ! str_starts_with($path, '/')
            && ! str_contains($path, '..')
            && ! str_contains($path, "\0");
    }

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'sort_order' => 'integer',
            'is_featured' => 'boolean',
            'metadata_stripped' => 'boolean',
            'metadata' => 'array',
        ];
    }
}
