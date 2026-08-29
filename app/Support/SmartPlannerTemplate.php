<?php

namespace App\Support;

final class SmartPlannerTemplate
{
    public const QUICK_VISIT = 'quick_visit';

    public const FOOD_HUNTING = 'food_hunting';

    public const FAMILY_FRIENDLY = 'family_friendly';

    public const BUDGET = 'budget';

    public const KEYS = [
        self::QUICK_VISIT,
        self::FOOD_HUNTING,
        self::FAMILY_FRIENDLY,
        self::BUDGET,
    ];

    /** @return array<string, array{name: string, description: string, limit: string}> */
    public static function cards(): array
    {
        return [
            self::QUICK_VISIT => [
                'name' => '1-Hour Quick Visit',
                'description' => 'A short set of up to three food stops, planned at about 20 minutes per stop.',
                'limit' => 'Does not include queueing, walking, or travel time; one hour is not guaranteed.',
            ],
            self::FOOD_HUNTING => [
                'name' => 'Food Hunting Plan',
                'description' => 'Prioritises Must-Try foods while varying stalls and food categories where data allows.',
                'limit' => 'Up to five food stops; other active foods may fill gaps when Must-Try options are limited.',
            ],
            self::FAMILY_FRIENDLY => [
                'name' => 'Family-Friendly Plan',
                'description' => 'Uses public Family-Friendly review tags when available, otherwise a short and varied fallback.',
                'limit' => 'Does not verify children’s facilities, safety, or accessibility.',
            ],
            self::BUDGET => [
                'name' => 'Budget Visit Plan',
                'description' => 'Uses numeric food prices only and keeps the conservative price-max total within your budget.',
                'limit' => 'Defaults to RM30 and excludes foods without a numeric price maximum.',
            ],
        ];
    }

    public static function isKnown(?string $key): bool
    {
        return in_array($key, self::KEYS, true);
    }
}
