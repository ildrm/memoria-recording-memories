<?php

namespace App\Services\Social;

class MastodonHostResolver
{
    /**
     * @return array<int, string>|null
     */
    public function resolve(string $host): ?array
    {
        if (! function_exists('dns_get_record')) {
            return null;
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (! is_array($records)) {
            return null;
        }

        $addresses = [];
        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($address)) {
                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }
}
