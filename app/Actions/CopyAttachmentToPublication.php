<?php

namespace App\Actions;

use App\Enums\AttachmentMediaType;
use App\Enums\AttachmentScanStatus;
use App\Enums\PublicationStatus;
use App\Events\PublicationUnpublished;
use App\Models\Attachment;
use App\Models\Publication;
use App\Models\PublicationMedia;
use App\Models\User;
use App\Services\AuditRecorder;
use App\Services\PublicationEditTransition;
use App\Services\PublicationSnapshotter;
use App\Services\PublicImageSanitizer;
use App\Services\SanitizedPublicImage;
use App\Services\StoredFileCleanup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class CopyAttachmentToPublication
{
    public function __construct(
        private readonly PublicImageSanitizer $imageSanitizer,
        private readonly PublicationEditTransition $editTransition,
        private readonly PublicationSnapshotter $snapshotter,
        private readonly AuditRecorder $auditRecorder,
        private readonly StoredFileCleanup $storedFileCleanup,
    ) {}

    public function handle(
        Attachment $attachment,
        Publication $publication,
        User $owner,
        ?string $altText = null,
        bool $featured = false,
    ): PublicationMedia {
        Gate::forUser($owner)->authorize('update', $publication);
        Gate::forUser($owner)->authorize('update', $attachment);
        Gate::forUser($owner)->authorize('create', PublicationMedia::class);

        $altText = Validator::make(
            ['alt_text' => $altText],
            ['alt_text' => ['nullable', 'string', 'max:255']],
        )->validate()['alt_text'] ?? null;

        $attachment = Attachment::query()
            ->ownedBy($owner)
            ->whereKey($attachment->getKey())
            ->firstOrFail();
        $publication = Publication::query()
            ->ownedBy($owner)
            ->whereKey($publication->getKey())
            ->firstOrFail();
        $this->assertEligible($attachment);

        $destinationDisk = (string) config('memoria.disks.sanitized_media', 'local');
        $destinationDirectory = "publication-media/{$owner->getKey()}/{$publication->getKey()}";
        $maximumInputBytes = min(
            max(1024, (int) $attachment->size_bytes),
            max(1024, (int) config('memoria.public_images.maximum_kilobytes', 20480) * 1024),
        );
        $maximumOutputBytes = max(
            1024,
            (int) config('memoria.public_images.publication_maximum_kilobytes', 8192) * 1024,
        );
        /** @var array<int, SanitizedPublicImage> $sanitizedImages */
        $sanitizedImages = [];
        $created = false;
        $publicationWasPublic = false;

        try {
            $source = Storage::disk($attachment->disk)->readStream($attachment->path);
            if ($source === false) {
                throw new RuntimeException('The private attachment could not be read.');
            }

            try {
                $sanitizedImage = $this->imageSanitizer->sanitizeAndStore(
                    source: $source,
                    destinationDisk: $destinationDisk,
                    destinationDirectory: $destinationDirectory,
                    maximumWidth: max(1, (int) config('memoria.public_images.publication_maximum_width', 2400)),
                    maximumHeight: max(1, (int) config('memoria.public_images.publication_maximum_height', 2400)),
                    maximumBytes: $maximumInputBytes,
                    maximumOutputBytes: $maximumOutputBytes,
                    maximumPixels: max(1_000_000, (int) config('memoria.public_images.publication_maximum_source_pixels', 24_000_000)),
                );
            } finally {
                fclose($source);
            }

            $sanitizedImages = [$sanitizedImage];
            $responsiveVariants = $this->createResponsiveVariants(
                primaryImage: $sanitizedImage,
                destinationDirectory: $destinationDirectory,
                maximumOutputBytes: $maximumOutputBytes,
                sanitizedImages: $sanitizedImages,
            );

            $medium = DB::transaction(function () use (
                $attachment,
                $publication,
                $owner,
                $altText,
                $featured,
                $sanitizedImage,
                $responsiveVariants,
                &$created,
                &$publicationWasPublic,
            ): PublicationMedia {
                $publication = Publication::query()
                    ->ownedBy($owner)
                    ->lockForUpdate()
                    ->findOrFail($publication->getKey());
                Gate::forUser($owner)->authorize('update', $publication);

                $attachment = Attachment::query()
                    ->ownedBy($owner)
                    ->lockForUpdate()
                    ->findOrFail($attachment->getKey());
                Gate::forUser($owner)->authorize('update', $attachment);
                $this->assertEligible($attachment);

                $medium = PublicationMedia::query()
                    ->whereBelongsTo($publication)
                    ->whereBelongsTo($owner, 'owner')
                    ->where('source_attachment_id', $attachment->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($medium !== null) {
                    $medium->forceFill([
                        'alt_text' => $altText,
                        'is_featured' => $featured,
                    ]);

                    if (! $medium->isDirty(['alt_text', 'is_featured'])) {
                        return $medium;
                    }
                } else {
                    $medium = new PublicationMedia;
                    $medium->forceFill([
                        'publication_id' => $publication->getKey(),
                        'user_id' => $owner->getKey(),
                        'source_attachment_id' => $attachment->getKey(),
                        'disk' => $sanitizedImage->disk,
                        'path' => $sanitizedImage->path,
                        'original_name' => 'image.'.$sanitizedImage->extension,
                        'mime_type' => $sanitizedImage->mimeType,
                        'size_bytes' => $sanitizedImage->sizeBytes,
                        'alt_text' => $altText,
                        'sort_order' => (int) $publication->media()->max('sort_order') + 1,
                        'is_featured' => $featured,
                        'metadata_stripped' => true,
                        'metadata' => [
                            ...$sanitizedImage->metadata(),
                            'variants' => array_map(
                                fn (SanitizedPublicImage $variant): array => $variant->responsiveVariantMetadata(),
                                $responsiveVariants,
                            ),
                        ],
                    ]);
                    $created = true;
                }

                if ($featured) {
                    $publication->media()
                        ->when($medium->exists, fn ($query) => $query->whereKeyNot($medium->getKey()))
                        ->update(['is_featured' => false]);
                }

                $transition = $this->editTransition->apply($publication, 'publication_media_updated');
                $publicationWasPublic = $transition['previous_status'] === PublicationStatus::Published;
                $publication->save();
                $medium->save();

                $this->snapshotter->snapshot($publication, 'public_media_updated');
                $this->auditRecorder->record(
                    event: $created ? 'publication_media.copied' : 'publication_media.updated',
                    actor: $owner,
                    auditable: $publication,
                    metadata: [
                        'publication_media_id' => $medium->getKey(),
                        'source_attachment_id' => $attachment->getKey(),
                        'metadata_stripped' => true,
                        'responsive_variant_count' => count($responsiveVariants),
                        'public_width' => $sanitizedImage->width,
                        'public_height' => $sanitizedImage->height,
                        'visibility_withdrawn' => $transition['visibility_withdrawn'],
                    ],
                );

                return $medium->refresh();
            });
        } catch (Throwable $exception) {
            $this->deleteSanitizedImages($sanitizedImages);

            throw $exception;
        }

        if (! $created) {
            $this->deleteSanitizedImages($sanitizedImages);
        }

        if ($publicationWasPublic) {
            PublicationUnpublished::dispatch(
                (int) $publication->getKey(),
                (int) $owner->getKey(),
            );
        }

        return $medium;
    }

    private function assertEligible(Attachment $attachment): void
    {
        if ($attachment->scan_status !== AttachmentScanStatus::Clean) {
            throw ValidationException::withMessages([
                'attachment' => [__('Only an attachment with a clean security scan can be made public.')],
            ]);
        }

        if ($attachment->media_type !== AttachmentMediaType::Image) {
            throw ValidationException::withMessages([
                'attachment' => [__('Only image attachments can be copied to a publication.')],
            ]);
        }
    }

    /**
     * @param  array<int, SanitizedPublicImage>  $sanitizedImages
     * @return array<string, SanitizedPublicImage>
     */
    private function createResponsiveVariants(
        SanitizedPublicImage $primaryImage,
        string $destinationDirectory,
        int $maximumOutputBytes,
        array &$sanitizedImages,
    ): array {
        $configuredWidths = config('memoria.public_images.publication_variant_widths', []);
        if (! is_array($configuredWidths)) {
            return [];
        }

        $variants = [];

        foreach (PublicationMedia::RESPONSIVE_VARIANT_NAMES as $variantName) {
            $maximumWidth = (int) ($configuredWidths[$variantName] ?? 0);
            if ($maximumWidth < 1 || $maximumWidth >= $primaryImage->width) {
                continue;
            }

            $source = Storage::disk($primaryImage->disk)->readStream($primaryImage->path);
            if ($source === false) {
                throw new RuntimeException('The sanitized publication image could not be read.');
            }

            try {
                $variant = $this->imageSanitizer->sanitizeAndStore(
                    source: $source,
                    destinationDisk: $primaryImage->disk,
                    destinationDirectory: $destinationDirectory,
                    maximumWidth: $maximumWidth,
                    maximumBytes: max(1024, $primaryImage->sizeBytes),
                    maximumOutputBytes: $maximumOutputBytes,
                );
                $variants[$variantName] = $variant;
                $sanitizedImages[] = $variant;
            } finally {
                fclose($source);
            }
        }

        return $variants;
    }

    /** @param array<int, SanitizedPublicImage> $images */
    private function deleteSanitizedImages(array $images): void
    {
        foreach ($images as $image) {
            if ($image->path === '' || str_contains($image->path, '..')) {
                continue;
            }

            $storage = Storage::disk($image->disk);
            if ((! $storage->delete($image->path)) && $storage->exists($image->path)) {
                $this->storedFileCleanup->schedule(
                    $image->disk,
                    $image->path,
                    'abandoned_publication_media_copy',
                );
            }
        }
    }
}
