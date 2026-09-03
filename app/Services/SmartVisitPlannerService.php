<?php

namespace App\Services;

use App\Contracts\RecommendationExplanationProvider;
use App\Models\Food;
use App\Models\NightMarket;
use App\Models\Review;
use App\Models\Stall;
use App\Models\User;
use App\Models\VisitPlan;
use App\Support\SmartPlannerTemplate;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SmartVisitPlannerService
{
    private const FALLBACK_DAYS = 14;

    public function __construct(
        private readonly RecommendationExplanationProvider $explanationProvider,
        private readonly VisitPlanService $visitPlanService,
    ) {}

    /** @return array{cities: Collection<int, NightMarket>, markets: Collection<int, NightMarket>, categories: Collection<int, Food>, halalOptions: array<string, string>, templates: array<string, array{name: string, description: string, limit: string}>} */
    public function preferenceOptions(): array
    {
        return [
            'cities' => NightMarket::query()
                ->eligibleForPlanning()
                ->select('city')
                ->whereNotNull('city')
                ->where('city', '<>', '')
                ->distinct()
                ->orderBy('city')
                ->get(),
            'markets' => NightMarket::query()
                ->eligibleForPlanning()
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
            'templates' => SmartPlannerTemplate::cards(),
        ];
    }

    /** @return array<string, mixed> */
    public function templateDefaults(?string $template, string $visitDate): array
    {
        $preferences = [
            'visit_date' => $visitDate,
            'halal_preference' => 'any',
            'must_try' => false,
            'max_markets' => 1,
        ];

        if (! SmartPlannerTemplate::isKnown($template)) {
            return $preferences;
        }

        $preferences['template'] = $template;

        if ($template === SmartPlannerTemplate::BUDGET) {
            $preferences['budget_min'] = 0;
            $preferences['budget_max'] = 30;
        }

        return $preferences;
    }

    /** @param array<string, mixed> $preferences @return array<string, mixed> */
    public function normaliseTemplatePreferences(array $preferences): array
    {
        $template = $preferences['template'] ?? null;

        if (! SmartPlannerTemplate::isKnown($template)) {
            return $preferences;
        }

        $preferences['max_markets'] = 1;

        if ($template === SmartPlannerTemplate::BUDGET) {
            $preferences['budget_min'] = array_key_exists('budget_min', $preferences) ? $preferences['budget_min'] : 0;
            $preferences['budget_max'] = array_key_exists('budget_max', $preferences) ? $preferences['budget_max'] : 30;
        }

        return $preferences;
    }

    public function defaultVisitDate(): string
    {
        $operatingDays = NightMarket::query()
            ->eligibleForPlanning()
            ->with('operatingDays:id,night_market_id,day_of_week')
            ->get(['id'])
            ->flatMap->operatingDays
            ->pluck('day_of_week')
            ->unique();

        $today = Carbon::today();

        for ($offset = 0; $offset <= self::FALLBACK_DAYS; $offset++) {
            $candidate = $today->copy()->addDays($offset);

            if ($operatingDays->contains($candidate->englishDayOfWeek)) {
                return $candidate->toDateString();
            }
        }

        return $today->toDateString();
    }

    /**
     * Retained as the exact-date recommendation API used by existing callers and query regression coverage.
     *
     * @param  array<string, mixed>  $preferences
     * @return list<array<string, mixed>>
     */
    public function recommend(array $preferences): array
    {
        $preferences = $this->normaliseTemplatePreferences($preferences);

        return $this->evaluateDate($preferences, Carbon::parse($preferences['visit_date']))['recommendations'];
    }

    /**
     * @param  array<string, mixed>  $preferences
     * @return array<string, mixed>
     */
    public function recommendDateAware(array $preferences): array
    {
        $preferences = $this->normaliseTemplatePreferences($preferences);
        $requestedDate = Carbon::parse($preferences['visit_date'])->startOfDay();
        $requestedResult = $this->evaluateDate($preferences, $requestedDate);

        if ($requestedResult['recommendations'] !== []) {
            return $this->withTemplateMetadata($this->dateAwareResult(
                $requestedDate,
                $requestedDate,
                $requestedResult['recommendations'],
                null,
                null,
                false,
            ), $preferences);
        }

        /** @var array<string, array{recommendations: list<array<string, mixed>>, reason_code: string|null, reason_message: string|null}> $weekdayResults */
        $weekdayResults = [];

        for ($offset = 1; $offset <= self::FALLBACK_DAYS; $offset++) {
            $candidateDate = $requestedDate->copy()->addDays($offset);
            $weekday = $candidateDate->englishDayOfWeek;
            $candidateResult = $weekdayResults[$weekday] ??= $this->evaluateDate($preferences, $candidateDate);

            if ($candidateResult['recommendations'] !== []) {
                return $this->withTemplateMetadata($this->dateAwareResult(
                    $requestedDate,
                    $candidateDate,
                    $candidateResult['recommendations'],
                    $requestedResult['reason_code'],
                    $requestedResult['reason_message'],
                    true,
                ), $preferences);
            }
        }

        return $this->withTemplateMetadata($this->dateAwareResult(
            $requestedDate,
            null,
            [],
            $requestedResult['reason_code'],
            $requestedResult['reason_message'],
            false,
            true,
        ), $preferences);
    }

    /** @param array<string, mixed> $data */
    public function createPlanForClient(User $user, array $data): VisitPlan
    {
        $requestedPreferences = $data;
        $requestedPreferences['visit_date'] = $data['requested_date'];
        $result = $this->recommendDateAware($requestedPreferences);
        $confirmedDate = $result['recommendation_date'];

        if ($confirmedDate === null) {
            throw ValidationException::withMessages([
                'night_market_id' => 'This recommendation is no longer available. Generate recommendations again.',
            ]);
        }

        if ($result['uses_fallback'] && ! ($data['confirmed_fallback_date'] ?? false)) {
            throw ValidationException::withMessages([
                'confirmed_fallback_date' => 'Select Use recommended date before creating a plan for the fallback date.',
            ]);
        }

        if ($data['visit_date'] !== $confirmedDate) {
            throw ValidationException::withMessages([
                'visit_date' => 'This recommendation date is no longer available. Generate recommendations again.',
            ]);
        }

        $recommendation = collect($result['recommendations'])
            ->first(fn (array $recommendation) => $recommendation['market']->id === (int) $data['night_market_id']);

        if (! $recommendation) {
            throw ValidationException::withMessages([
                'night_market_id' => 'This recommendation is no longer available. Generate recommendations again.',
            ]);
        }

        $allowedStallIds = collect($recommendation['stalls'])->pluck('stall.id')->map(fn ($id) => (int) $id);
        $allowedFoodIds = collect($recommendation['foods'])->pluck('food.id')->map(fn ($id) => (int) $id);
        $submittedStallIds = collect($data['stall_ids'] ?? [])->map(fn ($id) => (int) $id);
        $submittedFoodIds = collect($data['food_ids'] ?? [])->map(fn ($id) => (int) $id);

        if ($submittedStallIds->diff($allowedStallIds)->isNotEmpty()
            || $submittedFoodIds->diff($allowedFoodIds)->isNotEmpty()
            || ($submittedStallIds->isEmpty() && $submittedFoodIds->isEmpty())) {
            throw ValidationException::withMessages([
                'food_ids' => 'One or more recommended targets are no longer available. Generate recommendations again.',
            ]);
        }

        return DB::transaction(function () use ($user, $data, $confirmedDate, $submittedStallIds, $submittedFoodIds): VisitPlan {
            $visitPlan = $this->visitPlanService->createForClient($user, [
                'title' => $data['title'],
                'night_market_id' => (int) $data['night_market_id'],
                'visit_date' => $confirmedDate,
                'notes' => $data['preference_notes'] ?? null,
            ]);

            foreach ($submittedStallIds as $stallId) {
                $this->visitPlanService->addItemForClient($user, $visitPlan->id, [
                    'item_type' => 'stall',
                    'stall_id' => $stallId,
                ]);
            }

            foreach ($submittedFoodIds as $foodId) {
                $this->visitPlanService->addItemForClient($user, $visitPlan->id, [
                    'item_type' => 'food',
                    'food_id' => $foodId,
                ]);
            }

            return $visitPlan;
        });
    }

    /**
     * @param  array<string, mixed>  $preferences
     * @return array{recommendations: list<array<string, mixed>>, reason_code: string|null, reason_message: string|null}
     */
    private function evaluateDate(array $preferences, Carbon $visitDate): array
    {
        $dayOfWeek = $visitDate->englishDayOfWeek;
        $categories = array_values($preferences['categories'] ?? []);
        $halalPreference = $preferences['halal_preference'];
        $mustTry = (bool) $preferences['must_try'];
        $hasBudget = isset($preferences['budget_min'], $preferences['budget_max']);
        $budgetMin = $hasBudget ? (float) $preferences['budget_min'] : null;
        $budgetMax = $hasBudget ? (float) $preferences['budget_max'] : null;

        $operatingMarkets = NightMarket::query()
            ->eligibleForPlanning()
            ->select(['id', 'name', 'city', 'state'])
            ->whereHas('operatingDays', fn (Builder $query) => $query->where('day_of_week', $dayOfWeek))
            ->with([
                'operatingDays' => fn ($query) => $query
                    ->select(['id', 'night_market_id', 'day_of_week', 'opening_time', 'closing_time'])
                    ->where('day_of_week', $dayOfWeek)
                    ->orderBy('opening_time'),
                'stalls' => fn ($query) => $query
                    ->select(['id', 'night_market_id', 'name', 'halal_status'])
                    ->where('status', Stall::STATUS_ACTIVE)
                    ->orderBy('name')
                    ->orderBy('id'),
                'stalls.foods' => fn ($query) => $query
                    ->select([
                        'id', 'stall_id', 'name', 'category', 'price_min', 'price_max', 'is_must_try', 'image_path',
                    ])
                    ->where('status', Food::STATUS_ACTIVE)
                    ->orderBy('name')
                    ->orderBy('id'),
            ])
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        if ($operatingMarkets->isEmpty()) {
            return $this->emptyDateResult(
                'no_operating_market',
                'No public Night Market operates on '.$this->displayDate($visitDate).'.',
            );
        }

        $matchingMarkets = $operatingMarkets
            ->when($preferences['city'] ?? null, fn ($markets, string $city) => $markets
                ->filter(fn (NightMarket $market) => $market->city === $city))
            ->when($preferences['night_market_id'] ?? null, fn ($markets, int $marketId) => $markets
                ->filter(fn (NightMarket $market) => $market->id === $marketId));

        if ($matchingMarkets->isEmpty()) {
            return $this->emptyDateResult(
                'market_preferences',
                'Public Night Markets operate on '.$this->displayDate($visitDate)
                    .', but none matches your selected city or Night Market.',
            );
        }

        if (SmartPlannerTemplate::isKnown($preferences['template'] ?? null)) {
            return $this->evaluateTemplate($preferences['template'], $matchingMarkets, $preferences, $dayOfWeek);
        }

        $hasFoodPreferenceMatch = false;
        $recommendations = [];

        foreach ($matchingMarkets as $market) {
            $foodRecommendations = [];

            foreach ($market->stalls as $stall) {
                if ($halalPreference !== 'any' && $stall->halal_status !== $halalPreference) {
                    continue;
                }

                foreach ($stall->foods as $food) {
                    if ($categories !== [] && ! in_array($food->category, $categories, true)) {
                        continue;
                    }

                    if ($mustTry && ! $food->is_must_try) {
                        continue;
                    }

                    $hasFoodPreferenceMatch = true;

                    if ($hasBudget && ! $this->canAppearForBudget($food, $budgetMin, $budgetMax)) {
                        continue;
                    }

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

        if (! $hasFoodPreferenceMatch) {
            return $this->emptyDateResult(
                'food_preferences',
                'Matching Night Markets operate on '.$this->displayDate($visitDate)
                    .', but no public Food matches your category, Halal, or Must-Try preferences.',
            );
        }

        if ($recommendations === [] && $hasBudget) {
            return $this->emptyDateResult(
                'budget',
                'Matching Foods are available on '.$this->displayDate($visitDate)
                    .', but none with a complete known price range fits your selected budget.',
            );
        }

        usort($recommendations, fn (array $left, array $right) => ($right['score'] <=> $left['score'])
            ?: strcasecmp($left['market']->name, $right['market']->name)
            ?: ($left['market']->id <=> $right['market']->id));

        return [
            'recommendations' => array_slice($recommendations, 0, (int) $preferences['max_markets']),
            'reason_code' => null,
            'reason_message' => null,
        ];
    }

    /**
     * @param  Collection<int, NightMarket>  $markets
     * @param  array<string, mixed>  $preferences
     * @return array{recommendations: list<array<string, mixed>>, reason_code: string|null, reason_message: string|null}
     */
    private function evaluateTemplate(string $template, Collection $markets, array $preferences, string $dayOfWeek): array
    {
        return match ($template) {
            SmartPlannerTemplate::QUICK_VISIT => $this->quickVisitRecommendations($markets, $preferences, $dayOfWeek),
            SmartPlannerTemplate::FOOD_HUNTING => $this->foodHuntingRecommendations($markets, $preferences, $dayOfWeek),
            SmartPlannerTemplate::FAMILY_FRIENDLY => $this->familyFriendlyRecommendations($markets, $preferences, $dayOfWeek),
            SmartPlannerTemplate::BUDGET => $this->budgetRecommendations($markets, $preferences, $dayOfWeek),
        };
    }

    /**
     * @param  Collection<int, NightMarket>  $markets
     * @param  array<string, mixed>  $preferences
     * @return array{recommendations: list<array<string, mixed>>, reason_code: string|null, reason_message: string|null}
     */
    private function quickVisitRecommendations(Collection $markets, array $preferences, string $dayOfWeek): array
    {
        foreach ($markets as $market) {
            $foods = $this->templateFoodCandidates($market, $preferences);
            if ($foods === []) {
                continue;
            }

            usort($foods, $this->foodScoreComparator(...));
            $selectedFoods = array_slice($foods, 0, 3);

            return $this->templateResult($this->templateRecommendation(
                $market,
                $selectedFoods,
                '1-Hour Quick Visit template: selected up to three food stops at about 20 minutes per stop.',
                'Queueing, walking, and travel time are not included, so one hour is not guaranteed.',
                "Operates on {$dayOfWeek}",
            ));
        }

        return $this->templateEmptyResult('No active Foods match the selected preferences for a quick visit.');
    }

    /**
     * @param  Collection<int, NightMarket>  $markets
     * @param  array<string, mixed>  $preferences
     * @return array{recommendations: list<array<string, mixed>>, reason_code: string|null, reason_message: string|null}
     */
    private function foodHuntingRecommendations(Collection $markets, array $preferences, string $dayOfWeek): array
    {
        foreach ($markets as $market) {
            $foods = $this->templateFoodCandidates($market, $preferences, ignoreMustTryPreference: true);
            if ($foods === []) {
                continue;
            }

            $mustTryFoods = array_values(array_filter($foods, fn (array $food) => $food['food']->is_must_try));
            $otherFoods = array_values(array_filter($foods, fn (array $food) => ! $food['food']->is_must_try));
            usort($mustTryFoods, $this->foodScoreComparator(...));
            usort($otherFoods, $this->foodScoreComparator(...));

            $selectedFoods = $this->selectDiverseFoods($mustTryFoods, 5);
            $mustTryCount = count($selectedFoods);
            if (count($selectedFoods) < 5) {
                $selectedFoods = $this->selectDiverseFoods($otherFoods, 5, $selectedFoods);
            }

            $fallbackCount = count($selectedFoods) - $mustTryCount;
            $fallback = $fallbackCount > 0
                ? "Only {$mustTryCount} Must-Try Food ".($mustTryCount === 1 ? 'was' : 'were').' available, so '
                    ."{$fallbackCount} other active Food ".($fallbackCount === 1 ? 'was' : 'were').' added.'
                : 'All selected food stops are currently marked Must-Try.';

            return $this->templateResult($this->templateRecommendation(
                $market,
                $selectedFoods,
                'Food Hunting Plan template: prioritised Must-Try Foods and varied stalls and food categories where possible.',
                $fallback,
                "Operates on {$dayOfWeek}",
            ));
        }

        return $this->templateEmptyResult('No active Foods match the selected preferences for this food hunting plan.');
    }

    /**
     * @param  Collection<int, NightMarket>  $markets
     * @param  array<string, mixed>  $preferences
     * @return array{recommendations: list<array<string, mixed>>, reason_code: string|null, reason_message: string|null}
     */
    private function familyFriendlyRecommendations(Collection $markets, array $preferences, string $dayOfWeek): array
    {
        $signals = $this->familyFriendlySignals($markets);
        $ratings = $this->foodRatings($markets);
        $markets = $markets->sortBy(fn (NightMarket $market) => sprintf(
            '%d-%s',
            isset($signals['markets'][$market->id]) ? 0 : 1,
            mb_strtolower($market->name),
        ))->values();

        foreach ($markets as $market) {
            $foods = $this->templateFoodCandidates($market, $preferences);
            if ($foods === []) {
                continue;
            }

            $marketHasSignal = isset($signals['markets'][$market->id]);
            usort($foods, function (array $left, array $right) use ($signals, $ratings, $marketHasSignal): int {
                $leftSignal = $marketHasSignal || isset($signals['foods'][$left['food']->id]);
                $rightSignal = $marketHasSignal || isset($signals['foods'][$right['food']->id]);

                return ($rightSignal <=> $leftSignal)
                    ?: (($ratings[$right['food']->id] ?? 0) <=> ($ratings[$left['food']->id] ?? 0))
                    ?: $this->foodScoreComparator($left, $right);
            });
            $selectedFoods = $this->selectDiverseFoods($foods, 3);
            $hasSelectedSignal = $marketHasSignal || collect($selectedFoods)
                ->contains(fn (array $food) => isset($signals['foods'][$food['food']->id]));

            $notice = $hasSelectedSignal
                ? 'Public Family-Friendly review tags informed this short, varied plan. Review tags do not verify children’s facilities, safety, or accessibility.'
                : 'No verified family-friendly tag data was available; this is a general short and varied plan.';

            return $this->templateResult($this->templateRecommendation(
                $market,
                $selectedFoods,
                'Family-Friendly Plan template: used a short itinerary, public review-tag signals where available, higher ratings, and food variety.',
                $notice,
                "Operates on {$dayOfWeek}",
            ));
        }

        return $this->templateEmptyResult('No active Foods match the selected preferences for this general family-friendly plan.');
    }

    /**
     * @param  Collection<int, NightMarket>  $markets
     * @param  array<string, mixed>  $preferences
     * @return array{recommendations: list<array<string, mixed>>, reason_code: string|null, reason_message: string|null}
     */
    private function budgetRecommendations(Collection $markets, array $preferences, string $dayOfWeek): array
    {
        $budget = (float) ($preferences['budget_max'] ?? 30);

        foreach ($markets as $market) {
            $foods = array_values(array_filter(
                $this->templateFoodCandidates($market, $preferences),
                fn (array $food) => $food['food']->price_max !== null,
            ));
            usort($foods, fn (array $left, array $right) => ((float) $left['food']->price_max <=> (float) $right['food']->price_max)
                ?: $this->foodScoreComparator($left, $right));

            $selectedFoods = [];
            $total = 0.0;
            foreach ($foods as $food) {
                $priceMaximum = (float) $food['food']->price_max;
                if ($total + $priceMaximum > $budget) {
                    continue;
                }

                $selectedFoods[] = $food;
                $total += $priceMaximum;
            }

            if ($selectedFoods === []) {
                continue;
            }

            return $this->templateResult($this->templateRecommendation(
                $market,
                $selectedFoods,
                'Budget Visit Plan template: uses numeric price maximums only and keeps the conservative total within the selected budget.',
                'Foods without a numeric price maximum were excluded; price display text was not used to estimate costs.',
                "Operates on {$dayOfWeek}",
                $total,
                $budget,
            ));
        }

        return $this->templateEmptyResult('No active Foods with a numeric price maximum fit the selected budget.');
    }

    /**
     * @param  array<string, mixed>  $preferences
     * @return list<array<string, mixed>>
     */
    private function templateFoodCandidates(NightMarket $market, array $preferences, bool $ignoreMustTryPreference = false): array
    {
        $categories = array_values($preferences['categories'] ?? []);
        $halalPreference = $preferences['halal_preference'];
        $mustTryOnly = ! $ignoreMustTryPreference && (bool) $preferences['must_try'];
        $budgetMinimum = isset($preferences['budget_min']) ? (float) $preferences['budget_min'] : null;
        $budgetMaximum = isset($preferences['budget_max']) ? (float) $preferences['budget_max'] : null;
        $foods = [];

        foreach ($market->stalls as $stall) {
            if ($halalPreference !== 'any' && $stall->halal_status !== $halalPreference) {
                continue;
            }

            foreach ($stall->foods as $food) {
                if (($categories !== [] && ! in_array($food->category, $categories, true))
                    || ($mustTryOnly && ! $food->is_must_try)) {
                    continue;
                }

                $foods[] = $this->composeFoodRecommendation(
                    $food,
                    $stall,
                    $categories,
                    $halalPreference,
                    $mustTryOnly,
                    $budgetMinimum,
                    $budgetMaximum,
                );
            }
        }

        return $foods;
    }

    /** @param list<array<string, mixed>> $foods @param list<array<string, mixed>> $selected @return list<array<string, mixed>> */
    private function selectDiverseFoods(array $foods, int $limit, array $selected = []): array
    {
        $selectedIds = collect($selected)->pluck('food.id')->map(fn ($id) => (int) $id)->all();
        $usedStallIds = collect($selected)->pluck('stall.id')->map(fn ($id) => (int) $id)->all();
        $usedCategories = collect($selected)->pluck('food.category')->map(fn ($category) => $category ?? '')->all();

        foreach ([true, false] as $requireNewStallAndCategory) {
            foreach ($foods as $food) {
                if (count($selected) >= $limit || in_array($food['food']->id, $selectedIds, true)) {
                    continue;
                }

                $newStall = ! in_array($food['stall']->id, $usedStallIds, true);
                $newCategory = ! in_array($food['food']->category ?? '', $usedCategories, true);
                if ($requireNewStallAndCategory && (! $newStall || ! $newCategory)) {
                    continue;
                }

                $selected[] = $food;
                $selectedIds[] = $food['food']->id;
                $usedStallIds[] = $food['stall']->id;
                $usedCategories[] = $food['food']->category ?? '';
            }
        }

        return $selected;
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    private function foodScoreComparator(array $left, array $right): int
    {
        return ($right['score'] <=> $left['score'])
            ?: strcasecmp($left['food']->name, $right['food']->name)
            ?: ($left['food']->id <=> $right['food']->id);
    }

    /** @param Collection<int, NightMarket> $markets @return array{markets: array<int, true>, foods: array<int, true>} */
    private function familyFriendlySignals(Collection $markets): array
    {
        $marketIds = $markets->pluck('id');
        $foodIds = $markets->flatMap(fn (NightMarket $market) => $market->stalls->flatMap->foods)->pluck('id');
        $reviews = Review::query()
            ->publiclyVisible()
            ->whereHas('tags', fn (Builder $query) => $query->where('name', 'Family-Friendly'))
            ->where(fn (Builder $query) => $query
                ->whereIn('night_market_id', $marketIds)
                ->orWhereIn('food_id', $foodIds))
            ->get(['night_market_id', 'food_id']);

        return [
            'markets' => $reviews->whereNotNull('night_market_id')->pluck('night_market_id')->flip()->map(fn () => true)->all(),
            'foods' => $reviews->whereNotNull('food_id')->pluck('food_id')->flip()->map(fn () => true)->all(),
        ];
    }

    /** @param Collection<int, NightMarket> $markets @return array<int, float> */
    private function foodRatings(Collection $markets): array
    {
        $foodIds = $markets->flatMap(fn (NightMarket $market) => $market->stalls->flatMap->foods)->pluck('id');

        return Review::query()
            ->publiclyVisible()
            ->whereIn('food_id', $foodIds)
            ->selectRaw('food_id, AVG(rating) as average_rating')
            ->groupBy('food_id')
            ->pluck('average_rating', 'food_id')
            ->map(fn ($rating) => (float) $rating)
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $foods
     * @return array<string, mixed>
     */
    private function templateRecommendation(
        NightMarket $market,
        array $foods,
        string $explanation,
        string $notice,
        string $operatingReason,
        ?float $budgetTotal = null,
        ?float $budgetLimit = null,
    ): array {
        $completePriceFoods = array_filter($foods, fn (array $food) => $food['has_complete_price']);
        $estimatedMinimum = array_sum(array_map(fn (array $food) => (float) $food['food']->price_min, $completePriceFoods));
        $estimatedMaximum = array_sum(array_map(fn (array $food) => (float) $food['food']->price_max, $completePriceFoods));

        return [
            'market' => $market,
            'score' => 100 + array_sum(array_column($foods, 'score')),
            'explanation' => $explanation.' '.$operatingReason,
            'stalls' => [],
            'foods' => $foods,
            'estimated_price_label' => $budgetTotal !== null
                ? 'RM'.number_format($budgetTotal, 2).' of RM'.number_format((float) $budgetLimit, 2)
                : ($completePriceFoods === []
                    ? 'Not available'
                    : ($estimatedMinimum === $estimatedMaximum
                        ? 'RM'.number_format($estimatedMinimum, 2)
                        : 'RM'.number_format($estimatedMinimum, 2).'–RM'.number_format($estimatedMaximum, 2))),
            'unknown_price_count' => count($foods) - count($completePriceFoods),
            'template_notice' => $notice,
        ];
    }

    /** @param array<string, mixed> $recommendation @return array{recommendations: list<array<string, mixed>>, reason_code: null, reason_message: null} */
    private function templateResult(array $recommendation): array
    {
        return [
            'recommendations' => [$recommendation],
            'reason_code' => null,
            'reason_message' => null,
        ];
    }

    /** @return array{recommendations: list<array<string, mixed>>, reason_code: string, reason_message: string} */
    private function templateEmptyResult(string $message): array
    {
        return $this->emptyDateResult('template_data', $message);
    }

    private function canAppearForBudget(Food $food, float $minimum, float $maximum): bool
    {
        if ($food->price_min === null && $food->price_max === null) {
            return true;
        }

        if ($food->price_min !== null && $food->price_max === null) {
            return (float) $food->price_min <= $maximum;
        }

        if ($food->price_min === null) {
            return (float) $food->price_max >= $minimum;
        }

        return (float) $food->price_min >= $minimum && (float) $food->price_max <= $maximum;
    }

    /** @return array{recommendations: array{}, reason_code: string, reason_message: string} */
    private function emptyDateResult(string $code, string $message): array
    {
        return [
            'recommendations' => [],
            'reason_code' => $code,
            'reason_message' => $message,
        ];
    }

    /** @return array<string, mixed> */
    private function dateAwareResult(
        Carbon $requestedDate,
        ?Carbon $recommendationDate,
        array $recommendations,
        ?string $requestedReasonCode,
        ?string $requestedReasonMessage,
        bool $usesFallback,
        bool $fallbackExhausted = false,
    ): array {
        return [
            'requested_date' => $requestedDate->toDateString(),
            'requested_date_label' => $this->displayDate($requestedDate),
            'recommendation_date' => $recommendationDate?->toDateString(),
            'recommendation_date_label' => $recommendationDate ? $this->displayDate($recommendationDate) : null,
            'uses_fallback' => $usesFallback,
            'requested_reason_code' => $requestedReasonCode,
            'requested_reason_message' => $requestedReasonMessage,
            'fallback_exhausted' => $fallbackExhausted,
            'fallback_limit_days' => self::FALLBACK_DAYS,
            'recommendations' => $recommendations,
        ];
    }

    /** @param array<string, mixed> $result @param array<string, mixed> $preferences @return array<string, mixed> */
    private function withTemplateMetadata(array $result, array $preferences): array
    {
        $template = $preferences['template'] ?? null;
        if (! SmartPlannerTemplate::isKnown($template)) {
            return $result;
        }

        $card = SmartPlannerTemplate::cards()[$template];
        $result['template'] = [
            'key' => $template,
            ...$card,
            'notice' => $result['recommendations'][0]['template_notice'] ?? null,
        ];

        return $result;
    }

    private function displayDate(Carbon $date): string
    {
        return $date->format('l, j M Y');
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
