<?php

namespace App\Services;

use App\Models\CatalogCategory;
use App\Models\User;
use App\Support\CatalogCategory as CategoryLabel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class CatalogCategoryService
{
    /** @return Collection<int, CatalogCategory> */
    public function activeForType(string $type): Collection
    {
        $this->assertType($type);

        $categories = CatalogCategory::query()->forType($type)->orderBy('name')->get();
        $blockedNames = $categories->where('is_active', false)
            ->map(fn ($category) => CategoryLabel::key($category->name));

        return $categories->where('is_active', true)
            ->reject(fn ($category) => $blockedNames->contains(CategoryLabel::key(CategoryLabel::canonical($category->name, $type))))
            ->each(fn (CatalogCategory $category) => $category->setAttribute('name', CategoryLabel::canonical($category->name, $type)))
            ->unique('name')->values();
    }

    public function isPermittedSelection(string $type, ?string $category, ?string $legacyCategory = null): bool
    {
        $this->assertType($type);
        $normalized = $this->normalize($category);

        if ($normalized === null) {
            return true;
        }

        return $this->permittedCategory($type, $category, $legacyCategory) !== false;
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
        if ($this->normalize($category) === null) {
            return null;
        }
        $resolved = $this->permittedCategory($type, $category, $legacyCategory);
        if ($resolved === false) {
            throw ValidationException::withMessages([
                'category' => 'Choose an active '.$type.' category or add a new one.',
            ]);
        }

        return $resolved;
    }

    private function permittedCategory(string $type, ?string $category, ?string $legacyCategory): string|false
    {
        $all = CatalogCategory::query()->forType($type)->get();
        $exact = $all->first(fn ($item) => $this->normalize($item->name) === $this->normalize($category));
        if ($exact && ! $exact->is_active) {
            return false;
        }
        $label = CategoryLabel::canonical($category, $type);
        $canonicalRecord = $all->first(fn ($item) => $this->normalize($item->name) === $this->normalize($label));
        if ($canonicalRecord && ! $canonicalRecord->is_active) {
            return false;
        }
        $matches = $all->filter(fn ($item) => CategoryLabel::canonical($item->name, $type) === $label);
        $active = $matches->first(fn ($item) => $item->is_active);
        $legacyMatches = filled($legacyCategory) && CategoryLabel::canonical($legacyCategory, $type) === $label;
        $legacyRecord = $all->first(fn ($item) => $this->normalize($item->name) === $this->normalize($legacyCategory));
        if ($legacyMatches && $legacyRecord && ! $legacyRecord->is_active) {
            return false;
        }
        if ($legacyMatches && (! $legacyRecord || $legacyRecord->is_active) && ($matches->isEmpty() || $active)) {
            // Retain the exact original value when only unrelated fields were edited.
            return $legacyCategory;
        }

        return $active ? $label : false;
    }

    private function createOrFind(string $type, string $newCategory, ?User $creator): CatalogCategory
    {
        $name = CategoryLabel::canonical($newCategory, $type);
        $normalized = $this->normalize($name);

        if ($name === null || $normalized === null || preg_match('/[\x00-\x1F\x7F]/u', $newCategory) === 1 || preg_match('/<[^>]*>/', $newCategory) === 1) {
            throw ValidationException::withMessages([
                'new_category' => 'Enter a safe category name without HTML or control characters.',
            ]);
        }

        $existing = CatalogCategory::query()->forType($type)->get();
        $exact = $existing->first(fn ($item) => $this->normalize($item->name) === $this->normalize($newCategory));
        $matches = $existing->filter(fn ($item) => CategoryLabel::canonical($item->name, $type) === $name);
        $canonicalRecord = $existing->first(fn ($item) => $this->normalize($item->name) === $normalized);
        if (($canonicalRecord && ! $canonicalRecord->is_active) || ($exact && ! $exact->is_active) || ($matches->isNotEmpty() && ! $matches->contains(fn ($item) => $item->is_active))) {
            throw ValidationException::withMessages(['new_category' => 'Choose a different active '.$type.' category.']);
        }
        if ($active = $matches->first(fn ($item) => $item->is_active)) {
            return $active;
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
