<?php

namespace App\Support;

class CatalogCategory
{
    public static function main(?string $category): ?string
    {
        $value = str((string) $category)->squish()->value();
        if ($value === '') {
            return null;
        }

        $main = trim(explode('/', $value, 2)[0]);

        return $main === '' ? null : str($main)->lower()->title()->value();
    }

    public static function key(string $category): string
    {
        return mb_strtolower(trim($category));
    }
}
