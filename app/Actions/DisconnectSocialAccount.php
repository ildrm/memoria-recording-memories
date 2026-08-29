<?php

namespace App\Actions;

use App\Enums\PublicationTargetStatus;
use App\Enums\SocialPostStatus;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\AuditRecorder;
use App\Services\Social\RemoteSocialPostCleanup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DisconnectSocialAccount
{
    public function __construct(
        private readonly AuditRecorder $auditRecorder,
        private readonly RemoteSocialPostCleanup $remoteSocialPostCleanup,
    ) {}

    public function handle(SocialAccount $account, User $owner): SocialAccount
    {
        Gate::forUser($owner)->authorize('delete', $account);

        return DB::transaction(function () use ($account, $owner): SocialAccount {
            $account = SocialAccount::query()
                ->ownedBy($owner)
                ->lockForUpdate()
                ->findOrFail($account->getKey());

            $account->socialPosts()
                ->where('status', SocialPostStatus::Published)
                ->whereNotNull('remote_post_id')
                ->lazyById()
                ->each(fn (SocialPost $socialPost) => $this->remoteSocialPostCleanup->schedule(
                    $socialPost,
                    'social_account_disconnected',
                ));

            $account->publicationTargets()
                ->whereIn('status', [
                    PublicationTargetStatus::Pending,
                    PublicationTargetStatus::Scheduled,
                    PublicationTargetStatus::Processing,
                ])
                ->update(['status' => PublicationTargetStatus::Cancelled->value]);

            $account->socialPosts()
                ->whereIn('status', [
                    SocialPostStatus::Pending,
                    SocialPostStatus::Scheduled,
                    SocialPostStatus::Processing,
                    SocialPostStatus::Retrying,
                ])
                ->update([
                    'status' => SocialPostStatus::Disconnected->value,
                    'next_retry_at' => null,
                ]);

            $provider = $account->provider;
            $account->forceFill([
                'access_token' => '',
                'refresh_token' => null,
                'revoked_at' => now(),
            ])->save();

            $this->auditRecorder->record(
                event: 'social_account.disconnected',
                actor: $owner,
                auditable: $account,
                metadata: ['provider' => $provider->value],
            );

            return $account;
        });
    }
}
