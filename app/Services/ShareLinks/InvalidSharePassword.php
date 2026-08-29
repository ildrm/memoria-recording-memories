<?php

namespace App\Services\ShareLinks;

use App\Models\ShareLink;
use RuntimeException;

class InvalidSharePassword extends RuntimeException
{
    public function __construct(public readonly ShareLink $shareLink)
    {
        parent::__construct('The share-link password is incorrect.');
    }
}
