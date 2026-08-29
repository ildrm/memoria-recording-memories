<?php

namespace App\Actions;

use App\Enums\PublicationStatus;
use App\Enums\PublicationTargetStatus;
use App\Enums\PublicationTargetType;
use App\Enums\SocialPostStatus;
use App\Events\PublicationPublished;
use App\Jobs\PublishSocialPost;
use App\Models\Publication;
use App\Models\PublicationTarget;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\AuditRecorder;
use App\Services\PublicationSnapshotter;
use App\Services\PublicationTargetConfigurator;
use App\Services\PublicationWorkflowConfirmation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PublishPublication
{
    public function __construct(
        private readonly PublicationTargetConfigurator $targetConfigurator,
        private readonly PublicationSnapshotter $snapshotter,
        private readonly AuditRecorder $auditRecorder,
        private readonly PublicationWorkflowConfirmation $workflowConfirmation,
    ) {}

    /**
     * @param  array<int, string>  $socialProviders
     * @param  array<string, string|null>  $providerText
     * @param  array<int, int|string>  $socialAccountIds
     */
    public function handle(
        Publication $publication,
        User $owner,
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
                'privacy_review_confirmed' => [__('Review privacy and preview the public version before publishing.')],
            ]);
        }

        return $this->publish(
            publication: $publication,
            owner: $owner,
            configureTargets: true,
            privacyReviewConfirmed: true,
            previewConfirmed: true,
            publishToWebsite: $publishToWebsite,
            socialProviders: $socialProviders,
            providerText: $providerText,
            socialAccountIds: $socialAccountIds,
        );
    }

    public function scheduled(Publication $publication): Publication
    {
        $owner = User::query()->findOrFail($publication->user_id);
        Gate::forUser($owner)->authorize('publish', $publication);

        return $this->publish(
            publication: $publication,
            owner: $owner,
            configureTargets: false,
            privacyReviewConfirmed: $publication->privacy_reviewed_at !== null,
            previewConfirmed: $publication->privacy_reviewed_at !== null,
            publishToWebsite: false,
            socialProviders: [],
            providerText: [],
            socialAccountIds: [],
        );
    }

    /**
     * @param  array<int, string>  $socialProviders
     * @param  array<string, string|null>  $providerText
     * @param  array<int, int|string>  $socialAccountIds
     */
    private function publish(
        Publication $publication,
        User $owner,
        bool $configureTargets,
        bool $privacyReviewConfirmed,
        bool $previewConfirmed,
        bool $publishToWebsite,
        array $socialProviders,
        array $providerText,
        array $socialAccountIds,
    ): Publication {
        return DB::transaction(function () use (
            $publication,
            $owner,
            $configureTargets,
            $privacyReviewConfirmed,
            $previewConfirmed,
            $publishToWebsite,
            $socialProviders,
            $providerText,
            $socialAccountIds,
        ): Publication {
            $publication = Publication::query()
                ->ownedBy($owner)
                ->lockForUpdate()
                ->findOrFail($publication->getKey());

            $allowedStatuses = $configureTargets
                ? [PublicationStatus::Draft, PublicationStatus::Unpublished]
                : [PublicationStatus::Scheduled];

            if (! in_array($publication->status, $allowedStatuses, true)) {
                throw ValidationException::withMessages([
                    'status' => [$configureTargets
                        ? __('Only draft or unpublished publications can be published.')
                        : __('Only scheduled publications can be dispatched.')],
                ]);
            }

            if (! $privacyReviewConfirmed || ! $previewConfirmed) {
                throw ValidationException::withMessages([
                    'privacy_review_confirmed' => [__('Review privacy and preview the public version before publishing.')],
                ]);
            }

            if ($configureTargets) {
                $this->workflowConfirmation->assertReadyToPublish($publication);
            } else {
                $this->workflowConfirmation->assertScheduledVersionUnchanged($publication);
            }

            $targets = $configureTargets
                ? $this->targetConfigurator->configure(
                    $publication,
                    $publishToWebsite,
                    $socialProviders,
                    $providerText,
                    PublicationTargetStatus::Pending,
                    socialAccountIds: $socialAccountIds,
                )
                : $this->scheduledTargets($publication);

            $publication->forceFill([
                'status' => PublicationStatus::Published,
                'published_at' => now(),
                'scheduled_at' => null,
                'unpublished_at' => null,
            ])->save();

            $this->snapshotter->snapshot(
                $publication,
                $configureTargets ? 'published' : 'scheduled_publish',
            );

            foreach ($targets as $target) {
                $this->dispatchTarget($publication, $target);
            }

            $this->auditRecorder->record(
                event: 'publication.published',
                actor: $owner,
                auditable: $publication,
                metadata: ['target_count' => $targets->count()],
            );

            PublicationPublished::dispatch(
                (int) $publication->getKey(),
                (int) $owner->getKey(),
            );

            return $publication->refresh();
        });
    }

    /**
     * @return Collection<int, PublicationTarget>
     */
    private function scheduledTargets(Publication $publication): Collection
    {
        $targets = PublicationTarget::query()
            ->whereBelongsTo($publication)
            ->where('status', PublicationTargetStatus::Scheduled)
            ->lockForUpdate()
            ->get();

        if ($targets->isEmpty()) {
            throw ValidationException::withMessages([
                'targets' => [__('This scheduled publication has no active targets.')],
            ]);
        }

        return $targets;
    }

    private function dispatchTarget(Publication $publication, PublicationTarget $target): void
    {
        if ($target->type === PublicationTargetType::Website) {
            $target->forceFill([
                'status' => PublicationTargetStatus::Published,
                'scheduled_at' => null,
                'dispatched_at' => now(),
                'completed_at' => now(),
                'failed_at' => null,
            ])->save();

            return;
        }

        $target->forceFill([
            'status' => PublicationTargetStatus::Pending,
            'scheduled_at' => null,
            'dispatched_at' => now(),
            'completed_at' => null,
            'failed_at' => null,
        ])->save();

        $idempotencyKey = hash(
            'sha256',
            "publication:{$publication->getKey()}:target:{$target->getKey()}:revision:{$publication->revision}",
        );
        $content = filled($target->content_override)
            ? (string) $target->content_override
            : $this->fallbackSocialContent($publication);

        $socialPost = SocialPost::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first() ?? new SocialPost;
        $socialPost->forceFill([
            'user_id' => $publication->user_id,
            'publication_id' => $publication->getKey(),
            'publication_target_id' => $target->getKey(),
            'social_account_id' => $target->social_account_id,
            'provider' => $target->provider,
            'status' => SocialPostStatus::Pending,
            'idempotency_key' => $idempotencyKey,
            'request_fingerprint' => hash('sha256', $content),
            'content' => $content,
            'scheduled_at' => null,
            'next_retry_at' => null,
            'failed_at' => null,
            'error_code' => null,
            'error_message' => null,
        ]);
        $socialPost->save();

        PublishSocialPost::dispatch((int) $socialPost->getKey())->afterCommit();
    }

    private function fallbackSocialContent(Publication $publication): string
    {
        $summary = filled($publication->excerpt)
            ? (string) $publication->excerpt
            : (string) Str::of((string) $publication->body)->stripTags()->squish();

        return trim((string) $publication->title."\n\n".$summary);
    }
}
