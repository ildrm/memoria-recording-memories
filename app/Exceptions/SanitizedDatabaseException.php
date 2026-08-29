<?php

namespace App\Exceptions;

use RuntimeException;

final class SanitizedDatabaseException extends RuntimeException
{
    public readonly string $errorCode;

    public function __construct(int|string $errorCode)
    {
        $normalizedErrorCode = (string) $errorCode;
        $this->errorCode = preg_match('/\A[A-Za-z0-9_-]{1,16}\z/', $normalizedErrorCode) === 1
            ? $normalizedErrorCode
            : 'unknown';

        parent::__construct('A database operation failed.');
    }

    /** @return array{database_error_code: string} */
    public function context(): array
    {
        return ['database_error_code' => $this->errorCode];
    }
}
