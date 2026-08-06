<?php

namespace App\Services;

use App\Models\Food;
use App\Models\NightMarket;
use App\Models\Stall;
use App\Models\User;
use App\Models\VisitPlan;
use App\Models\VisitPlanItem;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class VisitPlanService
{
    /**
     * @return Collection<int, VisitPlan>
     */
    public function plansForClient(User $user): Collection
    {
        return $user->visitPlans()
            ->with('nightMarket:id,name,city,state')
            ->withCount('items')
            ->orderBy('visit_date')
            ->get();
    }

    /**
     * @return Collection<int, NightMarket>
     */
    public function activeNightMarkets(): Collection
    {
        return NightMarket::query()
            ->where('status', NightMarket::STATUS_ACTIVE)
            ->where('state', 'Selangor')
            ->with(['operatingDays' => fn ($query) => $query->orderBy('id')])
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array{title: string, night_market_id: int, visit_date: string, notes?: string|null}  $data
     */
    public function createForClient(User $user, array $data): VisitPlan
    {
        $nightMarket = $this->eligibleMarket((int) $data['night_market_id']);
        $this->validateOperatingDate($nightMarket, $data['visit_date']);

        return $user->visitPlans()->create([
            'night_market_id' => $nightMarket->id,
            'title' => $data['title'],
            'visit_date' => $data['visit_date'],
            'notes' => $data['notes'] ?? null,
        ]);
    }

    public function findForClient(User $user, int $visitPlanId): VisitPlan
    {
        return VisitPlan::query()
            ->where('user_id', $user->id)
            ->findOrFail($visitPlanId);
    }

    public function planDetailsForClient(User $user, int $visitPlanId): VisitPlan
    {
        return VisitPlan::query()
            ->where('user_id', $user->id)
            ->with(['nightMarket.operatingDays', 'items'])
            ->findOrFail($visitPlanId);
    }

    /**
     * @param  array{title: string, night_market_id: int, visit_date: string, notes?: string|null}  $data
     */
    public function updateForClient(User $user, int $visitPlanId, array $data): VisitPlan
    {
        $visitPlan = $this->findForClient($user, $visitPlanId);
        $nightMarket = $this->eligibleMarket((int) $data['night_market_id']);
        $this->validateOperatingDate($nightMarket, $data['visit_date']);

        if ($visitPlan->night_market_id !== $nightMarket->id && $visitPlan->items()->exists()) {
            throw ValidationException::withMessages([
                'night_market_id' => 'Remove all plan items before changing the night market.',
            ]);
        }

        $visitPlan->update([
            'night_market_id' => $nightMarket->id,
            'title' => $data['title'],
            'visit_date' => $data['visit_date'],
            'notes' => $data['notes'] ?? null,
        ]);

        return $visitPlan->refresh();
    }

    public function deleteForClient(User $user, int $visitPlanId): void
    {
        $this->findForClient($user, $visitPlanId)->delete();
    }

    /**
     * @param  array{item_type: string, item_id: int, notes?: string|null}  $data
     */
    public function addItemForClient(User $user, int $visitPlanId, array $data): VisitPlanItem
    {
        $visitPlan = $this->findForClient($user, $visitPlanId);
        $itemName = $this->eligibleItemName(
            $visitPlan,
            $data['item_type'],
            (int) $data['item_id'],
        );

        return $visitPlan->items()->create([
            'item_type' => $data['item_type'],
            'item_name' => $itemName,
            'notes' => $data['notes'] ?? null,
            'sort_order' => ((int) $visitPlan->items()->max('sort_order')) + 1,
        ]);
    }

    public function removeItemForClient(User $user, int $visitPlanId, int $visitPlanItemId): void
    {
        $visitPlan = $this->findForClient($user, $visitPlanId);

        $visitPlan->items()->findOrFail($visitPlanItemId)->delete();
    }

    /**
     * @return Collection<int, Stall>
     */
    public function eligibleStallsForPlan(VisitPlan $visitPlan): Collection
    {
        return Stall::query()
            ->where('night_market_id', $visitPlan->night_market_id)
            ->where('status', Stall::STATUS_ACTIVE)
            ->whereHas('nightMarket', fn ($query) => $query
                ->where('status', NightMarket::STATUS_ACTIVE)
                ->where('state', 'Selangor'))
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Food>
     */
    public function eligibleFoodsForPlan(VisitPlan $visitPlan): Collection
    {
        return Food::query()
            ->where('status', Food::STATUS_ACTIVE)
            ->whereHas('stall', fn ($query) => $query
                ->where('night_market_id', $visitPlan->night_market_id)
                ->where('status', Stall::STATUS_ACTIVE))
            ->whereHas('stall.nightMarket', fn ($query) => $query
                ->where('status', NightMarket::STATUS_ACTIVE)
                ->where('state', 'Selangor'))
            ->with('stall:id,name')
            ->orderBy('name')
            ->get();
    }

    private function eligibleMarket(int $nightMarketId): NightMarket
    {
        $nightMarket = NightMarket::query()
            ->whereKey($nightMarketId)
            ->where('status', NightMarket::STATUS_ACTIVE)
            ->where('state', 'Selangor')
            ->with('operatingDays')
            ->first();

        if (! $nightMarket) {
            throw ValidationException::withMessages([
                'night_market_id' => 'The selected night market must be active and located in Selangor.',
            ]);
        }

        return $nightMarket;
    }

    private function validateOperatingDate(NightMarket $nightMarket, string $visitDate): void
    {
        $dayOfWeek = Carbon::parse($visitDate)->englishDayOfWeek;

        if (! $nightMarket->operatingDays->contains('day_of_week', $dayOfWeek)) {
            throw ValidationException::withMessages([
                'visit_date' => "The selected night market does not operate on {$dayOfWeek}. Choose one of its operating days.",
            ]);
        }
    }

    private function eligibleItemName(VisitPlan $visitPlan, string $itemType, int $itemId): string
    {
        if ($itemType === 'stall') {
            return Stall::query()
                ->whereKey($itemId)
                ->where('night_market_id', $visitPlan->night_market_id)
                ->where('status', Stall::STATUS_ACTIVE)
                ->whereHas('nightMarket', fn ($query) => $query
                    ->where('status', NightMarket::STATUS_ACTIVE)
                    ->where('state', 'Selangor'))
                ->value('name')
                ?? throw ValidationException::withMessages([
                    'item_id' => 'The selected stall is not available for this night market.',
                ]);
        }

        return Food::query()
            ->whereKey($itemId)
            ->where('status', Food::STATUS_ACTIVE)
            ->whereHas('stall', fn ($query) => $query
                ->where('night_market_id', $visitPlan->night_market_id)
                ->where('status', Stall::STATUS_ACTIVE))
            ->whereHas('stall.nightMarket', fn ($query) => $query
                ->where('status', NightMarket::STATUS_ACTIVE)
                ->where('state', 'Selangor'))
            ->value('name')
            ?? throw ValidationException::withMessages([
                'item_id' => 'The selected food is not available for this night market.',
            ]);
    }
}
