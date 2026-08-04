<?php

namespace App\Services;

use App\Models\Food;
use App\Models\NightMarket;
use App\Models\Stall;
use Illuminate\Database\Eloquent\Collection;

class StallFoodService
{
    public function findActiveMarketForClient(int $nightMarketId): NightMarket
    {
        return NightMarket::query()
            ->where('status', NightMarket::STATUS_ACTIVE)
            ->where('state', 'Selangor')
            ->findOrFail($nightMarketId);
    }

    /**
     * @param  array{search?: string|null, category?: string|null}  $filters
     * @return Collection<int, Stall>
     */
    public function discoverStallsForMarket(NightMarket $nightMarket, array $filters): Collection
    {
        return Stall::query()
            ->where('night_market_id', $nightMarket->id)
            ->where('status', Stall::STATUS_ACTIVE)
            ->with(['foods' => fn ($query) => $query
                ->where('status', Food::STATUS_ACTIVE)
                ->orderByDesc('is_must_try')
                ->orderBy('name')])
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhereHas('foods', fn ($query) => $query
                            ->where('status', Food::STATUS_ACTIVE)
                            ->where('name', 'like', '%'.$search.'%'));
                });
            })
            ->when($filters['category'] ?? null, fn ($query, string $category) => $query
                ->whereHas('foods', fn ($query) => $query
                    ->where('status', Food::STATUS_ACTIVE)
                    ->where('category', $category)))
            ->orderBy('name')
            ->get();
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

    public function findActiveFoodForClient(int $foodId): Food
    {
        return Food::query()
            ->where('status', Food::STATUS_ACTIVE)
            ->whereHas('stall', fn ($query) => $query->where('status', Stall::STATUS_ACTIVE))
            ->whereHas('stall.nightMarket', fn ($query) => $query
                ->where('status', NightMarket::STATUS_ACTIVE)
                ->where('state', 'Selangor'))
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