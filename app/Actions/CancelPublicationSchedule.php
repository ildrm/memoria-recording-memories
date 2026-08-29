<?php

namespace App\Actions;

use App\Enums\PublicationStatus;
use App\Enums\PublicationTargetStatus;
use App\Models\Publication;
use App\Models\User;
use App\Services\AuditRecorder;
use App\Services\PublicationSnapshotter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CancelPublicationSchedule
{
    public function __construct(
        private readonly PublicationSnapshotter $snapshotter,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    public function handle(Publication $publication, User $owner): Publication
    {
        Gate::forUser($owner)->authorize('publish', $publication);

        return DB::transaction(function () use ($publication, $owner): Publication {
            $publication = Publication::query()
                ->ownedBy($owner)
                ->lockForUpdate()
                ->findOrFail($publication->getKey());

            if ($publication->status !== PublicationStatus::Scheduled) {
                throw ValidationException::withMessages([
                    'status' => [__('Only a scheduled publication can have its schedule cancelled.')],
                ]);
            }

            $publication->targets()
                ->where('status', PublicationTargetStatus::Scheduled)
                ->update([
                    'status' => PublicationTargetStatus::Cancelled->value,
                    'scheduled_at' => null,
                ]);

            $publication->forceFill([
                'status' => PublicationStatus::Draft,
                'scheduled_at' => null,
            ])->save();

            $this->snapshotter->snapshot($publication, 'schedule_cancelled');
            $this->auditRecorder->record(
                event: 'publication.schedule_cancelled',
                actor: $owner,
                auditable: $publication,
            );

            return $publication->refresh();
        });
    }
}
