<?php

namespace App\Services\Social\Exceptions;

use RuntimeException;

abstract class SocialPublishException extends RuntimeException
{
    abstract public function isRetryable(): bool;
}
