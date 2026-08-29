<?php

namespace App\Actions;

use App\Enums\PublicationStatus;
use App\Events\PublicationUnpublished;
use App\Models\Publication;
use App\Models\PublicationMedia;
use App\Models\User;
use App\Services\AuditRecorder;
use App\Services\PublicationEditTransition;
use App\Services\PublicationSnapshotter;
use App\Services\StoredFileCleanup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RemovePublicationMedia
{
    public function __construct(
        private readonly PublicationEditTransition $editTransition,
        private readonly PublicationSnapshotter $snapshotter,
        private readonly AuditRecorder $auditRecorder,
        private readonly StoredFileCleanup $storedFileCleanup,
    ) {}

    public function handle(PublicationMedia $medium, User $owner): void
    {
        Gate::forUser($owner)->authorize('delete', $medium);

        $publicationWasPublic = false;

        DB::transaction(function () use ($medium, $owner, &$publicationWasPublic): void {
            $publication = Publication::query()
                ->ownedBy($owner)
                ->lockForUpdate()
                ->findOrFail($medium->publication_id);
            $medium = PublicationMedia::query()
                ->whereBelongsTo($publication)
                ->whereBelongsTo($owner, 'owner')
                ->lockForUpdate()
                ->findOrFail($medium->getKey());
            Gate::forUser($owner)->authorize('delete', $medium);

            $files = $medium->storedImageFiles();
            $mediumId = (int) $medium->getKey();
            $transition = $this->editTransition->apply($publication, 'publication_media_removed');
            $publicationWasPublic = $transition['previous_status'] === PublicationStatus::Published;
            $publication->save();
            $medium->delete();

            $this->snapshotter->snapshot($publication, 'public_media_removed');
            $this->auditRecorder->record(
                event: 'publication_media.removed',
                actor: $owner,
                auditable: $publication,
                metadata: [
                    'publication_media_id' => $mediumId,
                    'stored_file_count' => count($files),
                    'visibility_withdrawn' => $transition['visibility_withdrawn'],
                ],
            );

            foreach ($files as $file) {
                $this->storedFileCleanup->schedule(
                    $file['disk'],
                    $file['path'],
                    'publication_media_removed',
                );
            }
        });

        if ($publicationWasPublic) {
            PublicationUnpublished::dispatch(
                (int) $medium->publication_id,
                (int) $owner->getKey(),
            );
        }
    }
}
