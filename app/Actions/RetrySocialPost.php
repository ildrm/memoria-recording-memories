<?php

namespace App\Actions;

use App\Enums\PublicationStatus;
use App\Enums\PublicationTargetStatus;
use App\Enums\SocialPostStatus;
use App\Jobs\PublishSocialPost;
use App\Models\Publication;
use App\Models\PublicationTarget;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostFailure;
use App\Models\User;
use App\Services\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

use function Illuminate\Support\enum_value;

class RetrySocialPost
{
    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    public function handle(SocialPost $post, User $owner): SocialPost
    {
        Gate::forUser($owner)->authorize('retry', $post);

        $post = DB::transaction(function () use ($post, $owner): SocialPost {
            $post = SocialPost::query()
                ->ownedBy($owner)
                ->lockForUpdate()
                ->findOrFail($post->getKey());
            Gate::forUser($owner)->authorize('retry', $post);

            if (! in_array($post->status, [
                SocialPostStatus::Failed,
                SocialPostStatus::Disconnected,
                SocialPostStatus::TokenExpired,
            ], true)) {
                throw ValidationException::withMessages([
                    'social_post' => [__('Only a stopped delivery can be retried explicitly.')],
                ]);
            }

            $account = SocialAccount::query()
                ->ownedBy($owner)
                ->whereKey($post->social_account_id)
                ->lockForUpdate()
                ->first();

            if (! $account instanceof SocialAccount
                || ! $account->isConnected()
                || $account->provider !== $post->provider) {
                throw ValidationException::withMessages([
                    'social_account' => [__('Reconnect the selected social account before retrying this delivery.')],
                ]);
            }

            $publication = Publication::query()
                ->ownedBy($owner)
                ->whereKey($post->publication_id)
                ->first();
            $target = PublicationTarget::query()
                ->whereKey($post->publication_target_id)
                ->where('publication_id', $post->publication_id)
                ->where('user_id', $owner->getKey())
                ->where('social_account_id', $account->getKey())
                ->lockForUpdate()
                ->first();
            $isReconnectRetry = in_array($post->status, [
                SocialPostStatus::Disconnected,
                SocialPostStatus::TokenExpired,
            ], true);

            if (! $publication instanceof Publication
                || $publication->status !== PublicationStatus::Published
                || ! $target instanceof PublicationTarget
                || $target->provider !== $post->provider
                || ($target->status === PublicationTargetStatus::Cancelled && ! $isReconnectRetry)) {
                throw ValidationException::withMessages([
                    'social_post' => [__('This publication is no longer active for the selected destination.')],
                ]);
            }

            if ($post->status === SocialPostStatus::Failed && ! $this->failedPostIsRetryable($post)) {
                throw ValidationException::withMessages([
                    'social_post' => [__('The provider rejected this request permanently. Review the destination or publication instead of resending it unchanged.')],
                ]);
            }

            $post->forceFill([
                'status' => SocialPostStatus::Pending,
                'failed_at' => null,
                'next_retry_at' => null,
                'error_code' => null,
                'error_message' => null,
            ])->save();
            $target->forceFill([
                'status' => PublicationTargetStatus::Pending,
                'failed_at' => null,
            ])->save();

            $this->auditRecorder->record(
                event: 'social_post.retry_requested',
                actor: $owner,
                auditable: $post,
                metadata: [
                    'provider' => (string) enum_value($post->provider),
                    'attempt_count' => (int) $post->attempt_count,
                    'idempotency_key_reused' => true,
                ],
            );

            return $post->refresh();
        });

        PublishSocialPost::dispatch((int) $post->getKey())->afterCommit();

        return $post;
    }

    private function failedPostIsRetryable(SocialPost $post): bool
    {
        $failure = SocialPostFailure::query()
            ->whereBelongsTo($post)
            ->latest('occurred_at')
            ->first(['is_retryable']);

        return $failure instanceof SocialPostFailure && $failure->is_retryable;
    }
}
