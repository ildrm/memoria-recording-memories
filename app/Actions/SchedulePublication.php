<?php

namespace App\Actions;

use App\Enums\PublicationStatus;
use App\Enums\PublicationTargetStatus;
use App\Events\PublicationScheduled;
use App\Models\Publication;
use App\Models\User;
use App\Services\AuditRecorder;
use App\Services\LocalDateTimeResolver;
use App\Services\PublicationSnapshotter;
use App\Services\PublicationTargetConfigurator;
use App\Services\PublicationWorkflowConfirmation;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SchedulePublication
{
    public function __construct(
        private readonly PublicationTargetConfigurator $targetConfigurator,
        private readonly PublicationSnapshotter $snapshotter,
        private readonly AuditRecorder $auditRecorder,
        private readonly PublicationWorkflowConfirmation $workflowConfirmation,
        private readonly LocalDateTimeResolver $localDateTimeResolver,
    ) {}

    /**
     * @param  array<int, string>  $socialProviders
     * @param  array<string, string|null>  $providerText
     * @param  array<int, int|string>  $socialAccountIds
     */
    public function handle(
        Publication $publication,
        User $owner,
        DateTimeInterface|string $scheduledAt,
        string $timezone,
        bool $privacyReviewConfirmed,
        bool $previewConfirmed,
        bool $publishToWebsite,
        array $socialProviders,
        array $providerText = [],
        array $socialAccountIds = [],
    ): Publication {
        Gate::forUser($owner)->authorize('publish', $publication);

        if (! $privacyReviewConfirmed || ! $previewConfirmed) {
            throw ValidationException::withMessages([
                'privacy_review_confirmed' => [__('Review privacy and preview the public version before scheduling publication.')],
            ]);
        }

        $scheduledAtUtc = $this->localDateTimeResolver->resolve($scheduledAt, $timezone);

        if (! $scheduledAtUtc->isFuture()) {
            throw ValidationException::withMessages([
                'scheduled_at' => [__('Choose a publication time in the future.')],
            ]);
        }

        return DB::transaction(function () use (
            $publication,
            $owner,
            $scheduledAtUtc,
            $publishToWebsite,
            $socialProviders,
            $providerText,
            $socialAccountIds,
        ): Publication {
            $publication = Publication::query()
                ->ownedBy($owner)
                ->lockForUpdate()
                ->findOrFail($publication->getKey());

            if (! in_array($publication->status, [
                PublicationStatus::Draft,
                PublicationStatus::Unpublished,
            ], true)) {
                throw ValidationException::withMessages([
                    'status' => [__('Only draft or unpublished publications can be scheduled.')],
                ]);
            }

            $this->workflowConfirmation->assertReadyToPublish($publication);

            $this->targetConfigurator->configure(
                $publication,
                $publishToWebsite,
                $socialProviders,
                $providerText,
                PublicationTargetStatus::Scheduled,
                $scheduledAtUtc,
                $socialAccountIds,
            );

            $publication->forceFill([
                'status' => PublicationStatus::Scheduled,
                'scheduled_at' => $scheduledAtUtc,
                'unpublished_at' => null,
            ])->save();

            $this->snapshotter->snapshot($publication, 'scheduled');
            $scheduledFingerprint = $this->workflowConfirmation->currentFingerprint($publication);
            $this->auditRecorder->record(
                event: 'publication.scheduled',
                actor: $owner,
                auditable: $publication,
                metadata: [
                    'scheduled_at' => $scheduledAtUtc->toIso8601String(),
                    'workflow_fingerprint' => $scheduledFingerprint,
                ],
            );

            PublicationScheduled::dispatch(
                (int) $publication->getKey(),
                (int) $owner->getKey(),
                $scheduledAtUtc,
            );

            return $publication->refresh();
        });
    }
}
