<?php

namespace App\Actions;

use App\Enums\PublicationStatus;
use App\Models\Publication;
use App\Models\User;
use App\Services\AuditRecorder;
use App\Services\PublicationSnapshotter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RestoreArchivedPublication
{
    public function __construct(
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

            if ($publication->status !== PublicationStatus::Archived) {
                throw ValidationException::withMessages([
                    'status' => [__('Only an archived publication can be restored.')],
                ]);
            }

            $publication->forceFill([
                'status' => PublicationStatus::Draft,
                'archived_at' => null,
                'scheduled_at' => null,
                'privacy_reviewed_at' => null,
            ])->save();

            $this->snapshotter->snapshot($publication, 'restored_from_archive');
            $this->auditRecorder->record(
                event: 'publication.restored',
                actor: $owner,
                auditable: $publication,
                metadata: ['privacy_review_required' => true],
            );

            return $publication->refresh();
        });
    }
}
