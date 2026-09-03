<?php

namespace App\Support;

class SelangorCities
{
    /** @var array<int, string> */
    public const CANONICAL = [
        'Ampang', 'Bangi', 'Banting', 'Batu Caves', 'Cyberjaya', 'Gombak', 'Hulu Langat', 'Kajang', 'Klang',
        'Kuala Kubu Bharu', 'Kuala Selangor', 'Petaling', 'Petaling Jaya', 'Puchong', 'Rawang',
        'Sabak Bernam', 'Selayang', 'Semenyih', 'Sepang', 'Seri Kembangan',
        'Shah Alam', 'Subang Jaya',
    ];

    /** @return array<int, string> */
    public static function withExisting(iterable $cities): array
    {
        $values = [...self::CANONICAL];
        foreach ($cities as $city) {
            $value = trim((string) (is_object($city) ? $city->city : $city));
            if ($value !== '') {
                $values[] = $value;
            }
        }
        sort($values, SORT_NATURAL | SORT_FLAG_CASE);

        return array_values(array_unique($values));
    }

    public static function normalize(string $city): string
    {
        return str($city)->squish()->value();
    }
}
