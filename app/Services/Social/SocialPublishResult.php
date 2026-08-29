<?php

namespace App\Services\Social;

final readonly class SocialPublishResult
{
    public function __construct(
        public string $remoteId,
        public ?string $remoteUrl = null,
    ) {}
}
