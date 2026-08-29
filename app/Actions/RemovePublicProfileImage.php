<?php

namespace App\Actions;

use App\Models\User;
use App\Models\UserProfile;
use App\Services\AuditRecorder;
use App\Services\StoredFileCleanup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class RemovePublicProfileImage
{
    public function __construct(
        private readonly AuditRecorder $auditRecorder,
        private readonly StoredFileCleanup $storedFileCleanup,
    ) {}

    public function handle(UserProfile $profile, User $owner, string $kind): UserProfile
    {
        Gate::forUser($owner)->authorize('update', $profile);
        $kind = (string) Validator::make(
            ['kind' => $kind],
            ['kind' => ['required', Rule::in(['avatar', 'cover'])]],
        )->validate()['kind'];

        return DB::transaction(function () use ($profile, $owner, $kind): UserProfile {
            $profile = UserProfile::query()
                ->whereBelongsTo($owner)
                ->lockForUpdate()
                ->findOrFail($profile->getKey());
            Gate::forUser($owner)->authorize('update', $profile);

            $field = $kind === 'avatar' ? 'avatar_path' : 'cover_image_path';
            $diskField = $kind === 'avatar' ? 'avatar_disk' : 'cover_image_disk';
            $oldPath = $profile->getAttribute($field);
            $oldDisk = $profile->getAttribute($diskField);
            $profile->forceFill([$field => null, $diskField => null])->save();

            $this->auditRecorder->record(
                event: "profile.{$kind}_image.removed",
                actor: $owner,
                auditable: $profile,
            );

            if (is_string($oldPath) && $oldPath !== '' && ! str_contains($oldPath, '..')) {
                $this->storedFileCleanup->schedule(
                    is_string($oldDisk) && $oldDisk !== ''
                        ? $oldDisk
                        : (string) config('memoria.disks.sanitized_media', 'local'),
                    $oldPath,
                    "profile_{$kind}_image_removed",
                );
            }

            return $profile->refresh();
        });
    }
}
