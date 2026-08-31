<?php

namespace App\Services;

final class ReviewedPublicUrl
{
    public function canonicalize(mixed $url, bool $allowDocumentPath = false): ?string
    {
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            return null;
        }

        $host = strtolower(rtrim(trim($parts['host'], '[]'), '.'));

        if (! $this->hostIsPublic($host)) {
            return null;
        }

        $path = $this->canonicalPath((string) ($parts['path'] ?? ''));

        if ($path === null || (! $allowDocumentPath && $path !== '/')) {
            return null;
        }

        $port = $parts['port'] ?? 443;

        if (! is_int($port) || $port < 1 || $port > 65535) {
            return null;
        }

        $authority = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            ? "[{$host}]"
            : $host;

        return 'https://'.$authority.($port === 443 ? '' : ":{$port}").$path;
    }

    public function isValid(mixed $url, bool $allowDocumentPath = false): bool
    {
        return $this->canonicalize($url, $allowDocumentPath) !== null;
    }

    public function areEquivalent(mixed $first, mixed $second, bool $allowDocumentPath = false): bool
    {
        $canonicalFirst = $this->canonicalize($first, $allowDocumentPath);
        $canonicalSecond = $this->canonicalize($second, $allowDocumentPath);

        return $canonicalFirst !== null && $canonicalFirst === $canonicalSecond;
    }

    private function hostIsPublic(string $host): bool
    {
        if ($host === ''
            || in_array($host, ['localhost', 'example.com', 'example.org', 'example.net'], true)
            || str_ends_with($host, '.example.com')
            || str_ends_with($host, '.example.org')
            || str_ends_with($host, '.example.net')
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.example')
            || str_ends_with($host, '.invalid')
        ) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) !== false;
        }

        return filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    private function canonicalPath(string $path): ?string
    {
        $path = $path === '' ? '/' : $path;

        if (str_contains($path, '\\') || preg_match('/%(?![0-9a-f]{2})/i', $path) === 1) {
            return null;
        }

        $path = preg_replace_callback(
            '/%([0-9a-f]{2})/i',
            static function (array $matches): string {
                $character = chr((int) hexdec($matches[1]));

                return preg_match('/^[A-Za-z0-9._~-]$/', $character) === 1
                    ? $character
                    : strtoupper($matches[0]);
            },
            $path,
        );

        if (! is_string($path)) {
            return null;
        }

        foreach (explode('/', $path) as $segment) {
            if (in_array($segment, ['.', '..'], true)) {
                return null;
            }
        }

        $path = rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }
}
