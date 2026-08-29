<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class PublicationPublished implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $publicationId,
        public readonly int $ownerId,
    ) {}
}
