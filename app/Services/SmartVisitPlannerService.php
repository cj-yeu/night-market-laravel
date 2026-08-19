<?php

namespace App\Services;

use App\Contracts\RecommendationExplanationProvider;
use App\Models\Food;
use App\Models\NightMarket;
use App\Models\Stall;
use App\Models\User;
use App\Models\VisitPlan;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SmartVisitPlannerService
{
    public function __construct(
        private readonly RecommendationExplanationProvider $explanationProvider,
        private readonly VisitPlanService $visitPlanService,
    ) {}

    /** @return array{cities: Collection<int, NightMarket>, markets: Collection<int, NightMarket>, categories: Collection<int, Food>, halalOptions: array<string, string>} */
    public function preferenceOptions(): array
    {
        return [
            'cities' => NightMarket::query()
                ->publiclyVisible()
                ->select('city')
                ->whereNotNull('city')
                ->where('city', '<>', '')
                ->distinct()
                ->orderBy('city')
                ->get(),
            'markets' => NightMarket::query()
                ->publiclyVisible()
                ->select(['id', 'name', 'city'])
                ->orderBy('name')
                ->orderBy('id')
                ->get(),
            'categories' => Food::query()
                ->publiclyVisible()
                ->select('category')
                ->whereNotNull('category')
                ->where('category', '<>', '')
                ->distinct()
                ->orderBy('category')
                ->get(),
            'halalOptions' => [
                'any' => 'Any classification',
                Stall::HALAL_CERTIFIED => 'Halal-certified',
                Stall::HALAL_MUSLIM_OWNED_OR_CLAIMED => 'Muslim-owned/claimed',
                Stall::HALAL_NON_HALAL => 'Non-halal',
                Stall::HALAL_UNKNOWN => 'Unknown acceptable',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $preferences
     * @return list<array<string, mixed>>
     */
    public function recommend(array $preferences): array
    {
        $visitDate = Carbon::parse($preferences['visit_date']);
        $dayOfWeek = $visitDate->englishDayOfWeek;
        $categories = array_values($preferences['categories'] ?? []);
        $halalPreference = $preferences['halal_preference'];
        $mustTry = (bool) $preferences['must_try'];
        $hasBudget = isset($preferences['budget_min'], $preferences['budget_max']);
        $budgetMin = $hasBudget ? (float) $preferences['budget_min'] : null;
        $budgetMax = $hasBudget ? (float) $preferences['budget_max'] : null;

        $markets = NightMarket::query()
            ->publiclyVisible()
            ->select(['id', 'name', 'city', 'state'])
            ->whereHas('operatingDays', fn (Builder $query) => $query->where('day_of_week', $dayOfWeek))
            ->when($preferences['city'] ?? null, fn (Builder $query, string $city) => $query->where('city', $city))
            ->when($preferences['night_market_id'] ?? null, fn (Builder $query, int $marketId) => $query->whereKey($marketId))
            ->with([
                'operatingDays' => fn ($query) => $query
                    ->select(['id', 'night_market_id', 'day_of_week', 'opening_time', 'closing_time'])
                    ->where('day_of_week', $dayOfWeek)
                    ->orderBy('opening_time'),
                'stalls' => fn ($query) => $query
                    ->select(['id', 'night_market_id', 'name', 'halal_status'])
                    ->where('status', Stall::STATUS_ACTIVE)
                    ->when($halalPreference !== 'any', fn ($query) => $query->where('halal_status', $halalPreference))
                    ->orderBy('name')
                    ->orderBy('id'),
                'stalls.foods' => fn ($query) => $query
                    ->select([
                        'id', 'stall_id', 'name', 'category', 'price_min', 'price_max', 'is_must_try', 'image_path',
                    ])
                    ->where('status', Food::STATUS_ACTIVE)
                    ->when($categories !== [], fn ($query) => $query->whereIn('category', $categories))
                    ->when($mustTry, fn ($query) => $query->where('is_must_try', true))
                    ->when($hasBudget, fn ($query) => $this->applyPossibleBudgetFilter($query, $budgetMin, $budgetMax))
                    ->orderBy('name')
                    ->orderBy('id'),
            ])
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $recommendations = [];

        foreach ($markets as $market) {
            $foodRecommendations = [];

            foreach ($market->stalls as $stall) {
                foreach ($stall->foods as $food) {
                    $foodRecommendations[] = $this->composeFoodRecommendation(
                        $food,
                        $stall,
                        $categories,
                        $halalPreference,
                        $mustTry,
                        $budgetMin,
                        $budgetMax,
                    );
                }
            }

            if ($foodRecommendations === []) {
                continue;
            }

            usort($foodRecommendations, fn (array $left, array $right) => ($right['score'] <=> $left['score'])
                ?: strcasecmp($left['food']->name, $right['food']->name)
                ?: ($left['food']->id <=> $right['food']->id));
            $foodRecommendations = array_slice($foodRecommendations, 0, 6);

            $recommendations[] = $this->composeMarketRecommendation(
                $market,
                $foodRecommendations,
                $preferences,
                $dayOfWeek,
                $budgetMin,
                $budgetMax,
            );
        }

        usort($recommendations, fn (array $left, array $right) => ($right['score'] <=> $left['score'])
            ?: strcasecmp($left['market']->name, $right['market']->name)
            ?: ($left['market']->id <=> $right['market']->id));

        return array_slice($recommendations, 0, (int) $preferences['max_markets']);
    }

    /** @param array<string, mixed> $data */
    public function createPlanForClient(User $user, array $data): VisitPlan
    {
        $recommendation = collect($this->recommend($data))
            ->first(fn (array $result) => $result['market']->id === (int) $data['night_market_id']);

        if (! $recommendation) {
            throw ValidationException::withMessages([
                'night_market_id' => 'This recommendation is no longer available. Generate recommendations again.',
            ]);
        }

        $allowedStallIds = collect($recommendation['stalls'])->pluck('stall.id')->map(fn ($id) => (int) $id);
        $allowedFoodIds = collect($recommendation['foods'])->pluck('food.id')->map(fn ($id) => (int) $id);
        $submittedStallIds = collect($data['stall_ids'])->map(fn ($id) => (int) $id);
        $submittedFoodIds = collect($data['food_ids'])->map(fn ($id) => (int) $id);

        if ($submittedStallIds->diff($allowedStallIds)->isNotEmpty()
            || $submittedFoodIds->diff($allowedFoodIds)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'food_ids' => 'One or more recommended targets are no longer available. Generate recommendations again.',
            ]);
        }

        return DB::transaction(function () use ($user, $data, $submittedStallIds, $submittedFoodIds): VisitPlan {
            $visitPlan = $this->visitPlanService->createForClient($user, [
                'title' => $data['title'],
                'night_market_id' => (int) $data['night_market_id'],
                'visit_date' => $data['visit_date'],
                'notes' => $data['preference_notes'] ?? null,
            ]);

            foreach ($submittedStallIds as $stallId) {
                $this->visitPlanService->addItemForClient($user, $visitPlan->id, [
                    'item_type' => 'stall',
                    'item_id' => $stallId,
                ]);
            }

            foreach ($submittedFoodIds as $foodId) {
                $this->visitPlanService->addItemForClient($user, $visitPlan->id, [
                    'item_type' => 'food',
                    'item_id' => $foodId,
                ]);
            }

            return $visitPlan;
        });
    }

    private function applyPossibleBudgetFilter(Builder $query, float $minimum, float $maximum): void
    {
        $query->where(function (Builder $query) use ($minimum, $maximum) {
            $query->where(fn (Builder $query) => $query->whereNull('price_min')->whereNull('price_max'))
                ->orWhere(fn (Builder $query) => $query
                    ->whereNotNull('price_min')->whereNotNull('price_max')
                    ->where('price_min', '<=', $maximum)->where('price_max', '>=', $minimum))
                ->orWhere(fn (Builder $query) => $query
                    ->whereNotNull('price_min')->whereNull('price_max')
                    ->where('price_min', '<=', $maximum))
                ->orWhere(fn (Builder $query) => $query
                    ->whereNull('price_min')->whereNotNull('price_max')
                    ->where('price_max', '>=', $minimum));
        });
    }

    /** @param list<string> $categories @return array<string, mixed> */
    private function composeFoodRecommendation(
        Food $food,
        Stall $stall,
        array $categories,
        string $halalPreference,
        bool $mustTry,
        ?float $budgetMin,
        ?float $budgetMax,
    ): array {
        $score = 0;
        $factors = [];

        if ($categories !== []) {
            $score += 10;
            $factors[] = 'matches the selected '.$food->category.' category';
        }

        if ($halalPreference !== 'any') {
            $score += 8;
            $factors[] = 'uses the exact '.$stall->halalPublicLabel().' Stall classification';
        }

        if ($mustTry && $food->is_must_try) {
            $score += 6;
            $factors[] = 'is marked Must-Try';
        }

        $hasCompletePrice = $food->price_min !== null && $food->price_max !== null;
        $withinBudget = $hasCompletePrice
            && $budgetMin !== null && $budgetMax !== null
            && (float) $food->price_min >= $budgetMin
            && (float) $food->price_max <= $budgetMax;

        if ($withinBudget) {
            $score += 8;
            $factors[] = 'its stored numeric price range fits the selected budget';
        } elseif ($hasCompletePrice) {
            $score += 1;
            $factors[] = 'has a stored numeric price range';
        } else {
            $factors[] = 'does not have a complete numeric price range';
        }

        return [
            'food' => $food,
            'stall' => $stall,
            'score' => $score,
            'within_budget' => $withinBudget,
            'has_complete_price' => $hasCompletePrice,
            'price_label' => $this->numericPriceLabel($food),
            'explanation' => $this->explanationProvider->explain($factors),
        ];
    }

    private function numericPriceLabel(Food $food): string
    {
        if ($food->price_min !== null && $food->price_max !== null) {
            if ((float) $food->price_min === (float) $food->price_max) {
                return 'RM '.number_format((float) $food->price_min, 2);
            }

            return 'RM '.number_format((float) $food->price_min, 2)
                .'–RM '.number_format((float) $food->price_max, 2);
        }

        if ($food->price_min !== null) {
            return 'From RM '.number_format((float) $food->price_min, 2);
        }

        if ($food->price_max !== null) {
            return 'Up to RM '.number_format((float) $food->price_max, 2);
        }

        return 'Price not available';
    }

    /**
     * @param  list<array<string, mixed>>  $foods
     * @param  array<string, mixed>  $preferences
     * @return array<string, mixed>
     */
    private function composeMarketRecommendation(
        NightMarket $market,
        array $foods,
        array $preferences,
        string $dayOfWeek,
        ?float $budgetMin,
        ?float $budgetMax,
    ): array {
        $score = 100 + array_sum(array_column($foods, 'score'));
        $factors = ["operates on {$dayOfWeek}"];

        if ($preferences['city'] ?? null) {
            $score += 20;
            $factors[] = 'matches '.$market->city;
        }

        if ($preferences['night_market_id'] ?? null) {
            $score += 30;
            $factors[] = 'matches the selected Night Market';
        }

        if (($preferences['categories'] ?? []) !== []) {
            $factors[] = 'includes Foods in the selected categories';
        }

        if ($preferences['halal_preference'] !== 'any') {
            $factors[] = 'uses the requested Stall Halal classification';
        }

        if ($preferences['must_try']) {
            $factors[] = 'includes Must-Try Foods';
        }

        $withinBudgetCount = count(array_filter($foods, fn (array $food) => $food['within_budget']));
        if ($budgetMin !== null && $budgetMax !== null && $withinBudgetCount > 0) {
            $foodLabel = $withinBudgetCount === 1 ? 'Food' : 'Foods';
            $factors[] = "includes {$withinBudgetCount} {$foodLabel} with complete numeric ranges inside RM".
                number_format($budgetMin, 2).'–RM'.number_format($budgetMax, 2);
        }

        $completePriceFoods = array_filter($foods, fn (array $food) => $food['has_complete_price']);
        $estimatedMinimum = array_sum(array_map(fn (array $food) => (float) $food['food']->price_min, $completePriceFoods));
        $estimatedMaximum = array_sum(array_map(fn (array $food) => (float) $food['food']->price_max, $completePriceFoods));
        $unknownPriceCount = count($foods) - count($completePriceFoods);
        $stalls = collect($foods)
            ->unique(fn (array $food) => $food['stall']->id)
            ->map(fn (array $food) => ['stall' => $food['stall']])
            ->values()
            ->all();

        return [
            'market' => $market,
            'score' => $score,
            'explanation' => $this->explanationProvider->explain($factors),
            'stalls' => $stalls,
            'foods' => $foods,
            'estimated_price_label' => $completePriceFoods === []
                ? 'Not available'
                : ($estimatedMinimum === $estimatedMaximum
                    ? 'RM'.number_format($estimatedMinimum, 2)
                    : 'RM'.number_format($estimatedMinimum, 2).'–RM'.number_format($estimatedMaximum, 2)),
            'unknown_price_count' => $unknownPriceCount,
        ];
    }
}
