<?php

namespace App\Actions;

use App\Models\Attachment;
use App\Models\Entry;
use App\Models\Export;
use App\Models\PublicationMedia;
use App\Models\User;
use App\Services\AuditRecorder;
use App\Services\StoredFileCleanup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ForceDeleteEntry
{
    public function __construct(
        private readonly AuditRecorder $auditRecorder,
        private readonly StoredFileCleanup $storedFileCleanup,
    ) {}

    public function handle(Entry $entry, User $owner): void
    {
        Gate::forUser($owner)->authorize('forceDelete', $entry);

        DB::transaction(function () use ($entry, $owner): void {
            $entry = Entry::withTrashed()
                ->ownedBy($owner)
                ->lockForUpdate()
                ->findOrFail($entry->getKey());
            Gate::forUser($owner)->authorize('forceDelete', $entry);

            if (! $entry->trashed()) {
                throw ValidationException::withMessages([
                    'entry' => [__('Only a memory already in the trash can be permanently deleted.')],
                ]);
            }

            $files = Attachment::withTrashed()
                ->ownedBy($owner)
                ->whereBelongsTo($entry)
                ->lockForUpdate()
                ->get(['id', 'disk', 'path'])
                ->filter(fn (Attachment $attachment): bool => ! $this->pathIsReferencedElsewhere($attachment))
                ->map(fn (Attachment $attachment): array => [
                    'disk' => $attachment->disk,
                    'path' => $attachment->path,
                ])
                ->values()
                ->all();

            $this->auditRecorder->record(
                event: 'entry.permanently_deleted',
                actor: $owner,
                auditable: $entry,
                metadata: [
                    'attachment_file_count' => count($files),
                    'entry_revision' => (int) $entry->revision,
                ],
            );

            foreach ($files as $file) {
                $this->storedFileCleanup->schedule(
                    $file['disk'],
                    $file['path'],
                    'entry_permanently_deleted',
                );
            }

            $entry->forceDelete();
        });
    }

    private function pathIsReferencedElsewhere(Attachment $attachment): bool
    {
        return Attachment::withTrashed()
            ->where('disk', $attachment->disk)
            ->where('path', $attachment->path)
            ->whereKeyNot($attachment->getKey())
            ->exists()
            || PublicationMedia::query()
                ->where('disk', $attachment->disk)
                ->where('path', $attachment->path)
                ->exists()
            || Export::query()
                ->where('disk', $attachment->disk)
                ->where('path', $attachment->path)
                ->exists();
    }
}
