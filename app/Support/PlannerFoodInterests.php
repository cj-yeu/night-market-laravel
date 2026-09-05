<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class PlannerFoodInterests
{
    // Presentation groups, not catalog categories. Only explicit, meaningful terms
    // match; unfamiliar/composite labels remain available in More choices.
    public const GROUPS = [
        'meals' => ['label' => 'Rice & Meals', 'icon' => 'egg-fried', 'terms' => ['rice', 'meals']],
        'noodles' => ['label' => 'Noodles', 'icon' => 'cup-hot', 'terms' => ['noodles', 'noodle', 'pasta']],
        'snacks' => ['label' => 'Snacks & Bites', 'icon' => 'basket', 'terms' => ['snack', 'snacks', 'finger food', 'roti']],
        'grilled' => ['label' => 'Grilled & Skewers', 'icon' => 'fire', 'terms' => ['grilled', 'grilled food', 'skewers']],
        'desserts' => ['label' => 'Desserts', 'icon' => 'cake2', 'terms' => ['dessert', 'desserts', 'ice cream', 'yogurt', 'sweet treat', 'cake', 'jelly']],
        'drinks' => ['label' => 'Drinks', 'icon' => 'cup-straw', 'terms' => ['beverage', 'drinks', 'drink']],
        'dim_sum' => ['label' => 'Dim Sum & Bao', 'icon' => 'basket2', 'terms' => ['dim sum', 'bao']],
    ];

    public static function options(array $available): array
    {
        $options = [];
        foreach (self::GROUPS as $key => $group) {
            $categories = array_values(array_filter($available, function ($category) use ($group) {
                $parts = array_map('trim', explode('/', CatalogCategory::key($category)));

                return array_intersect($parts, $group['terms']) !== [];
            }));
            if ($categories !== []) {
                $options[$key] = [...$group, 'categories' => $categories];
            }
        }

        return $options;
    }

    public static function resolve(array $interests, array $categories, array $available): array
    {
        $options = self::options($available);
        foreach ($interests as $interest) {
            if (! isset($options[$interest])) {
                throw ValidationException::withMessages([
                    'interests' => 'One of your food interests is no longer available. Review your selections.',
                ]);
            }
            $categories = [...$categories, ...$options[$interest]['categories']];
        }

        return array_values(array_unique(array_map(fn ($value) => CatalogCategory::canonical($value, 'food'), $categories)));
    }
}
