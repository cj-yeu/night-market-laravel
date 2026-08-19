<?php

namespace App\Services;

use App\Models\Food;
use App\Models\NightMarket;
use App\Models\Stall;
use Illuminate\Database\Eloquent\Collection;

class StallFoodService
{
    public function findPubliclyVisibleMarket(int $nightMarketId): NightMarket
    {
        return NightMarket::query()
            ->publiclyVisible()
            ->findOrFail($nightMarketId);
    }

    /**
     * @param  array{search?: string|null, category?: string|null}  $filters
     * @return Collection<int, Stall>
     */
    public function discoverStallsForMarket(NightMarket $nightMarket, array $filters): Collection
    {
        $search = $filters['search'] ?? null;
        $category = $filters['category'] ?? null;

        return Stall::query()
            ->where('night_market_id', $nightMarket->id)
            ->publiclyVisible()
            ->with(['foods' => fn ($query) => $query
                ->publiclyVisible()
                ->orderByDesc('is_must_try')
                ->orderBy('name')])
            ->when($search && $category, fn ($query) => $this
                ->applyCombinedSearchAndCategory($query, $search, $category))
            ->when($search && ! $category, fn ($query) => $this->applyKeywordSearch($query, $search))
            ->when(! $search && $category, fn ($query) => $this->applyCategoryFilter($query, $category))
            ->orderBy('name')
            ->get();
    }

    private function applyKeywordSearch($query, string $search): void
    {
        $query->where(function ($query) use ($search) {
            $query->where('name', 'like', '%'.$search.'%')
                ->orWhere('description', 'like', '%'.$search.'%')
                ->orWhereHas('foods', fn ($query) => $query
                    ->where('status', Food::STATUS_ACTIVE)
                    ->where(function ($query) use ($search) {
                        $query->where('name', 'like', '%'.$search.'%')
                            ->orWhere('description', 'like', '%'.$search.'%');
                    }));
        });
    }

    private function applyCategoryFilter($query, string $category): void
    {
        $query->whereHas('foods', fn ($query) => $query
            ->where('status', Food::STATUS_ACTIVE)
            ->where('category', $category));
    }

    private function applyCombinedSearchAndCategory($query, string $search, string $category): void
    {
        $query->where(function ($query) use ($search, $category) {
            $query->where(function ($query) use ($search, $category) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhere('description', 'like', '%'.$search.'%');
                });
                $this->applyCategoryFilter($query, $category);
            })->orWhereHas('foods', fn ($query) => $query
                ->where('status', Food::STATUS_ACTIVE)
                ->where('category', $category)
                ->where(function ($query) use ($search) {
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhere('description', 'like', '%'.$search.'%');
                }));
        });
    }

    /**
     * @return Collection<int, Food>
     */
    public function activeCategoriesForMarket(NightMarket $nightMarket): Collection
    {
        return Food::query()
            ->where('status', Food::STATUS_ACTIVE)
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->whereHas('stall', fn ($query) => $query
                ->where('night_market_id', $nightMarket->id)
                ->where('status', Stall::STATUS_ACTIVE))
            ->select('category')
            ->distinct()
            ->orderBy('category')
            ->get();
    }

    public function findPubliclyVisibleFood(int $foodId): Food
    {
        return Food::query()
            ->publiclyVisible()
            ->with('stall.nightMarket')
            ->findOrFail($foodId);
    }

    /**
     * @return Collection<int, NightMarket>
     */
    public function activeNightMarkets(): Collection
    {
        return NightMarket::where('status', NightMarket::STATUS_ACTIVE)
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Stall>
     */
    public function activeStalls(): Collection
    {
        return Stall::with('nightMarket')
            ->where('status', Stall::STATUS_ACTIVE)
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createStall(array $data): Stall
    {
        return Stall::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createFood(array $data): Food
    {
        return Food::create($data);
    }
}
