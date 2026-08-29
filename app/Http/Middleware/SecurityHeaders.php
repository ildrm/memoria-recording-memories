<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        if (! $response->headers->has('Referrer-Policy')) {
            $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }

        $permissionsPolicy = config('memoria.security_headers.permissions_policy');
        if (is_string($permissionsPolicy) && $permissionsPolicy !== '') {
            $response->headers->set('Permissions-Policy', $permissionsPolicy);
        }

        $contentSecurityPolicy = config(
            $request->is('app', 'app/*', 'admin', 'admin/*')
                ? 'memoria.security_headers.panel_content_security_policy'
                : 'memoria.security_headers.content_security_policy',
        );
        if (is_string($contentSecurityPolicy)
            && $contentSecurityPolicy !== ''
            && ! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', $contentSecurityPolicy);
        }

        if (app()->isProduction() && $request->isSecure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains',
            );
        }

        if ($request->attributes->getBoolean('memoria.noindex')
            && ! $response->headers->has('X-Robots-Tag')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        }

        return $response;
    }
}
