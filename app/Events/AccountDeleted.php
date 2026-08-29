<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class AccountDeleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $formerUserId) {}
}
