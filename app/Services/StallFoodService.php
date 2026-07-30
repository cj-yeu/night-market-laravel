<?php

namespace App\Services;

use App\Models\Food;
use App\Models\NightMarket;
use App\Models\Stall;
use Illuminate\Database\Eloquent\Collection;

class StallFoodService
{
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
