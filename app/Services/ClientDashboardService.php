<?php

namespace App\Services;

use App\Models\User;
use App\Models\VisitPlan;

class ClientDashboardService
{
    /** @return array{upcomingPlanCount: int, nearestUpcomingPlan: VisitPlan|null} */
    public function summaryFor(User $user): array
    {
        $upcoming = $user->visitPlans()
            ->whereDate('visit_date', '>=', now()->toDateString());

        return [
            'upcomingPlanCount' => (clone $upcoming)->count(),
            'nearestUpcomingPlan' => $upcoming
                ->select(['id', 'user_id', 'night_market_id', 'title', 'visit_date'])
                ->with('nightMarket:id,name,city,state,status')
                ->orderBy('visit_date')
                ->orderBy('id')
                ->first(),
        ];
    }
}
