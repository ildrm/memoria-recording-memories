<?php

namespace App\Jobs;

use App\Contracts\SocialPublisherRegistry;
use App\Enums\SocialPostStatus;
use App\Enums\SocialProvider;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Services\AuditRecorder;
use App\Services\Social\Exceptions\PermanentSocialPublishException;
use App\Services\Social\Exceptions\RetryableSocialPublishException;
use App\Services\Social\Exceptions\SanitizedSocialIntegrationException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;
use Throwable;

class DeleteRemoteSocialPost implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 8;

    public int $maxExceptions = 6;

    public int $timeout = 30;

    public int $uniqueFor = 86400;

    /** @var array<int, int> */
    public array $backoff = [15, 60, 300, 900, 3600, 10800];

    public function __construct(public readonly int $socialPostDeletionId)
    {
        $this->onQueue('social');
    }

    public function uniqueId(): string
    {
        return (string) $this->socialPostDeletionId;
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('social-post-deletion:'.$this->socialPostDeletionId))
                ->expireAfter(120)
                ->dontRelease(),
        ];
    }

    public function handle(
        SocialPublisherRegistry $publishers,
        AuditRecorder $auditRecorder,
    ): void {
        $deletion = $this->deletionRecord();
        if ($deletion === null || $deletion['completed_at'] || $deletion['failed_at']) {
            return;
        }

        DB::table('social_post_deletions')
            ->where('id', $this->socialPostDeletionId)
            ->whereNull('completed_at')
            ->whereNull('failed_at')
            ->update([
                'attempts' => DB::raw('attempts + 1'),
                'last_attempted_at' => now(),
                'last_error_code' => null,
                'updated_at' => now(),
            ]);

        try {
            $context = $this->providerContext($deletion);
            $publisher = $publishers->for($context['provider']);
            $publisher->delete($context['account'], $context['post']);
        } catch (PermanentSocialPublishException $exception) {
            $this->markFailed($exception->errorCode, $auditRecorder);

            return;
        } catch (RetryableSocialPublishException $exception) {
            $this->recordRetryableFailure();

            throw new RetryableSocialPublishException('The remote social post cleanup is temporarily unavailable.');
        } catch (Throwable $exception) {
            $this->recordRetryableFailure('unknown_provider_failure');

            report(new SanitizedSocialIntegrationException(
                operation: 'remote_post_deletion',
                provider: $deletion['provider'],
                failureClass: class_basename($exception),
            ));

            throw new RetryableSocialPublishException(
                'The remote social post cleanup could not be completed.',
            );
        }

        DB::transaction(function (): void {
            $socialPostId = DB::table('social_post_deletions')
                ->where('id', $this->socialPostDeletionId)
                ->lockForUpdate()
                ->value('social_post_id');
            DB::table('social_post_deletions')
                ->where('id', $this->socialPostDeletionId)
                ->update([
                    'encrypted_remote_post_id' => null,
                    'encrypted_credentials' => null,
                    'last_error_code' => null,
                    'completed_at' => now(),
                    'failed_at' => null,
                    'updated_at' => now(),
                ]);

            if (is_int($socialPostId) || (is_string($socialPostId) && ctype_digit($socialPostId))) {
                SocialPost::query()->whereKey((int) $socialPostId)->update([
                    'status' => SocialPostStatus::Deleted->value,
                    'remote_post_id' => null,
                    'remote_url' => null,
                    'error_code' => null,
                    'error_message' => null,
                    'failed_at' => null,
                    'updated_at' => now(),
                ]);
            }
        });
        $auditRecorder->record(
            event: 'social_post.remote_deletion_completed',
            metadata: $this->auditMetadata($deletion),
        );
    }

    public function failed(?Throwable $exception): void
    {
        $deletion = $this->deletionRecord();
        if ($deletion === null || $deletion['completed_at'] || $deletion['failed_at']) {
            return;
        }

        $errorCode = $exception instanceof PermanentSocialPublishException
            ? $exception->errorCode
            : 'retries_exhausted';
        $this->markFailed($errorCode, app(AuditRecorder::class));
    }

    private function recordRetryableFailure(string $errorCode = 'temporary_provider_failure'): void
    {
        DB::table('social_post_deletions')
            ->where('id', $this->socialPostDeletionId)
            ->whereNull('completed_at')
            ->whereNull('failed_at')
            ->update([
                'last_error_code' => $errorCode,
                'updated_at' => now(),
            ]);
    }

    private function markFailed(string $errorCode, AuditRecorder $auditRecorder): void
    {
        $deletion = $this->deletionRecord();
        if ($deletion === null || $deletion['completed_at']) {
            return;
        }

        DB::transaction(function () use ($errorCode): void {
            $socialPostId = DB::table('social_post_deletions')
                ->where('id', $this->socialPostDeletionId)
                ->lockForUpdate()
                ->value('social_post_id');
            DB::table('social_post_deletions')
                ->where('id', $this->socialPostDeletionId)
                ->whereNull('completed_at')
                ->update([
                    'encrypted_remote_post_id' => null,
                    'encrypted_credentials' => null,
                    'last_error_code' => $errorCode,
                    'failed_at' => now(),
                    'updated_at' => now(),
                ]);

            if (is_int($socialPostId) || (is_string($socialPostId) && ctype_digit($socialPostId))) {
                SocialPost::query()->whereKey((int) $socialPostId)->update([
                    'status' => SocialPostStatus::DeletionFailed->value,
                    'error_code' => $errorCode,
                    'error_message' => 'The provider copy may remain because automatic removal failed.',
                    'failed_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
        $auditRecorder->record(
            event: 'social_post.remote_deletion_failed',
            metadata: array_merge($this->auditMetadata($deletion), [
                'error_code' => $errorCode,
                'external_copy_may_remain' => true,
            ]),
        );
    }

    /**
     * @param  array{provider: string, remote_post_hash: string, encrypted_remote_post_id: string|null, encrypted_credentials: string|null, reason: string, completed_at: bool, failed_at: bool}  $deletion
     * @return array{provider: SocialProvider, account: SocialAccount, post: SocialPost}
     *
     * @throws JsonException
     */
    private function providerContext(array $deletion): array
    {
        $provider = SocialProvider::tryFrom($deletion['provider']);
        $remotePostId = $deletion['encrypted_remote_post_id'] !== null
            ? Crypt::decryptString($deletion['encrypted_remote_post_id'])
            : '';
        $credentialsJson = $deletion['encrypted_credentials'] !== null
            ? Crypt::decryptString($deletion['encrypted_credentials'])
            : '';
        $credentials = json_decode($credentialsJson, true, flags: JSON_THROW_ON_ERROR);

        if ($provider === null
            || trim($remotePostId) === ''
            || ! is_array($credentials)
            || ! is_string($credentials['access_token'] ?? null)
            || trim($credentials['access_token']) === '') {
            throw new RuntimeException('The remote social post cleanup request is invalid.');
        }

        $account = new SocialAccount;
        $account->forceFill([
            'provider' => $provider,
            'access_token' => $credentials['access_token'],
            'server_url' => is_string($credentials['server_url'] ?? null)
                ? $credentials['server_url']
                : null,
            'metadata' => is_array($credentials['metadata'] ?? null)
                ? $credentials['metadata']
                : [],
        ]);
        $post = new SocialPost;
        $post->forceFill([
            'provider' => $provider,
            'remote_post_id' => $remotePostId,
        ]);

        return compact('provider', 'account', 'post');
    }

    /**
     * @return array{provider: string, remote_post_hash: string, encrypted_remote_post_id: string|null, encrypted_credentials: string|null, reason: string, completed_at: bool, failed_at: bool}|null
     */
    private function deletionRecord(): ?array
    {
        $deletion = DB::table('social_post_deletions')
            ->select([
                'provider', 'remote_post_hash', 'encrypted_remote_post_id',
                'encrypted_credentials', 'reason', 'completed_at', 'failed_at',
            ])
            ->find($this->socialPostDeletionId);
        if ($deletion === null) {
            return null;
        }

        $encryptedRemotePostId = data_get($deletion, 'encrypted_remote_post_id');
        $encryptedCredentials = data_get($deletion, 'encrypted_credentials');

        return [
            'provider' => (string) data_get($deletion, 'provider'),
            'remote_post_hash' => (string) data_get($deletion, 'remote_post_hash'),
            'encrypted_remote_post_id' => is_string($encryptedRemotePostId) ? $encryptedRemotePostId : null,
            'encrypted_credentials' => is_string($encryptedCredentials) ? $encryptedCredentials : null,
            'reason' => (string) data_get($deletion, 'reason'),
            'completed_at' => data_get($deletion, 'completed_at') !== null,
            'failed_at' => data_get($deletion, 'failed_at') !== null,
        ];
    }

    /**
     * @param  array{provider: string, remote_post_hash: string, encrypted_remote_post_id: string|null, encrypted_credentials: string|null, reason: string, completed_at: bool, failed_at: bool}  $deletion
     * @return array{social_post_deletion_id: int, provider: string, remote_post_hash: string, reason: string}
     */
    private function auditMetadata(array $deletion): array
    {
        return [
            'social_post_deletion_id' => $this->socialPostDeletionId,
            'provider' => $deletion['provider'],
            'remote_post_hash' => $deletion['remote_post_hash'],
            'reason' => $deletion['reason'],
        ];
    }
}
