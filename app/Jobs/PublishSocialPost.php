<?php

namespace App\Jobs;

use App\Contracts\SocialPublisherContract;
use App\Contracts\SocialPublisherRegistry;
use App\Enums\PublicationStatus;
use App\Enums\PublicationTargetStatus;
use App\Enums\PublicationTargetType;
use App\Enums\SocialPostStatus;
use App\Enums\SocialProvider;
use App\Events\SocialPostFailed;
use App\Events\SocialPostSucceeded;
use App\Models\Publication;
use App\Models\PublicationTarget;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostFailure;
use App\Models\User;
use App\Notifications\SocialPostFailedNotification;
use App\Services\AuditRecorder;
use App\Services\NotificationPreference;
use App\Services\Social\Exceptions\PermanentSocialPublishException;
use App\Services\Social\Exceptions\RetryableSocialPublishException;
use App\Services\Social\RemoteSocialPostCleanup;
use App\Services\Social\SocialAccessTokenRefresher;
use App\Services\Social\SocialPublishResult;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class PublishSocialPost implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $maxExceptions = 4;

    public int $timeout = 90;

    public int $uniqueFor = 7200;

    /**
     * @var array<int, int>
     */
    public array $backoff = [15, 60, 300, 900];

    public function __construct(public readonly int $socialPostId)
    {
        $this->onQueue('social');
    }

    public function uniqueId(): string
    {
        return (string) $this->socialPostId;
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('social-post:'.$this->socialPostId))
                ->expireAfter(max(
                    (int) config('memoria.social.lock_seconds', 60),
                    $this->timeout + 30,
                ))
                ->dontRelease(),
        ];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(2);
    }

    public function handle(
        SocialPublisherRegistry $publishers,
        AuditRecorder $auditRecorder,
    ): void {
        $socialPost = SocialPost::query()->find($this->socialPostId);

        if ($socialPost === null || in_array($socialPost->status, [
            SocialPostStatus::Published,
            SocialPostStatus::Cancelled,
        ], true)) {
            return;
        }

        $owner = $this->ownerFor($socialPost);
        if ($owner === null) {
            $this->markTerminal(
                $socialPost,
                SocialPostStatus::Cancelled,
                'owner_unavailable',
                'The owning account is unavailable.',
            );

            return;
        }

        $account = SocialAccount::query()->find($socialPost->social_account_id);
        if ($account === null || $account->revoked_at !== null) {
            $this->markTerminal($socialPost, SocialPostStatus::Disconnected, 'account_disconnected');

            return;
        }

        if ((int) $account->user_id !== (int) $socialPost->user_id) {
            $this->markTerminal(
                $socialPost,
                SocialPostStatus::Failed,
                'ownership_mismatch',
                'The social publication ownership chain is invalid.',
            );

            return;
        }

        if ($account->token_expires_at?->isPast()) {
            try {
                $account = app(SocialAccessTokenRefresher::class)->refreshIfExpired($account);
            } catch (RetryableSocialPublishException $exception) {
                $this->recordFailure($socialPost, $exception, true);
                $this->markRetrying($socialPost);

                throw new RetryableSocialPublishException(
                    'The social provider credential refresh is temporarily unavailable.',
                );
            } catch (PermanentSocialPublishException $exception) {
                $this->recordFailure($socialPost, $exception, false);
                $this->markTerminal($socialPost, SocialPostStatus::TokenExpired, 'token_expired');

                return;
            }

        }

        $publication = Publication::query()->find($socialPost->publication_id);
        $target = $this->targetFor($socialPost);
        if ($publication === null
            || (int) $publication->user_id !== (int) $socialPost->user_id
            || $target === null) {
            $this->markTerminal(
                $socialPost,
                SocialPostStatus::Failed,
                'ownership_mismatch',
                'The social publication ownership chain is invalid.',
            );

            return;
        }

        $provider = $this->provider($socialPost);
        if ($provider === null
            || $account->provider !== $provider
            || $target->provider !== $provider) {
            $this->markTerminal(
                $socialPost,
                SocialPostStatus::Failed,
                'invalid_provider',
                'The social provider is not supported.',
            );

            return;
        }

        $idempotencyKey = $socialPost->idempotency_key;
        if (! is_string($idempotencyKey) || $idempotencyKey === '') {
            $this->markTerminal(
                $socialPost,
                SocialPostStatus::Failed,
                'missing_idempotency_key',
                'The social publication request is incomplete.',
            );

            return;
        }

        try {
            $publisher = $publishers->for($provider);
        } catch (PermanentSocialPublishException $exception) {
            $this->recordFailure($socialPost, $exception, false);

            if ($exception->errorCode === 'token_expired') {
                $this->markTerminal($socialPost, SocialPostStatus::TokenExpired, 'token_expired');
            } else {
                $this->markFailed($socialPost, false, $exception->errorCode);
            }

            return;
        } catch (RetryableSocialPublishException $exception) {
            $this->recordFailure($socialPost, $exception, true);
            $this->markRetrying($socialPost);

            throw new RetryableSocialPublishException('The social provider is temporarily unavailable.');
        } catch (Throwable $exception) {
            $this->recordFailure($socialPost, $exception, true);
            $this->markRetrying($socialPost);

            throw new RetryableSocialPublishException('The social provider request could not be completed.');
        }

        if ($socialPost->status === SocialPostStatus::Processing
            && ! $publisher->supportsIdempotentPublish()) {
            $this->recordFailure(
                $socialPost,
                new RetryableSocialPublishException(
                    'The previous social provider request has an uncertain outcome.',
                    outcomeIsUncertain: true,
                ),
                true,
            );
            $this->markFailed($socialPost, true, 'delivery_outcome_unknown');

            return;
        }

        $dispatch = $this->beginProviderDispatch(
            $provider,
            $idempotencyKey,
            $publisher->supportsIdempotentPublish(),
        );
        if ($dispatch === null) {
            return;
        }

        $socialPost = $dispatch['post'];
        $account = $dispatch['account'];
        $publication = $dispatch['publication'];

        try {
            $result = $publisher->publish(
                $account,
                $socialPost,
                $publication,
                $idempotencyKey,
            );
        } catch (PermanentSocialPublishException $exception) {
            $this->recordFailure($socialPost, $exception, false);

            if ($exception->errorCode === 'token_expired') {
                $this->markTerminal($socialPost, SocialPostStatus::TokenExpired, 'token_expired');
            } else {
                $this->markFailed($socialPost, false, $exception->errorCode);
            }

            return;
        } catch (RetryableSocialPublishException $exception) {
            $this->recordFailure($socialPost, $exception, true);

            if ($exception->outcomeIsUncertain && ! $publisher->supportsIdempotentPublish()) {
                $this->markFailed($socialPost, true, 'delivery_outcome_unknown');

                return;
            }

            $this->markRetrying($socialPost);

            throw new RetryableSocialPublishException('The social provider is temporarily unavailable.');
        } catch (Throwable $exception) {
            $this->recordFailure($socialPost, $exception, true);

            if (! $publisher->supportsIdempotentPublish()) {
                $this->markFailed($socialPost, true, 'delivery_outcome_unknown');

                return;
            }

            $this->markRetrying($socialPost);

            throw new RetryableSocialPublishException('The social provider request could not be completed.');
        }

        if (! $this->completeProviderDispatch($provider, $idempotencyKey, $result, $auditRecorder)) {
            $this->compensateCancelledDispatch($publisher, $account, $socialPost, $result);
        }
    }

    /**
     * @return array{post: SocialPost, account: SocialAccount, publication: Publication, target: PublicationTarget, owner: User}|null
     */
    private function beginProviderDispatch(
        SocialProvider $provider,
        string $idempotencyKey,
        bool $supportsIdempotentPublish,
    ): ?array {
        return DB::transaction(function () use ($provider, $idempotencyKey, $supportsIdempotentPublish): ?array {
            $context = $this->lockedLifecycleContext($provider, $idempotencyKey);
            $dispatchableStatuses = [
                SocialPostStatus::Pending,
                SocialPostStatus::Scheduled,
                SocialPostStatus::Retrying,
            ];

            if ($supportsIdempotentPublish) {
                $dispatchableStatuses[] = SocialPostStatus::Processing;
            }

            if ($context === null
                || ! in_array($context['post']->status, $dispatchableStatuses, true)
                || ! in_array($context['target']->status, [
                    PublicationTargetStatus::Pending,
                    PublicationTargetStatus::Scheduled,
                    PublicationTargetStatus::Processing,
                ], true)) {
                return null;
            }

            $context['post']->forceFill([
                'status' => SocialPostStatus::Processing,
                'attempt_count' => (int) $context['post']->attempt_count + 1,
                'last_attempted_at' => now(),
                'next_retry_at' => null,
                'error_code' => null,
                'error_message' => null,
            ])->save();
            $context['target']->forceFill([
                'status' => PublicationTargetStatus::Processing,
            ])->save();

            return $context;
        });
    }

    private function completeProviderDispatch(
        SocialProvider $provider,
        string $idempotencyKey,
        SocialPublishResult $result,
        AuditRecorder $auditRecorder,
    ): bool {
        return DB::transaction(function () use ($provider, $idempotencyKey, $result, $auditRecorder): bool {
            $context = $this->lockedLifecycleContext($provider, $idempotencyKey);
            if ($context === null
                || $context['post']->status !== SocialPostStatus::Processing
                || $context['target']->status !== PublicationTargetStatus::Processing) {
                return false;
            }

            $context['post']->forceFill([
                'status' => SocialPostStatus::Published,
                'remote_post_id' => $result->remoteId,
                'remote_url' => $result->remoteUrl,
                'published_at' => now(),
                'failed_at' => null,
                'next_retry_at' => null,
                'error_code' => null,
                'error_message' => null,
            ])->save();
            $context['target']->forceFill([
                'status' => PublicationTargetStatus::Published,
                'completed_at' => now(),
                'failed_at' => null,
            ])->save();

            $auditRecorder->record(
                event: 'social_post.published',
                actor: $context['owner'],
                auditable: $context['post'],
                metadata: ['provider' => $provider->value],
            );
            SocialPostSucceeded::dispatch(
                (int) $context['post']->getKey(),
                (int) $context['post']->user_id,
            );

            return true;
        });
    }

    /**
     * Lock lifecycle roots in the same account/publication order used by cancellation actions.
     *
     * @return array{post: SocialPost, account: SocialAccount, publication: Publication, target: PublicationTarget, owner: User}|null
     */
    private function lockedLifecycleContext(
        SocialProvider $provider,
        string $idempotencyKey,
    ): ?array {
        $seed = SocialPost::query()->find($this->socialPostId);
        if (! $seed instanceof SocialPost) {
            return null;
        }

        $account = SocialAccount::query()
            ->whereKey($seed->social_account_id)
            ->lockForUpdate()
            ->first();
        $publication = Publication::query()
            ->whereKey($seed->publication_id)
            ->lockForUpdate()
            ->first();
        $target = PublicationTarget::query()
            ->whereKey($seed->publication_target_id)
            ->lockForUpdate()
            ->first();
        $post = SocialPost::query()
            ->whereKey($this->socialPostId)
            ->lockForUpdate()
            ->first();

        if (! $post instanceof SocialPost
            || ! $account instanceof SocialAccount
            || ! $publication instanceof Publication
            || ! $target instanceof PublicationTarget
            || $publication->status !== PublicationStatus::Published
            || $target->type !== PublicationTargetType::Social
            || (int) $post->user_id !== (int) $publication->user_id
            || (int) $post->user_id !== (int) $account->user_id
            || (int) $post->user_id !== (int) $target->user_id
            || (int) $post->publication_id !== (int) $publication->getKey()
            || (int) $post->publication_target_id !== (int) $target->getKey()
            || (int) $target->publication_id !== (int) $publication->getKey()
            || (int) $post->social_account_id !== (int) $account->getKey()
            || (int) $target->social_account_id !== (int) $account->getKey()
            || $this->provider($post) !== $provider
            || $account->provider !== $provider
            || $target->provider !== $provider
            || ! hash_equals($idempotencyKey, (string) $post->idempotency_key)
            || ! $account->isConnected()) {
            return null;
        }

        $owner = $this->ownerFor($post);
        if (! $owner instanceof User) {
            return null;
        }

        return compact('post', 'account', 'publication', 'target', 'owner');
    }

    private function compensateCancelledDispatch(
        SocialPublisherContract $publisher,
        SocialAccount $account,
        SocialPost $socialPost,
        SocialPublishResult $result,
    ): void {
        $remotePost = clone $socialPost;
        $remotePost->forceFill([
            'remote_post_id' => $result->remoteId,
            'remote_url' => $result->remoteUrl,
        ]);

        try {
            $publisher->delete($account, $remotePost);
        } catch (Throwable $exception) {
            $this->recordCompensationFailure($socialPost, $result, $exception);
            app(RemoteSocialPostCleanup::class)->schedule(
                $remotePost,
                'cancelled_dispatch_compensation_failed',
                $account,
            );
        }
    }

    private function recordCompensationFailure(
        SocialPost $socialPost,
        SocialPublishResult $result,
        Throwable $exception,
    ): void {
        DB::transaction(function () use ($socialPost, $result, $exception): void {
            $lockedPost = SocialPost::query()->lockForUpdate()->find($socialPost->getKey());
            if (! $lockedPost instanceof SocialPost || $lockedPost->status === SocialPostStatus::Published) {
                return;
            }

            $lockedPost->forceFill([
                'remote_post_id' => $result->remoteId,
                'remote_url' => $result->remoteUrl,
                'failed_at' => now(),
                'error_code' => 'cancelled_remote_cleanup_failed',
                'error_message' => 'The cancelled external post could not be removed automatically.',
            ])->save();

            $failure = new SocialPostFailure;
            $failure->forceFill([
                'social_post_id' => $lockedPost->getKey(),
                'attempt' => $lockedPost->attempt_count,
                'error_class' => class_basename($exception),
                'error_code' => 'cancelled_remote_cleanup_failed',
                'message' => 'The cancelled external post could not be removed automatically.',
                'is_retryable' => $exception instanceof RetryableSocialPublishException,
                'context' => ['provider' => $this->provider($lockedPost)?->value],
                'occurred_at' => now(),
            ])->save();
        });
    }

    public function failed(?Throwable $exception): void
    {
        $socialPost = SocialPost::query()->find($this->socialPostId);
        if ($socialPost === null || in_array($socialPost->status, [
            SocialPostStatus::Published,
            SocialPostStatus::Cancelled,
        ], true)) {
            return;
        }

        $this->markFailed($socialPost, true, 'retries_exhausted');
    }

    private function markTerminal(
        SocialPost $socialPost,
        SocialPostStatus $status,
        string $errorCode,
        string $errorMessage = 'Reconnect the social account before retrying.',
    ): void {
        $transitionedPost = DB::transaction(function () use ($socialPost, $status, $errorCode, $errorMessage): ?SocialPost {
            $context = $this->lockedTransitionContext($socialPost);
            if ($context === null || ! $this->isActivePostStatus($context['post']->status)) {
                return null;
            }

            $context['post']->forceFill([
                'status' => $status,
                'failed_at' => now(),
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'next_retry_at' => null,
            ])->save();

            if ($context['target'] instanceof PublicationTarget
                && $this->isActiveTargetStatus($context['target']->status)) {
                $context['target']->forceFill([
                    'status' => PublicationTargetStatus::Failed,
                    'failed_at' => now(),
                ])->save();
            }

            return $context['post'];
        });

        if (! $transitionedPost instanceof SocialPost) {
            return;
        }

        $this->notifyOwnerOfFailure($transitionedPost);
        SocialPostFailed::dispatch(
            (int) $transitionedPost->getKey(),
            (int) $transitionedPost->user_id,
            false,
        );
    }

    private function markRetrying(SocialPost $socialPost): void
    {
        $transitionedPost = DB::transaction(function () use ($socialPost): ?SocialPost {
            $context = $this->lockedTransitionContext($socialPost);
            if ($context === null || ! $this->isActivePostStatus($context['post']->status)) {
                return null;
            }

            $delay = $this->backoff[min(max($context['post']->attempt_count - 1, 0), count($this->backoff) - 1)];
            $context['post']->forceFill([
                'status' => SocialPostStatus::Retrying,
                'next_retry_at' => now()->addSeconds($delay),
                'error_code' => 'temporary_provider_failure',
                'error_message' => 'The provider request will be retried.',
            ])->save();

            return $context['post'];
        });

        if (! $transitionedPost instanceof SocialPost) {
            return;
        }

        SocialPostFailed::dispatch(
            (int) $transitionedPost->getKey(),
            (int) $transitionedPost->user_id,
            true,
        );
    }

    private function markFailed(SocialPost $socialPost, bool $retryable, string $errorCode): void
    {
        $transitionedPost = DB::transaction(function () use ($socialPost, $errorCode): ?SocialPost {
            $context = $this->lockedTransitionContext($socialPost);
            if ($context === null || ! $this->isActivePostStatus($context['post']->status)) {
                return null;
            }

            $context['post']->forceFill([
                'status' => SocialPostStatus::Failed,
                'failed_at' => now(),
                'next_retry_at' => null,
                'error_code' => $errorCode,
                'error_message' => 'Social publication failed. Review the connection before retrying.',
            ])->save();

            if ($context['target'] instanceof PublicationTarget
                && $this->isActiveTargetStatus($context['target']->status)) {
                $context['target']->forceFill([
                    'status' => PublicationTargetStatus::Failed,
                    'failed_at' => now(),
                ])->save();
            }

            return $context['post'];
        });

        if (! $transitionedPost instanceof SocialPost) {
            return;
        }

        $this->notifyOwnerOfFailure($transitionedPost);
        SocialPostFailed::dispatch(
            (int) $transitionedPost->getKey(),
            (int) $transitionedPost->user_id,
            $retryable,
        );
    }

    /**
     * Lock cancellation roots before the mutable rows so lifecycle actions always win cleanly.
     *
     * @return array{post: SocialPost, target: PublicationTarget|null}|null
     */
    private function lockedTransitionContext(SocialPost $socialPost): ?array
    {
        $seed = SocialPost::query()->find($socialPost->getKey());
        if (! $seed instanceof SocialPost) {
            return null;
        }

        SocialAccount::query()
            ->whereKey($seed->social_account_id)
            ->lockForUpdate()
            ->first();
        Publication::query()
            ->whereKey($seed->publication_id)
            ->lockForUpdate()
            ->first();
        $target = PublicationTarget::query()
            ->whereKey($seed->publication_target_id)
            ->lockForUpdate()
            ->first();
        $post = SocialPost::query()
            ->whereKey($seed->getKey())
            ->lockForUpdate()
            ->first();

        if (! $post instanceof SocialPost) {
            return null;
        }

        if (! $target instanceof PublicationTarget
            || (int) $target->publication_id !== (int) $post->publication_id
            || (int) $target->user_id !== (int) $post->user_id
            || (int) $target->social_account_id !== (int) $post->social_account_id) {
            $target = null;
        }

        return compact('post', 'target');
    }

    private function isActivePostStatus(SocialPostStatus|string $status): bool
    {
        $status = $status instanceof SocialPostStatus ? $status : SocialPostStatus::tryFrom($status);

        return in_array($status, [
            SocialPostStatus::Pending,
            SocialPostStatus::Scheduled,
            SocialPostStatus::Processing,
            SocialPostStatus::Retrying,
        ], true);
    }

    private function isActiveTargetStatus(PublicationTargetStatus|string $status): bool
    {
        $status = $status instanceof PublicationTargetStatus ? $status : PublicationTargetStatus::tryFrom($status);

        return in_array($status, [
            PublicationTargetStatus::Pending,
            PublicationTargetStatus::Scheduled,
            PublicationTargetStatus::Processing,
        ], true);
    }

    private function recordFailure(
        SocialPost $socialPost,
        Throwable $exception,
        bool $retryable,
    ): void {
        $errorCode = $exception instanceof PermanentSocialPublishException
            ? $exception->errorCode
            : ($retryable ? 'temporary_provider_failure' : 'provider_rejected');
        $failure = new SocialPostFailure;
        $failure->forceFill([
            'social_post_id' => $socialPost->getKey(),
            'attempt' => $socialPost->attempt_count,
            'error_class' => class_basename($exception),
            'error_code' => $errorCode,
            'message' => Str::limit(
                $retryable
                    ? 'The external provider request failed temporarily.'
                    : 'The external provider rejected the publication request.',
                1000,
                '',
            ),
            'is_retryable' => $retryable,
            'context' => ['provider' => $this->provider($socialPost)?->value],
            'occurred_at' => now(),
        ]);
        $failure->save();
    }

    private function notifyOwnerOfFailure(SocialPost $socialPost): void
    {
        $owner = $this->ownerFor($socialPost);
        if ($owner === null || ! app(NotificationPreference::class)->allows($owner, 'publication_activity')) {
            return;
        }

        $owner->notify(
            (new SocialPostFailedNotification((int) $socialPost->getKey()))->afterCommit(),
        );
    }

    private function provider(SocialPost $socialPost): ?SocialProvider
    {
        $provider = $socialPost->getRawOriginal('provider');

        return is_string($provider) ? SocialProvider::tryFrom($provider) : null;
    }

    private function ownerFor(SocialPost $socialPost): ?User
    {
        return User::query()
            ->whereKey($socialPost->user_id)
            ->whereNull('disabled_at')
            ->first();
    }

    private function targetFor(SocialPost $socialPost): ?PublicationTarget
    {
        return PublicationTarget::query()
            ->whereKey($socialPost->publication_target_id)
            ->where('publication_id', $socialPost->publication_id)
            ->where('user_id', $socialPost->user_id)
            ->where('social_account_id', $socialPost->social_account_id)
            ->first();
    }
}
