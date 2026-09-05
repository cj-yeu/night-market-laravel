<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

class CatalogCategory
{
    // Explicit equivalences only. Composite or unfamiliar categories retain their meaning.
    public const ALIASES = [
        'stall' => [
            'beverage / drink' => 'Beverage',
            'dessert stall' => 'Dessert',
            'malay' => 'Malay Food',
        ],
        'food' => [
            'beverage / drink' => 'Beverage',
            'main dish' => 'Main',
        ],
    ];

    public static function canonical(?string $category, string $type): ?string
    {
        $value = str((string) $category)->squish()->value();
        if ($value === '') {
            return null;
        }

        return self::ALIASES[$type][self::key($value)] ?? str($value)->lower()->title()->value();
    }

    public static function applyFilter(Builder $query, string $category, string $type): void
    {
        // Compare the actual legacy values, including repeated whitespace, without rewriting rows.
        $model = $query->getModel();
        $values = $model->newQuery()->whereNotNull('category')->distinct()->pluck('category')
            ->filter(fn ($value) => self::canonical($value, $type) === self::canonical($category, $type))->all();
        $query->whereIn($model->qualifyColumn('category'), $values);
    }

    public static function main(?string $category): ?string
    {
        return self::canonical($category, 'stall');
    }

    public static function key(string $category): string
    {
        return str($category)->squish()->lower()->value();
    }
}
