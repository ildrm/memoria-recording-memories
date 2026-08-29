<?php

$configuredProxies = array_map(
    static fn (string $proxy): string => trim($proxy),
    explode(',', (string) env('TRUSTED_PROXIES', '')),
);

return [
    /*
    |--------------------------------------------------------------------------
    | Trusted reverse proxies
    |--------------------------------------------------------------------------
    |
    | List only proxy or load-balancer addresses/CIDRs controlled by the
    | operator. Forwarded HTTPS and client-IP headers from every other caller
    | remain untrusted.
    |
    */
    'proxies' => array_values(array_filter($configuredProxies)),
];
