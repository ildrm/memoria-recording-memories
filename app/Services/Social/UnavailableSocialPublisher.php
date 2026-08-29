<?php

namespace App\Services\Social;

use App\Contracts\SocialPublisherContract;
use App\Enums\SocialProvider;
use App\Models\Publication;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Services\Social\Exceptions\PermanentSocialPublishException;

class UnavailableSocialPublisher implements SocialPublisherContract
{
    public function supports(SocialProvider $provider): bool
    {
        return true;
    }

    public function supportsIdempotentPublish(): bool
    {
        return false;
    }

    public function publish(
        SocialAccount $account,
        SocialPost $socialPost,
        Publication $publication,
        string $idempotencyKey,
    ): SocialPublishResult {
        throw new PermanentSocialPublishException(
            'External social publishing is disabled until a provider adapter is configured.',
        );
    }

    public function delete(SocialAccount $account, SocialPost $socialPost): void
    {
        throw new PermanentSocialPublishException(
            'External social deletion is disabled until a provider adapter is configured.',
        );
    }
}
