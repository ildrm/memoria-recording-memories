<?php

namespace App\Services\Social\Exceptions;

class PermanentSocialPublishException extends SocialPublishException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'provider_rejected',
    ) {
        parent::__construct($message);
    }

    public function isRetryable(): bool
    {
        return false;
    }
}
