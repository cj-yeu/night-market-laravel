<?php

namespace App\Services;

use App\Models\Food;
use App\Models\NightMarket;
use App\Support\CatalogCategory;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class PlannerCandidateService
{
    public function candidates(array $preferences, string $date, ?array $ids = null): Collection
    {
        $day = Carbon::parse($date, 'Asia/Kuala_Lumpur')->englishDayOfWeek;
        $query = Food::query()->publiclyVisible()->with(['stall.nightMarket.operatingDays'])
            ->withExists(['reviews as family_signal' => fn ($q) => $q->publiclyVisible()->whereHas('tags', fn ($tags) => $tags->where('name', 'Family-Friendly'))])
            ->withAvg(['reviews as planner_rating' => fn ($q) => $q->publiclyVisible()], 'rating')
            ->whereHas('stall', function ($q) use ($preferences, $day) {
                $q->publiclyVisible()
                    ->when(($preferences['halal_preference'] ?? 'any') !== 'any', fn ($q) => $q->where('halal_status', $preferences['halal_preference']))
                    ->whereHas('nightMarket', fn ($q) => $q->eligibleForPlanning()
                        ->when($preferences['city'] ?? null, fn ($q, $city) => $q->where('city', $city))
                        ->when($preferences['night_market_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
                        ->whereHas('operatingDays', fn ($q) => $q->where('day_of_week', $day)));
            })
            ->when($preferences['must_try'] ?? false, fn ($q) => $q->where('is_must_try', true));
        if (($preferences['categories'] ?? []) !== []) {
            $categories = array_map(fn ($category) => CatalogCategory::canonical($category, 'food'), $preferences['categories']);
            $legacyValues = Food::query()->whereNotNull('category')->distinct()->pluck('category')
                ->filter(fn ($value) => in_array(CatalogCategory::canonical($value, 'food'), $categories, true))->all();
            $query->whereIn('category', $legacyValues);
        }
        if (isset($preferences['budget_max'])) {
            $query->whereNotNull('price_max')->where('price_max', '>=', 0)->where('price_max', '<=', $preferences['budget_max']);
        }
        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }
        $foods = $query->orderByDesc('is_must_try')->orderBy('id')->limit(60)->get();
        // Public Market review tags are also signals; never send review text or authors.
        $marketSignals = NightMarket::query()->whereIn('id', $foods->pluck('stall.night_market_id'))
            ->whereHas('reviews', fn ($q) => $q->publiclyVisible()->whereHas('tags', fn ($q) => $q->where('name', 'Family-Friendly')))->pluck('id');
        foreach ($foods as $food) {
            $food->setAttribute('family_signal', (bool) $food->family_signal || $marketSignals->contains($food->stall->night_market_id));
        }

        return $foods->keyBy('id');
    }

    public function facts(Collection $foods): array
    {
        return $foods->map(fn (Food $food) => [
            'food_id' => $food->id, 'stall_id' => $food->stall_id, 'market_id' => $food->stall->night_market_id,
            'category' => CatalogCategory::canonical($food->category, 'food'),
            'price_min' => $food->price_min, 'price_max' => $food->price_max,
            'must_try' => $food->is_must_try, 'halal' => $food->stall->halal_status,
            'family_signal' => (bool) $food->family_signal, 'rating' => $food->planner_rating === null ? null : round((float) $food->planner_rating, 2),
        ])->values()->all();
    }

    public function version(Collection $foods): string
    {
        return hash('sha256', json_encode([$this->facts($foods), $foods->map(fn ($food) => [
            $food->name, $food->updated_at, $food->stall->name, $food->stall->updated_at,
            $food->stall->nightMarket->name, $food->stall->nightMarket->updated_at,
            $food->stall->nightMarket->operatingDays->map(fn ($day) => [$day->day_of_week, $day->opening_time?->format('H:i'), $day->closing_time?->format('H:i')])->all(),
        ])->values()->all()], JSON_THROW_ON_ERROR));
    }

    public function limit(array $preferences): int
    {
        return in_array($preferences['template'] ?? null, ['quick_visit', 'family_friendly'], true) ? 3 : 5;
    }

    public function validateSelection(array $ids, int $marketId, Collection $candidates, array $preferences): Collection
    {
        if ($ids === [] || count($ids) > $this->limit($preferences) || count($ids) !== count(array_unique($ids))) {
            $this->invalid();
        }
        $selected = collect();
        foreach ($ids as $id) {
            $food = $candidates->get($id);
            if (! $food || (int) $food->stall->night_market_id !== $marketId) {
                $this->invalid();
            }
            $selected->push($food);
        }
        $cost = $this->cost($selected);
        if (isset($preferences['budget_max']) && ($cost['unknown'] > 0 || $cost['max_cents'] > $this->cents($preferences['budget_max']))) {
            $this->invalid();
        }

        return $selected;
    }

    public function cost(Collection $foods): array
    {
        $unknown = $foods->filter(fn ($food) => $food->price_max === null || (float) $food->price_max < 0
            || ($food->price_min !== null && ((float) $food->price_min < 0 || (float) $food->price_min > (float) $food->price_max)))->count();
        $min = $foods->sum(fn ($food) => $food->price_min !== null ? $this->cents($food->price_min) : 0);
        $max = $foods->sum(fn ($food) => $food->price_max !== null ? $this->cents($food->price_max) : 0);
        $complete = $unknown === 0 && $foods->every(fn ($food) => $food->price_min !== null);

        return ['unknown' => $unknown, 'min_cents' => $min, 'max_cents' => $max,
            'label' => $unknown > 0 ? 'Total unavailable — some prices are unknown'
                : (! $complete ? 'Up to RM'.number_format($max / 100, 2)
                    : 'RM'.number_format($min / 100, 2).($min === $max ? '' : '–RM'.number_format($max / 100, 2)))];
    }

    public function cents(mixed $value): int
    {
        return (int) round((float) $value * 100);
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['food_ids' => 'These food stops no longer meet your preferences or food budget. Generate a new recommendation.']);
    }
}
