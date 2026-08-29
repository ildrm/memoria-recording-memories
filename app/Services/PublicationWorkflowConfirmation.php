<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Publication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PublicationWorkflowConfirmation
{
    public const PREVIEW_EVENT = 'publication.preview_recorded';

    public const REVIEW_EVENT = 'publication.privacy_review_confirmed';

    public function __construct(
        private readonly PublicationWorkflowFingerprint $fingerprint,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    public function confirmPrivacyReview(Publication $publication, User $owner): string
    {
        Gate::forUser($owner)->authorize('update', $publication);

        return DB::transaction(function () use ($publication, $owner): string {
            $publication = Publication::query()
                ->ownedBy($owner)
                ->lockForUpdate()
                ->findOrFail($publication->getKey());
            Gate::forUser($owner)->authorize('update', $publication);

            $publication->forceFill(['privacy_reviewed_at' => now()])->save();
            $fingerprint = $this->fingerprint->forPublication($publication);
            $this->auditRecorder->record(
                event: self::REVIEW_EVENT,
                actor: $owner,
                auditable: $publication,
                metadata: [
                    'workflow_fingerprint' => $fingerprint,
                    'publication_revision' => (int) $publication->revision,
                ],
            );

            return $fingerprint;
        });
    }

    public function recordPreview(Publication $publication, User $owner): string
    {
        Gate::forUser($owner)->authorize('update', $publication);

        return DB::transaction(function () use ($publication, $owner): string {
            $publication = Publication::query()
                ->ownedBy($owner)
                ->lockForUpdate()
                ->findOrFail($publication->getKey());
            Gate::forUser($owner)->authorize('update', $publication);

            $fingerprint = $this->fingerprint->forPublication($publication);
            $review = $this->latestMarker($publication, self::REVIEW_EVENT);
            if ($publication->privacy_reviewed_at === null
                || $this->markerFingerprint($review) !== $fingerprint) {
                throw ValidationException::withMessages([
                    'privacy_review_confirmed' => [__('Confirm the privacy review for this exact public version before previewing it.')],
                ]);
            }

            $this->auditRecorder->record(
                event: self::PREVIEW_EVENT,
                actor: $owner,
                auditable: $publication,
                metadata: [
                    'workflow_fingerprint' => $fingerprint,
                    'publication_revision' => (int) $publication->revision,
                    'privacy_review_event_id' => (int) $review?->getKey(),
                ],
            );

            return $fingerprint;
        });
    }

    public function assertReadyToPreview(Publication $publication, User $owner): string
    {
        Gate::forUser($owner)->authorize('update', $publication);

        $fingerprint = $this->fingerprint->forPublication($publication);
        $review = $this->latestMarker($publication, self::REVIEW_EVENT);

        if ($publication->privacy_reviewed_at === null
            || $this->markerFingerprint($review) !== $fingerprint) {
            throw ValidationException::withMessages([
                'privacy_review_confirmed' => [__('Confirm the privacy review for this exact public version before previewing it.')],
            ]);
        }

        return $fingerprint;
    }

    public function assertReadyToPublish(Publication $publication): string
    {
        $fingerprint = $this->fingerprint->forPublication($publication);
        $review = $this->latestMarker($publication, self::REVIEW_EVENT);
        $preview = $this->latestMarker($publication, self::PREVIEW_EVENT);

        if ($publication->privacy_reviewed_at === null
            || $this->markerFingerprint($review) !== $fingerprint
            || $this->markerFingerprint($preview) !== $fingerprint
            || $review === null
            || $preview === null
            || (int) $preview->getKey() <= (int) $review->getKey()) {
            throw ValidationException::withMessages([
                'privacy_review_confirmed' => [__('Review privacy and preview this exact public version before publishing or scheduling it.')],
            ]);
        }

        return $fingerprint;
    }

    public function assertScheduledVersionUnchanged(Publication $publication): void
    {
        $marker = $this->latestMarker($publication, 'publication.scheduled');
        if ($this->markerFingerprint($marker) !== $this->fingerprint->forPublication($publication)) {
            throw ValidationException::withMessages([
                'publication' => [__('The scheduled public version changed and must be reviewed and scheduled again.')],
            ]);
        }
    }

    public function currentFingerprint(Publication $publication): string
    {
        return $this->fingerprint->forPublication($publication);
    }

    private function latestMarker(Publication $publication, string $event): ?AuditEvent
    {
        return AuditEvent::query()
            ->where('auditable_type', $publication->getMorphClass())
            ->where('auditable_id', $publication->getKey())
            ->where('actor_user_id', $publication->user_id)
            ->where('event', $event)
            ->latest('id')
            ->first();
    }

    private function markerFingerprint(?AuditEvent $event): ?string
    {
        $metadata = $event?->getAttribute('metadata');

        return is_array($metadata) && is_string($metadata['workflow_fingerprint'] ?? null)
            ? $metadata['workflow_fingerprint']
            : null;
    }
}
