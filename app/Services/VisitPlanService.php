<?php

namespace App\Services;

use App\Models\NightMarket;
use App\Models\User;
use App\Models\VisitPlan;
use Illuminate\Database\Eloquent\Collection;

class VisitPlanService
{
    /**
     * @return Collection<int, VisitPlan>
     */
    public function plansForClient(User $user): Collection
    {
        return $user->visitPlans()
            ->with('nightMarket')
            ->orderBy('visit_date')
            ->get();
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
     * @param  array{title: string, night_market_id: int, visit_date: string, notes?: string|null}  $data
     */
    public function createForClient(User $user, array $data): VisitPlan
    {
        return $user->visitPlans()->create([
            'night_market_id' => $data['night_market_id'],
            'title' => $data['title'],
            'visit_date' => $data['visit_date'],
            'notes' => $data['notes'] ?? null,
        ]);
    }
}
