<?php

namespace App\Support;

use Illuminate\Support\Str;

final class CatalogMarketIdentity
{
    public const FIELDS = [
        'name',
        'address',
        'city',
        'state',
    ];

    public static function hash(
        ?string $name,
        ?string $address,
        ?string $city,
        ?string $state,
    ): string {
        $normalizedFields = [
            self::normalize($name),
            self::normalize($address),
            self::normalize($city),
            self::normalize($state),
        ];

        $identity = implode('|', array_map(
            static fn (string $value): string => strlen($value).':'.$value,
            $normalizedFields,
        ));

        return hash('sha256', $identity);
    }

    private static function normalize(?string $value): string
    {
        return Str::lower(Str::squish((string) $value));
    }
}
