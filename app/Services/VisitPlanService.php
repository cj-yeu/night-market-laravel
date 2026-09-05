<?php

namespace App\Services;

use App\Models\Food;
use App\Models\NightMarket;
use App\Models\Stall;
use App\Models\User;
use App\Models\VisitPlan;
use App\Models\VisitPlanItem;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VisitPlanService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<VisitPlan>
     */
    public function plansForClient(User $user, array $filters = []): LengthAwarePaginator
    {
        $today = now()->toDateString();
        $search = $this->literalLikePattern($filters['search'] ?? null);

        $visitPlans = $user->visitPlans()
            ->select(['id', 'user_id', 'night_market_id', 'title', 'visit_date', 'notes'])
            ->with('nightMarket:id,name,city,state,status')
            ->withCount('items')
            ->when($search, fn ($query, string $pattern) => $query->where(function ($query) use ($pattern) {
                $query->where('title', 'like', $pattern)
                    ->orWhereHas('nightMarket', fn ($query) => $query
                        ->publiclyVisible()
                        ->where('name', 'like', $pattern));
            }))
            ->when(($filters['status'] ?? null) === 'upcoming', fn ($query) => $query->whereDate('visit_date', '>', $today))
            ->when(($filters['status'] ?? null) === 'today', fn ($query) => $query->whereDate('visit_date', $today))
            ->when(($filters['status'] ?? null) === 'past', fn ($query) => $query->whereDate('visit_date', '<', $today))
            ->orderByRaw('CASE WHEN visit_date >= ? THEN 0 ELSE 1 END', [$today])
            ->orderByRaw('CASE WHEN visit_date >= ? THEN visit_date END ASC', [$today])
            ->orderByRaw('CASE WHEN visit_date < ? THEN visit_date END DESC', [$today])
            ->orderBy('id')
            ->paginate(9)
            ->withQueryString();

        $visitPlans->getCollection()->each(fn (VisitPlan $visitPlan) => $this->decoratePlan($visitPlan));

        return $visitPlans;
    }

    /** @return Collection<int, NightMarket> */
    public function activeNightMarkets(): Collection
    {
        return NightMarket::query()
            ->publiclyVisible()
            ->whereHas('operatingDays')
            ->select(['id', 'name', 'city'])
            ->with(['operatingDays' => fn ($query) => $query
                ->select(['id', 'night_market_id', 'day_of_week', 'opening_time', 'closing_time'])
                ->orderBy('id')])
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{type: string, id: int, name: string, context: string, night_market_id: int}|null
     */
    public function planningTargetFromFilters(array $filters): ?array
    {
        if (! isset($filters['item_type'], $filters['item_id'])) {
            return null;
        }

        if ($filters['item_type'] === 'stall') {
            $stall = Stall::query()
                ->publiclyVisible()
                ->select(['id', 'night_market_id', 'name'])
                ->with('nightMarket:id,name')
                ->find($filters['item_id']);

            if (! $stall) {
                throw ValidationException::withMessages(['item_id' => 'The selected stall is no longer available.']);
            }

            return [
                'type' => 'stall',
                'id' => $stall->id,
                'name' => $stall->name,
                'context' => $stall->nightMarket->name,
                'night_market_id' => $stall->night_market_id,
            ];
        }

        $food = Food::query()
            ->publiclyVisible()
            ->select(['id', 'stall_id', 'name'])
            ->with(['stall:id,night_market_id,name', 'stall.nightMarket:id,name'])
            ->find($filters['item_id']);

        if (! $food) {
            throw ValidationException::withMessages(['item_id' => 'The selected Food is no longer available.']);
        }

        return [
            'type' => 'food',
            'id' => $food->id,
            'name' => $food->name,
            'context' => $food->stall->name.' at '.$food->stall->nightMarket->name,
            'night_market_id' => $food->stall->night_market_id,
        ];
    }

    /**
     * @param  array{type: string, id: int, name: string, context: string, night_market_id: int}  $target
     * @return Collection<int, VisitPlan>
     */
    public function compatiblePlansForTarget(User $user, array $target): Collection
    {
        $foreignKey = $target['type'] === 'stall' ? 'stall_id' : 'food_id';

        $plans = $user->visitPlans()
            ->select(['id', 'user_id', 'night_market_id', 'title', 'visit_date'])
            ->where('night_market_id', $target['night_market_id'])
            ->whereDate('visit_date', '>=', now()->toDateString())
            ->withExists(['items as has_target' => fn ($query) => $query->where($foreignKey, $target['id'])])
            ->orderBy('visit_date')
            ->orderBy('id')
            ->get();

        $plans->each(function (VisitPlan $visitPlan): void {
            $visitPlan->setAttribute('visit_status', $this->statusForDate($visitPlan->visit_date));
        });

        return $plans;
    }

    /** @param array{title: string, night_market_id: int, visit_date: string, notes?: string|null} $data */
    public function createForClient(User $user, array $data): VisitPlan
    {
        $this->validateLocation($data);
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
        return VisitPlan::query()->where('user_id', $user->id)->findOrFail($visitPlanId);
    }

    public function planDetailsForClient(User $user, int $visitPlanId): VisitPlan
    {
        $visitPlan = VisitPlan::query()
            ->where('user_id', $user->id)
            ->withCount('items')
            ->with([
                'nightMarket:id,name,city,state,status',
                'nightMarket.operatingDays:id,night_market_id,day_of_week,opening_time,closing_time',
                'items.stall:id,night_market_id,name,status',
                'items.stall.nightMarket:id,state,status',
                'items.food:id,stall_id,name,category,price_min,price_max,price_display,status,is_must_try',
                'items.food.stall:id,night_market_id,name,status',
                'items.food.stall.nightMarket:id,state,status',
            ])
            ->findOrFail($visitPlanId);

        $this->decoratePlan($visitPlan);
        $visitPlan->items->each(fn (VisitPlanItem $item) => $this->decorateItem($visitPlan, $item));

        return $visitPlan;
    }

    /** @param array{title: string, night_market_id: int, visit_date: string, notes?: string|null} $data */
    public function updateForClient(User $user, int $visitPlanId, array $data): VisitPlan
    {
        return DB::transaction(function () use ($user, $visitPlanId, $data): VisitPlan {
            $visitPlan = VisitPlan::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->findOrFail($visitPlanId);
            $this->validateLocation($data);
            $requestedMarketId = (int) $data['night_market_id'];
            $requestedVisitDate = Carbon::parse($data['visit_date'])->toDateString();
            $marketChanged = $visitPlan->night_market_id !== $requestedMarketId;
            $dateChanged = $visitPlan->visit_date->toDateString() !== $requestedVisitDate;

            if ($this->isPastVisitPlan($visitPlan)) {
                $errors = [];

                if ($marketChanged) {
                    $errors['night_market_id'] = 'Past visit plans cannot change the Night Market. You can still update the title or notes.';
                }

                if ($dateChanged) {
                    $errors['visit_date'] = 'Past visit plans cannot change the visit date. You can still update the title or notes.';
                }

                if ($errors) {
                    throw ValidationException::withMessages($errors);
                }

                $visitPlan->update([
                    'title' => $data['title'],
                    'notes' => $data['notes'] ?? null,
                ]);

                return $visitPlan->refresh();
            }

            if (Carbon::parse($requestedVisitDate)->lt(now()->startOfDay())) {
                throw ValidationException::withMessages([
                    'visit_date' => 'Visit plans must use today or a future date.',
                ]);
            }

            if ($marketChanged || $dateChanged) {
                $nightMarket = $this->eligibleMarket($requestedMarketId);
                $this->validateOperatingDate($nightMarket, $requestedVisitDate);
            }

            if ($marketChanged && $visitPlan->items()->exists()) {
                throw ValidationException::withMessages([
                    'night_market_id' => 'Remove all plan items before changing the night market.',
                ]);
            }

            $visitPlan->update([
                'night_market_id' => $requestedMarketId,
                'title' => $data['title'],
                'visit_date' => $requestedVisitDate,
                'notes' => $data['notes'] ?? null,
            ]);

            return $visitPlan->refresh();
        });
    }

    public function deleteForClient(User $user, int $visitPlanId): void
    {
        $this->findForClient($user, $visitPlanId)->delete();
    }

    /** @param array{item_type: string, stall_id?: int, food_id?: int, notes?: string|null} $data */
    public function addItemForClient(User $user, int $visitPlanId, array $data): VisitPlanItem
    {
        return DB::transaction(function () use ($user, $visitPlanId, $data): VisitPlanItem {
            $visitPlan = VisitPlan::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->findOrFail($visitPlanId);
            $this->ensureItemsCanBeChanged($visitPlan);

            $itemType = $data['item_type'];
            $itemId = (int) ($itemType === 'stall' ? ($data['stall_id'] ?? 0) : ($data['food_id'] ?? 0));
            $item = $this->eligibleItem($visitPlan, $itemType, $itemId);
            $foreignKey = $itemType === 'stall' ? 'stall_id' : 'food_id';

            if ($visitPlan->items()->where($foreignKey, $item->id)->exists()) {
                throw $this->duplicateItemException($foreignKey);
            }

            try {
                return $visitPlan->items()->create([
                    'stall_id' => $itemType === 'stall' ? $item->id : null,
                    'food_id' => $itemType === 'food' ? $item->id : null,
                    'item_type' => $itemType,
                    'item_name' => $item->name,
                    'notes' => $data['notes'] ?? null,
                    'sort_order' => ((int) $visitPlan->items()->max('sort_order')) + 1,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw $this->duplicateItemException($foreignKey);
            }
        });
    }

    public function removeItemForClient(User $user, int $visitPlanId, int $visitPlanItemId): void
    {
        DB::transaction(function () use ($user, $visitPlanId, $visitPlanItemId): void {
            $visitPlan = VisitPlan::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->findOrFail($visitPlanId);
            $this->ensureItemsCanBeChanged($visitPlan);
            $visitPlan->items()->lockForUpdate()->findOrFail($visitPlanItemId)->delete();
        });
    }

    /** @return Collection<int, NightMarket> */
    public function editableNightMarketsForPlan(VisitPlan $visitPlan): Collection
    {
        $nightMarkets = $this->activeNightMarkets();

        if (! $nightMarkets->contains('id', $visitPlan->night_market_id)) {
            $currentMarket = NightMarket::query()
                ->select(['id', 'name', 'city'])
                ->with(['operatingDays' => fn ($query) => $query
                    ->select(['id', 'night_market_id', 'day_of_week', 'opening_time', 'closing_time'])
                    ->orderBy('id')])
                ->find($visitPlan->night_market_id);

            if ($currentMarket) {
                $nightMarkets->push($currentMarket);
            }
        }

        return $nightMarkets->sortBy('name')->values();
    }

    /** @return Collection<int, Stall> */
    public function eligibleStallsForPlan(VisitPlan $visitPlan): Collection
    {
        return Stall::query()
            ->publiclyVisible()
            ->select(['id', 'night_market_id', 'name'])
            ->where('night_market_id', $visitPlan->night_market_id)
            ->whereNotIn('id', $visitPlan->items->where('item_type', 'stall')->pluck('stall_id')->filter())
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, Food> */
    public function eligibleFoodsForPlan(VisitPlan $visitPlan): Collection
    {
        return Food::query()
            ->publiclyVisible()
            ->select(['id', 'stall_id', 'name', 'category', 'price_min', 'price_max', 'price_display', 'is_must_try'])
            ->whereHas('stall', fn ($query) => $query->where('night_market_id', $visitPlan->night_market_id))
            ->whereNotIn('id', $visitPlan->items->where('item_type', 'food')->pluck('food_id')->filter())
            ->with('stall:id,name')
            ->orderBy('name')
            ->get();
    }

    private function validateLocation(array $data): void
    {
        $errors = app(CatalogSelectionService::class)->errors($data);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function eligibleMarket(int $nightMarketId): NightMarket
    {
        $nightMarket = NightMarket::query()
            ->publiclyVisible()
            ->with('operatingDays')
            ->find($nightMarketId);

        if (! $nightMarket) {
            throw ValidationException::withMessages([
                'night_market_id' => 'The selected night market must be active and located in Selangor.',
            ]);
        }

        return $nightMarket;
    }

    private function ensureItemsCanBeChanged(VisitPlan $visitPlan): void
    {
        if ($this->isPastVisitPlan($visitPlan)) {
            throw ValidationException::withMessages([
                'item_type' => 'Past visit plans cannot be changed. You can still update the title or notes.',
            ]);
        }
    }

    private function isPastVisitPlan(VisitPlan $visitPlan): bool
    {
        return $visitPlan->visit_date->lt(now()->startOfDay());
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

    private function eligibleItem(VisitPlan $visitPlan, string $itemType, int $itemId): Stall|Food
    {
        if ($itemType === 'stall') {
            return Stall::query()
                ->publiclyVisible()
                ->whereKey($itemId)
                ->where('night_market_id', $visitPlan->night_market_id)
                ->first()
                ?? throw ValidationException::withMessages([
                    'stall_id' => 'The selected stall is not available for this night market.',
                ]);
        }

        return Food::query()
            ->publiclyVisible()
            ->whereKey($itemId)
            ->whereHas('stall', fn ($query) => $query->where('night_market_id', $visitPlan->night_market_id))
            ->first()
            ?? throw ValidationException::withMessages([
                'food_id' => 'The selected Food is not available for this night market.',
            ]);
    }

    private function decoratePlan(VisitPlan $visitPlan): void
    {
        $market = $visitPlan->nightMarket;
        $marketIsAvailable = $market !== null
            && $market->status === NightMarket::STATUS_ACTIVE
            && $market->state === 'Selangor';

        $visitPlan->setAttribute('visit_status', $this->statusForDate($visitPlan->visit_date));
        $visitPlan->setAttribute('market_is_available', $marketIsAvailable);
        $visitPlan->setAttribute('market_display_name', $marketIsAvailable ? $market->name : 'No longer available');
    }

    private function decorateItem(VisitPlan $visitPlan, VisitPlanItem $item): void
    {
        if ($item->item_type === 'stall') {
            $stall = $item->stall;
            $available = $stall !== null
                && $stall->night_market_id === $visitPlan->night_market_id
                && $stall->status === Stall::STATUS_ACTIVE
                && $stall->nightMarket?->status === NightMarket::STATUS_ACTIVE
                && $stall->nightMarket?->state === 'Selangor';
            $displayName = $available ? $stall->name : 'No longer available';
        } else {
            $food = $item->food;
            $available = $food !== null
                && $food->status === Food::STATUS_ACTIVE
                && $food->stall?->night_market_id === $visitPlan->night_market_id
                && $food->stall?->status === Stall::STATUS_ACTIVE
                && $food->stall?->nightMarket?->status === NightMarket::STATUS_ACTIVE
                && $food->stall?->nightMarket?->state === 'Selangor';
            $displayName = $available ? $food->name : 'No longer available';
        }

        $item->setAttribute('is_available', $available);
        $item->setAttribute('display_name', $displayName);
    }

    private function statusForDate(Carbon $visitDate): string
    {
        if ($visitDate->isToday()) {
            return 'Today';
        }

        return $visitDate->isPast() ? 'Past' : 'Upcoming';
    }

    private function literalLikePattern(?string $value): ?string
    {
        return $value ? '%'.addcslashes($value, '\\%_').'%' : null;
    }

    private function duplicateItemException(string $field): ValidationException
    {
        return ValidationException::withMessages([
            $field => 'This item has already been added to the visit plan.',
        ]);
    }
}
