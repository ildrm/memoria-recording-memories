<?php

namespace App\Events;

use DateTimeInterface;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class PublicationScheduled implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $publicationId,
        public readonly int $ownerId,
        public readonly DateTimeInterface $scheduledAt,
    ) {}
}
