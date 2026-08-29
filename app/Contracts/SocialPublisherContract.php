<?php

namespace App\Contracts;

use App\Enums\SocialProvider;
use App\Models\Publication;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Services\Social\SocialPublishResult;

interface SocialPublisherContract
{
    public function supports(SocialProvider $provider): bool;

    public function supportsIdempotentPublish(): bool;

    public function publish(
        SocialAccount $account,
        SocialPost $socialPost,
        Publication $publication,
        string $idempotencyKey,
    ): SocialPublishResult;

    public function delete(SocialAccount $account, SocialPost $socialPost): void;
}
