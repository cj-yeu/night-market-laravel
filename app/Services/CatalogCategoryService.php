<?php

namespace App\Services;

use App\Models\CatalogCategory;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class CatalogCategoryService
{
    /** @return Collection<int, CatalogCategory> */
    public function activeForType(string $type): Collection
    {
        $this->assertType($type);

        return CatalogCategory::query()
            ->forType($type)
            ->active()
            ->orderBy('name')
            ->get(['id', 'category_type', 'name', 'normalized_name']);
    }

    public function isPermittedSelection(string $type, ?string $category, ?string $legacyCategory = null): bool
    {
        $this->assertType($type);
        $normalized = $this->normalize($category);

        if ($normalized === null) {
            return true;
        }

        if ($normalized === $this->normalize($legacyCategory)) {
            $registeredLegacyCategory = CatalogCategory::query()
                ->forType($type)
                ->where('normalized_name', $normalized)
                ->first();

            return $registeredLegacyCategory?->is_active ?? true;
        }

        return CatalogCategory::query()
            ->forType($type)
            ->active()
            ->where('normalized_name', $normalized)
            ->exists();
    }

    public function resolveForCatalog(
        string $type,
        ?string $category,
        ?string $newCategory,
        ?string $legacyCategory,
        ?User $creator,
    ): ?string {
        $this->assertType($type);

        if ($this->normalize($newCategory) !== null) {
            return $this->createOrFind($type, (string) $newCategory, $creator)->name;
        }

        $normalized = $this->normalize($category);
        if ($normalized === null) {
            return null;
        }

        if ($normalized === $this->normalize($legacyCategory)) {
            $registeredLegacyCategory = CatalogCategory::query()
                ->forType($type)
                ->where('normalized_name', $normalized)
                ->first();

            if (! $registeredLegacyCategory) {
                return $this->displayName($legacyCategory);
            }

            if ($registeredLegacyCategory->is_active) {
                return $registeredLegacyCategory->name;
            }

            throw ValidationException::withMessages([
                'category' => 'Choose an active '.$type.' category or add a new one.',
            ]);
        }

        $managedCategory = CatalogCategory::query()
            ->forType($type)
            ->active()
            ->where('normalized_name', $normalized)
            ->first();

        if (! $managedCategory) {
            throw ValidationException::withMessages([
                'category' => 'Choose an active '.$type.' category or add a new one.',
            ]);
        }

        return $managedCategory->name;
    }

    private function createOrFind(string $type, string $newCategory, ?User $creator): CatalogCategory
    {
        $name = $this->displayName($newCategory);
        $normalized = $this->normalize($name);

        if ($name === null || $normalized === null || preg_match('/[\x00-\x1F\x7F]/u', $newCategory) === 1 || preg_match('/<[^>]*>/', $newCategory) === 1) {
            throw ValidationException::withMessages([
                'new_category' => 'Enter a safe category name without HTML or control characters.',
            ]);
        }

        try {
            $category = CatalogCategory::query()->firstOrCreate(
                ['category_type' => $type, 'normalized_name' => $normalized],
                ['name' => $name, 'is_active' => true, 'created_by' => $creator?->id],
            );

            if ($category->is_active) {
                return $category;
            }
        } catch (QueryException) {
            $category = CatalogCategory::query()
                ->forType($type)
                ->where('normalized_name', $normalized)
                ->first();

            if ($category?->is_active) {
                return $category;
            }
        }

        throw ValidationException::withMessages([
            'new_category' => 'Choose a different active '.$type.' category.',
        ]);
    }

    private function assertType(string $type): void
    {
        if (! in_array($type, CatalogCategory::TYPES, true)) {
            throw new \InvalidArgumentException('Unsupported catalog category type.');
        }
    }

    private function normalize(?string $value): ?string
    {
        $name = $this->displayName($value);

        return $name === null ? null : mb_strtolower($name);
    }

    private function displayName(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $name = preg_replace('/\s+/u', ' ', trim($value)) ?? '';

        return $name === '' ? null : $name;
    }
}
