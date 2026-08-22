<?php

namespace App\Services;

use App\Contracts\HostnameResolver;

class NativeHostnameResolver implements HostnameResolver
{
    /** @return list<string> */
    public function resolve(string $hostname): array
    {
        $records = dns_get_record($hostname, DNS_A | DNS_AAAA);

        if ($records === false) {
            return [];
        }

        return collect($records)
            ->map(fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
