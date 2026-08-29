<?php

namespace App\Services\Social;

use App\Contracts\SocialPublisherContract;
use App\Enums\SocialProvider;
use App\Models\Publication;
use App\Models\SocialAccount;
use App\Models\SocialPost;

class FakeSocialPublisher implements SocialPublisherContract
{
    public function supports(SocialProvider $provider): bool
    {
        return true;
    }

    public function supportsIdempotentPublish(): bool
    {
        return true;
    }

    public function publish(
        SocialAccount $account,
        SocialPost $socialPost,
        Publication $publication,
        string $idempotencyKey,
    ): SocialPublishResult {
        $provider = $account->provider instanceof SocialProvider
            ? $account->provider->value
            : (string) $account->provider;
        $remoteId = substr(hash('sha256', $provider.'|'.$idempotencyKey), 0, 24);

        return new SocialPublishResult(
            remoteId: $remoteId,
            remoteUrl: "https://social.invalid/{$provider}/{$remoteId}",
        );
    }

    public function delete(SocialAccount $account, SocialPost $socialPost): void
    {
        // The deterministic local provider has no external state to remove.
    }
}
