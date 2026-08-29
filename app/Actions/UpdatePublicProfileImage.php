<?php

namespace App\Actions;

use App\Models\User;
use App\Models\UserProfile;
use App\Services\AuditRecorder;
use App\Services\PublicImageSanitizer;
use App\Services\SanitizedPublicImage;
use App\Services\StoredFileCleanup;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

class UpdatePublicProfileImage
{
    public function __construct(
        private readonly PublicImageSanitizer $imageSanitizer,
        private readonly AuditRecorder $auditRecorder,
        private readonly StoredFileCleanup $storedFileCleanup,
    ) {}

    public function handle(
        UploadedFile $image,
        UserProfile $profile,
        User $owner,
        string $kind,
    ): UserProfile {
        Gate::forUser($owner)->authorize('update', $profile);

        $validated = Validator::make([
            'image' => $image,
            'kind' => $kind,
        ], [
            'image' => [
                'required',
                'file',
                'max:'.max(1, (int) config('memoria.public_images.maximum_kilobytes', 20480)),
            ],
            'kind' => ['required', Rule::in(['avatar', 'cover'])],
        ])->validate();
        $kind = (string) $validated['kind'];

        $temporaryPath = $image->getRealPath();
        if (! is_string($temporaryPath) || $temporaryPath === '') {
            throw new RuntimeException('The uploaded profile image could not be read.');
        }

        $source = fopen($temporaryPath, 'rb');
        if ($source === false) {
            throw new RuntimeException('The uploaded profile image could not be read.');
        }

        try {
            $sanitizedImage = $this->imageSanitizer->sanitizeAndStore(
                source: $source,
                destinationDisk: (string) config('memoria.disks.sanitized_media', 'local'),
                destinationDirectory: "profile-images/{$owner->getKey()}/{$kind}",
                maximumWidth: (int) config("memoria.public_images.{$kind}_maximum_width"),
                maximumHeight: (int) config("memoria.public_images.{$kind}_maximum_height"),
            );
        } finally {
            fclose($source);
        }

        try {
            return DB::transaction(function () use ($profile, $owner, $kind, $sanitizedImage): UserProfile {
                $profile = UserProfile::query()
                    ->whereBelongsTo($owner)
                    ->lockForUpdate()
                    ->findOrFail($profile->getKey());
                Gate::forUser($owner)->authorize('update', $profile);

                $field = $kind === 'avatar' ? 'avatar_path' : 'cover_image_path';
                $diskField = $kind === 'avatar' ? 'avatar_disk' : 'cover_image_disk';
                $oldPath = $profile->getAttribute($field);
                $oldDisk = $profile->getAttribute($diskField);
                $profile->forceFill([
                    $field => $sanitizedImage->path,
                    $diskField => $sanitizedImage->disk,
                ])->save();

                $this->auditRecorder->record(
                    event: "profile.{$kind}_image.updated",
                    actor: $owner,
                    auditable: $profile,
                    metadata: [
                        'mime_type' => $sanitizedImage->mimeType,
                        'width' => $sanitizedImage->width,
                        'height' => $sanitizedImage->height,
                        'metadata_stripped' => true,
                    ],
                );

                if (is_string($oldPath) && $oldPath !== '') {
                    $this->storedFileCleanup->schedule(
                        is_string($oldDisk) && $oldDisk !== ''
                            ? $oldDisk
                            : (string) config('memoria.disks.sanitized_media', 'local'),
                        $oldPath,
                        "profile_{$kind}_image_replaced",
                    );
                }

                return $profile->refresh();
            });
        } catch (Throwable $exception) {
            $this->deleteSanitizedImage($sanitizedImage);

            throw $exception;
        }
    }

    private function deleteSanitizedImage(SanitizedPublicImage $image): void
    {
        if ($image->path !== '' && ! str_contains($image->path, '..')) {
            $storage = Storage::disk($image->disk);
            if ((! $storage->delete($image->path)) && $storage->exists($image->path)) {
                $this->storedFileCleanup->schedule(
                    $image->disk,
                    $image->path,
                    'abandoned_profile_image_copy',
                );
            }
        }
    }
}
