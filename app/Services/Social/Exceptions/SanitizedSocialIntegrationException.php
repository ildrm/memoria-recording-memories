<?php

namespace App\Services\Social\Exceptions;

use Illuminate\Support\Facades\Context;
use RuntimeException;

class SanitizedSocialIntegrationException extends RuntimeException
{
    public function __construct(
        public readonly string $operation,
        public readonly string $provider,
        public readonly string $failureClass,
    ) {
        parent::__construct('An unexpected social integration failure occurred.');
    }

    /** @return array<string, string> */
    public function context(): array
    {
        $context = [
            'social_operation' => $this->operation,
            'social_provider' => $this->provider,
            'failure_class' => $this->failureClass,
        ];
        $requestId = Context::get('request_id');
        if (is_string($requestId) && $requestId !== '') {
            $context['request_id'] = $requestId;
        }

        return $context;
    }
}
