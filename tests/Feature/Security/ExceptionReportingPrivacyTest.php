<?php

use App\Exceptions\SanitizedDatabaseException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Psr\Log\LoggerInterface;

test('database failures are reported without query text or private bindings', function (): void {
    $privateSentinel = 'PRIVATE DIARY BODY MUST NEVER REACH LOGS';
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('error')
        ->once()
        ->withArgs(function (string $message, array $context) use ($privateSentinel): bool {
            $reported = $context['exception'] ?? null;
            $safeLogPayload = json_encode([
                'message' => $message,
                'context_keys' => array_keys($context),
                'exception_message' => $reported instanceof SanitizedDatabaseException
                    ? $reported->getMessage()
                    : null,
                'exception_context' => $reported instanceof SanitizedDatabaseException
                    ? $reported->context()
                    : null,
            ], JSON_THROW_ON_ERROR);

            return $message === 'A database operation failed.'
                && $reported instanceof SanitizedDatabaseException
                && $reported->getPrevious() === null
                && $reported->errorCode === '23000'
                && ! str_contains($safeLogPayload, $privateSentinel)
                && ! str_contains($safeLogPayload, 'update entries set body');
        });
    app()->instance(LoggerInterface::class, $logger);

    $driverException = new PDOException(
        "Constraint failure containing {$privateSentinel}",
        23000,
    );
    $queryException = new QueryException(
        'sqlite',
        'update entries set body = ? where id = ?',
        [$privateSentinel, 42],
        $driverException,
    );

    app(ExceptionHandler::class)->report($queryException);
});
