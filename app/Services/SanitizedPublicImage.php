<?php

namespace App\Services;

final readonly class SanitizedPublicImage
{
    public function __construct(
        public string $disk,
        public string $path,
        public string $mimeType,
        public string $extension,
        public int $sizeBytes,
        public int $width,
        public int $height,
        public string $encoder,
    ) {}

    /** @return array{width: int, height: int, encoder: string, metadata_stripped: true} */
    public function metadata(): array
    {
        return [
            'width' => $this->width,
            'height' => $this->height,
            'encoder' => $this->encoder,
            'metadata_stripped' => true,
        ];
    }

    /**
     * @return array{
     *     path: string,
     *     mime_type: string,
     *     size_bytes: int,
     *     width: int,
     *     height: int,
     *     encoder: string,
     *     metadata_stripped: true
     * }
     */
    public function responsiveVariantMetadata(): array
    {
        return [
            'path' => $this->path,
            'mime_type' => $this->mimeType,
            'size_bytes' => $this->sizeBytes,
            'width' => $this->width,
            'height' => $this->height,
            'encoder' => $this->encoder,
            'metadata_stripped' => true,
        ];
    }
}
