<?php

namespace App\Services\ShareLinks;

use RuntimeException;

class InvalidShareLink extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This share link is unavailable.');
    }
}
