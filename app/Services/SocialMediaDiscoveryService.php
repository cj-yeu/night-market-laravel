<?php

namespace App\Services;

use App\Models\Food;
use App\Models\NightMarket;
use App\Models\Stall;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class SocialMediaDiscoveryService
{
    /**
     * @return Collection<int, NightMarket>
     */
    public function activeMarketsWithoutActiveStalls(): Collection
    {
        return NightMarket::query()
            ->publiclyVisible()
            ->whereDoesntHave('stalls', fn (Builder $query) => $query
                ->where('status', Stall::STATUS_ACTIVE))
            ->withCount([
                'stalls as active_stalls_count' => fn (Builder $query) => $query
                    ->where('status', Stall::STATUS_ACTIVE),
            ])
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'city', 'state', 'status']);
    }

    /**
     * @return Collection<int, Stall>
     */
    public function activeStallsWithoutActiveFoods(): Collection
    {
        return Stall::query()
            ->where('status', Stall::STATUS_ACTIVE)
            ->whereHas('nightMarket', fn (Builder $query) => $query->publiclyVisible())
            ->whereDoesntHave('foods', fn (Builder $query) => $query
                ->where('status', Food::STATUS_ACTIVE))
            ->with([
                'nightMarket:id,name,city,state,status',
            ])
            ->withCount([
                'foods as active_foods_count' => fn (Builder $query) => $query
                    ->where('status', Food::STATUS_ACTIVE),
            ])
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'night_market_id', 'name', 'status']);
    }
}
