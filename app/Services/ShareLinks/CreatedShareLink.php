<?php

namespace App\Services\ShareLinks;

use App\Models\ShareLink;

final readonly class CreatedShareLink
{
    public function __construct(
        public ShareLink $shareLink,
        public string $token,
        public string $url,
    ) {}
}
