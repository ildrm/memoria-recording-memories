<?php

use App\Exceptions\SanitizedDatabaseException;
use App\Http\Middleware\RequestCorrelation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustHosts(
            at: static function (): array {
                $host = parse_url((string) config('app.url'), PHP_URL_HOST);
                $canonicalHost = is_string($host)
                    ? strtolower(rtrim(trim($host, '[]'), '.'))
                    : '';

                return ['^'.preg_quote($canonicalHost).'$'];
            },
            subdomains: false,
        );
        $middleware->prepend(RequestCorrelation::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (QueryException $exception): bool {
            report(new SanitizedDatabaseException($exception->getCode()));

            return false;
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->respond(function (Response $response): Response {
            $requestId = Context::get('request_id');
            if (is_string($requestId) && $requestId !== '') {
                $response->headers->set('X-Request-ID', $requestId);
            }

            return $response;
        });
    })->create();
