<?php

namespace App\Services\Social\Exceptions;

class RetryableSocialPublishException extends SocialPublishException
{
    public function __construct(
        string $message,
        public readonly bool $outcomeIsUncertain = false,
    ) {
        parent::__construct($message);
    }

    public function isRetryable(): bool
    {
        return true;
    }
}
