<?php

namespace App\Actions;

use App\Enums\PublicationStatus;
use App\Events\PublicationUnpublished;
use App\Models\Publication;
use App\Models\User;
use App\Services\AuditRecorder;
use App\Services\PublicationEditTransition;
use App\Services\PublicationSnapshotter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ArchivePublication
{
    public function __construct(
        private readonly PublicationEditTransition $editTransition,
        private readonly PublicationSnapshotter $snapshotter,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    public function handle(Publication $publication, User $owner): Publication
    {
        Gate::forUser($owner)->authorize('update', $publication);

        return DB::transaction(function () use ($publication, $owner): Publication {
            $publication = Publication::query()
                ->ownedBy($owner)
                ->lockForUpdate()
                ->findOrFail($publication->getKey());
            Gate::forUser($owner)->authorize('update', $publication);

            $transition = $this->editTransition->apply($publication, 'publication_archived');
            $previousStatus = $transition['previous_status'];
            $publication->forceFill([
                'status' => PublicationStatus::Archived,
                'archived_at' => now(),
                'scheduled_at' => null,
                'privacy_reviewed_at' => null,
            ])->save();

            $this->snapshotter->snapshot($publication, 'archived');
            $this->auditRecorder->record(
                event: 'publication.archived',
                actor: $owner,
                auditable: $publication,
                metadata: [
                    'previous_status' => $previousStatus->value,
                    'visibility_withdrawn' => $transition['visibility_withdrawn'],
                    'external_copies_may_remain' => $previousStatus === PublicationStatus::Published,
                ],
            );

            if ($previousStatus === PublicationStatus::Published) {
                PublicationUnpublished::dispatch(
                    (int) $publication->getKey(),
                    (int) $owner->getKey(),
                );
            }

            return $publication->refresh();
        });
    }
}
