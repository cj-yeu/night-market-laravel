<?php

namespace App\Services;

use App\Models\Food;
use App\Models\NightMarket;
use App\Models\Stall;

class CatalogSelectionService
{
    /** Validate relationships without narrowing Admin access to historical/inactive records. */
    public function errors(array $input, string $marketField = 'night_market_id'): array
    {
        $marketId = $input[$marketField] ?? null;
        $stallId = $input['stall_id'] ?? null;
        $foodId = $input['food_id'] ?? null;
        $errors = [];
        if ($marketId && filled($input['city'] ?? null)
            && ! NightMarket::query()->whereKey($marketId)->where('city', $input['city'])->exists()) {
            $errors[$marketField] = 'Choose a Night Market in the selected city, or clear the city filter.';
        }
        if ($marketId && $stallId && ! Stall::query()->whereKey($stallId)->where('night_market_id', $marketId)->exists()) {
            $errors['stall_id'] = 'Choose a Stall belonging to the selected Night Market.';
        }
        if ($foodId && ($marketId || $stallId) && ! Food::query()->whereKey($foodId)
            ->when($stallId, fn ($q) => $q->where('stall_id', $stallId))
            ->when($marketId, fn ($q) => $q->whereHas('stall', fn ($q) => $q->where('night_market_id', $marketId)))->exists()) {
            $errors['food_id'] = 'Choose a Food belonging to the selected Stall and Night Market.';
        }

        return $errors;
    }
}
