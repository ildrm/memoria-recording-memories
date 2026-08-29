<?php

namespace App\Services\Social;

use App\Enums\SocialProvider;
use App\Enums\SocialPostStatus;
use App\Jobs\DeleteRemoteSocialPost;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

class RemoteSocialPostCleanup
{
    public function schedule(
        SocialPost $socialPost,
        string $reason,
        ?SocialAccount $credentialSnapshot = null,
        bool $dispatchImmediately = true,
    ): ?int
    {
        $remotePostId = $socialPost->remote_post_id;
        $provider = $this->provider($socialPost);
        $account = $credentialSnapshot ?? SocialAccount::query()->find($socialPost->social_account_id);
        $accessToken = $account?->access_token;

        if ($provider === null
            || ! is_string($remotePostId)
            || trim($remotePostId) === ''
            || ! $account instanceof SocialAccount
            || (int) $account->getKey() !== (int) $socialPost->social_account_id
            || $account->provider !== $provider
            || ! is_string($accessToken)
            || trim($accessToken) === '') {
            return null;
        }

        $serverUrl = is_string($account->server_url) ? trim($account->server_url) : null;
        $remotePostHash = hash_hmac(
            'sha256',
            $provider->value."\0".($serverUrl ?? '')."\0".$remotePostId,
            (string) config('app.key'),
        );
        $deletionKey = hash('sha256', $provider->value."\0".$remotePostHash);
        $now = now();
        $credentials = $this->encryptedCredentials($account, $accessToken);

        $deletionId = DB::transaction(function () use (
            $socialPost,
            $provider,
            $deletionKey,
            $remotePostHash,
            $remotePostId,
            $credentials,
            $reason,
            $now,
        ): int {
            $existing = DB::table('social_post_deletions')
                ->where('deletion_key', $deletionKey)
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                return (int) DB::table('social_post_deletions')->insertGetId([
                    'social_post_id' => $socialPost->getKey(),
                    'provider' => $provider->value,
                    'deletion_key' => $deletionKey,
                    'remote_post_hash' => $remotePostHash,
                    'encrypted_remote_post_id' => Crypt::encryptString($remotePostId),
                    'encrypted_credentials' => $credentials,
                    'reason' => Str::limit($reason, 120, ''),
                    'attempts' => 0,
                    'last_attempted_at' => null,
                    'last_error_code' => null,
                    'completed_at' => null,
                    'failed_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $deletionId = (int) data_get($existing, 'id');
            if (data_get($existing, 'completed_at') === null) {
                DB::table('social_post_deletions')->where('id', $deletionId)->update([
                    'social_post_id' => $socialPost->getKey(),
                    'encrypted_remote_post_id' => Crypt::encryptString($remotePostId),
                    'encrypted_credentials' => $credentials,
                    'reason' => Str::limit($reason, 120, ''),
                    'attempts' => 0,
                    'last_attempted_at' => null,
                    'last_error_code' => null,
                    'failed_at' => null,
                    'updated_at' => $now,
                ]);
            }

            return $deletionId;
        });

        if (DB::table('social_post_deletions')
            ->where('id', $deletionId)
            ->whereNull('completed_at')
            ->whereNull('failed_at')
            ->exists()) {
            $socialPost->forceFill([
                'status' => SocialPostStatus::DeletionPending,
                'next_retry_at' => null,
                'error_code' => null,
                'error_message' => null,
            ])->save();
        }

        if ($dispatchImmediately) {
            DeleteRemoteSocialPost::dispatch($deletionId)->afterCommit();
        }

        return $deletionId;
    }

    private function provider(SocialPost $socialPost): ?SocialProvider
    {
        $provider = $socialPost->getRawOriginal('provider');

        return is_string($provider) ? SocialProvider::tryFrom($provider) : null;
    }

    /** @throws JsonException */
    private function encryptedCredentials(SocialAccount $account, string $accessToken): string
    {
        return Crypt::encryptString(json_encode([
            'access_token' => $accessToken,
            'server_url' => $account->server_url,
            'metadata' => Arr::only(
                is_array($account->metadata) ? $account->metadata : [],
                ['page_id'],
            ),
        ], JSON_THROW_ON_ERROR));
    }
}
