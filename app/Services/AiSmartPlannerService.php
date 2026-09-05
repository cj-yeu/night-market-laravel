<?php

namespace App\Services;

use App\Exceptions\PlannerAiUnavailable;
use App\Models\Food;
use App\Models\MarketOperatingDay;
use App\Models\NightMarket;
use App\Models\Stall;
use App\Models\User;
use App\Models\VisitPlan;
use App\Support\CatalogCategory;
use App\Support\PlannerFoodInterests;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AiSmartPlannerService
{
    public function __construct(
        private readonly SmartVisitPlannerService $basic,
        private readonly PlannerCandidateService $catalog,
        private readonly OpenAiPlannerProvider $provider,
        private readonly PlannerRequestGuard $guard,
        private readonly VisitPlanService $plans,
    ) {}

    public function preparePreferences(array $preferences): array
    {
        $preferences = $this->basic->normaliseTemplatePreferences($preferences);
        $preferences['explicit_categories'] = $preferences['categories'] ?? [];
        $preferences['categories'] = PlannerFoodInterests::resolve($preferences['interests'] ?? [], $preferences['explicit_categories'],
            $this->basic->preferenceOptions()['categories']->pluck('category')->all());

        return $preferences;
    }

    public function recommend(User $user, array $preferences): array
    {
        return $this->guard->run($user->id, function () use ($user, $preferences) {
            $inputPreferences = [...$preferences, 'categories' => $preferences['explicit_categories'] ?? $preferences['categories'] ?? []];
            $this->guard->cache()->put('planner-draft:'.$user->id, $inputPreferences, 1200);
            // Notes stay local. A minimum is a soft preference, not a spending floor.
            $effective = $preferences;
            $effective['budget_min'] = isset($preferences['budget_max']) ? 0 : null;
            $result = $this->basic->recommendDateAware($effective);
            $date = $result['recommendation_date'];
            $result['source'] = 'basic';
            $result['source_notice'] = 'Basic recommendations — catalog rules, not AI.';
            if ($date === null) {
                $this->invalidate($user);

                return $result;
            }
            $candidates = $this->catalog->candidates($effective, $date);
            // Re-run date discovery with strict candidate eligibility if the old
            // per-food budget filtering selected a date without affordable foods.
            if ($candidates->isEmpty()) {
                for ($offset = 0; $offset <= 14; $offset++) {
                    $candidateDate = Carbon::parse($preferences['visit_date'])->addDays($offset)->toDateString();
                    $available = $this->catalog->candidates($effective, $candidateDate);
                    if ($available->isNotEmpty()) {
                        $date = $candidateDate;
                        $candidates = $available;
                        $result['recommendation_date'] = $date;
                        $result['recommendation_date_label'] = Carbon::parse($date)->format('l, j M Y');
                        $result['uses_fallback'] = $date !== $preferences['visit_date'];
                        $result['requested_reason_message'] = 'No eligible food combination meets these preferences and food budget on the requested date. This does not mean every market is closed.';
                        break;
                    }
                }
            }
            $version = $this->catalog->version($candidates);
            $safePreferences = array_intersect_key($effective, array_flip([
                'visit_date', 'city', 'night_market_id', 'budget_max', 'categories', 'halal_preference', 'must_try', 'max_markets', 'template',
            ]));
            $safePreferences['visit_date'] = $date;
            $safePreferences['soft_budget_min'] = $preferences['budget_min'] ?? null;
            $cacheKey = 'planner-result:'.$user->id.':'.hash('sha256', json_encode([$safePreferences, $version]));
            $selections = null;
            if ($candidates->isNotEmpty() && ($preferences['recommendation_mode'] ?? 'ai') === 'ai') {
                try {
                    $selections = $this->guard->cache()->get($cacheKey);
                    if ($selections === null) {
                        $this->guard->charge($user->id);
                        $selections = $this->provider->select($safePreferences, $this->catalog->facts($candidates), $this->catalog->limit($preferences));
                    }
                    $this->validateAi($selections, $candidates, $effective);
                    // Re-read after the external request; a catalog edit during it
                    // must never become a trusted snapshot.
                    $fresh = $this->catalog->candidates($effective, $date);
                    if ($this->catalog->version($fresh) !== $version) {
                        throw new PlannerAiUnavailable;
                    }
                    $this->guard->cache()->put($cacheKey, $selections, 180);
                    $result['source'] = 'ai';
                    $result['source_notice'] = 'AI-selected from the public catalog. Eligibility, reasons and food costs checked by NightBite.';
                } catch (PlannerAiUnavailable|ValidationException) {
                    $selections = null;
                    $result['source_notice'] = 'AI assistance was unavailable or its selection did not pass validation. These are basic recommendations.';
                    // Refresh even on fallback; never use stale provider-time data.
                    $candidates = $this->catalog->candidates($effective, $date);
                    $version = $this->catalog->version($candidates);
                }
            }
            if ($selections === null) {
                $selections = $this->fallback($candidates, $effective, $result['recommendations']);
            }
            $result['recommendations'] = [];
            if ($selections === []) {
                $result['uses_fallback'] = false;
                $result['requested_reason_message'] = 'No eligible food combination meets these preferences and food budget. Try another date, city, food interest or budget.';
                $this->invalidate($user);

                return $result;
            }
            $token = (string) Str::uuid();
            $snapshot = [
                'user_id' => $user->id, 'expires_at' => time() + 1200,
                'preferences' => $effective, 'date' => $date, 'requested_date' => $preferences['visit_date'],
                'candidate_ids' => $candidates->keys()->all(), 'version' => $version,
                'markets' => array_column($selections, 'market_id'),
                'input_preferences' => $inputPreferences,
                'selections' => $selections,
                'result_meta' => array_diff_key($result, ['recommendations' => true]),
            ];
            if (! $this->guard->cache()->put($this->key($user, $token), $snapshot, 1200)
                || ! $this->guard->cache()->put('planner-active:'.$user->id, $token, 1200)) {
                throw ValidationException::withMessages(['planner' => 'Could not safely retain your recommendation. Please try again.']);
            }
            $result = $this->renderSelections($result, $selections, $candidates, $effective, $token);

            return $result;
        });
    }

    // GET results rebuild display data from the bound snapshot. No provider call,
    // generation, TTL extension or new snapshot happens during a read.
    public function resultFor(User $user, string $token): array
    {
        $snapshot = $this->guard->cache()->get($this->key($user, $token));
        if (! $snapshot || $snapshot['user_id'] !== $user->id || $snapshot['expires_at'] < time()
            || $this->guard->cache()->get('planner-active:'.$user->id) !== $token
            || $snapshot['date'] < now('Asia/Kuala_Lumpur')->toDateString()
            || ! isset($snapshot['selections'], $snapshot['result_meta'])) {
            throw ValidationException::withMessages(['snapshot_id' => 'This recommendation has expired or changed. Review your preferences and generate a new plan.']);
        }
        $candidates = $this->catalog->candidates($snapshot['preferences'], $snapshot['date'], $snapshot['candidate_ids']);
        if ($this->catalog->version($candidates) !== $snapshot['version']) {
            throw ValidationException::withMessages(['snapshot_id' => 'Catalog information changed. Review your preferences and generate again for current prices and availability.']);
        }

        return $this->renderSelections($snapshot['result_meta'], $snapshot['selections'], $candidates, $snapshot['preferences'], $token);
    }

    public function preferencesFor(User $user, ?string $token = null): ?array
    {
        if ($token !== null) {
            $snapshot = $this->guard->cache()->get($this->key($user, $token));

            return $snapshot && $snapshot['user_id'] === $user->id && $snapshot['expires_at'] >= time()
                ? ($snapshot['input_preferences'] ?? null) : null;
        }

        return $this->guard->cache()->get('planner-draft:'.$user->id);
    }

    public function currentResultId(User $user): ?string
    {
        $token = $this->guard->cache()->get('planner-active:'.$user->id);
        if (! is_string($token)) {
            return null;
        }
        $snapshot = $this->guard->cache()->get($this->key($user, $token));

        return $snapshot && $snapshot['user_id'] === $user->id && $snapshot['expires_at'] >= time() ? $token : null;
    }

    private function renderSelections(array $result, array $selections, $candidates, array $effective, string $token): array
    {
        $result['recommendations'] = [];
        foreach ($selections as $selection) {
            $selected = $this->catalog->validateSelection(array_column($selection['foods'], 'food_id'), $selection['market_id'], $candidates, $effective);
            $market = $selected->first()->stall->nightMarket;
            $replacements = $candidates->filter(fn ($food) => $food->stall->night_market_id === $market->id)->values();
            $result['recommendations'][] = [
                'market' => $market, 'foods' => $selected->map(fn ($food) => $this->foodCard($food, $selection, $effective))->all(),
                'stalls' => [], 'snapshot_id' => $token, 'replacements' => $replacements,
                'estimated_price_label' => $this->catalog->cost($selected)['label'],
                'unknown_price_count' => $this->catalog->cost($selected)['unknown'],
                'explanation' => 'Selected for the suggested date, location and food preferences. The food budget is a ceiling, not a spending target.',
                'template_notice' => $this->templateNotice($effective, $selected),
                'quality' => $this->selectionQuality($effective, $selected, $replacements),
            ];
        }
        // Template notices are recomputed from the actual chosen combination.
        if (isset($result['template'])) {
            $result['template']['notice'] = $result['recommendations'][0]['template_notice'];
        }

        return $result;
    }

    private function selectionQuality(array $preferences, $selected, $candidates): array
    {
        $groupsFor = fn ($foods) => array_keys(PlannerFoodInterests::options($foods->map(fn ($food) => CatalogCategory::canonical($food->category, 'food'))->unique()->all()));
        $available = $groupsFor($candidates);
        $covered = $groupsFor($selected);
        $interests = [];
        foreach ($preferences['interests'] ?? [] as $key) {
            $interests[] = ['key' => $key, 'label' => PlannerFoodInterests::GROUPS[$key]['label'],
                'status' => in_array($key, $covered, true) ? 'Included'
                    : (in_array($key, $available, true) ? 'Available in replacements, not selected' : 'No matching eligible food at this market on this date')];
        }

        return ['candidate_count' => $candidates->count(), 'stop_limit' => $this->catalog->limit($preferences), 'interests' => $interests];
    }

    private function validateAi(array $selections, $candidates, array $preferences): void
    {
        if (count($selections) > (int) $preferences['max_markets']
            || count(array_unique(array_column($selections, 'market_id'))) !== count($selections)) {
            throw new PlannerAiUnavailable;
        }
        foreach ($selections as $selection) {
            $this->catalog->validateSelection(array_column($selection['foods'], 'food_id'), (int) $selection['market_id'], $candidates, $preferences);
            foreach ($selection['foods'] as $item) {
                $food = $candidates->get($item['food_id']);
                if (($item['reason'] === 'must_try' && ! $food->is_must_try)
                    || ($item['reason'] === 'family_signal' && ! $food->family_signal)
                    || ($item['reason'] === 'known_price' && $food->price_max === null)) {
                    throw new PlannerAiUnavailable;
                }
            }
        }
    }

    private function fallback($candidates, array $preferences, array $basic): array
    {
        $rank = collect($basic)->flatMap(fn ($plan) => collect($plan['foods'])->pluck('food.id'))->flip();
        $groups = $candidates->sortBy(fn ($food) => $rank[$food->id] ?? 1000)->groupBy('stall.night_market_id');
        $plans = [];
        foreach ($groups as $marketId => $foods) {
            $selected = [];
            $total = 0;
            foreach ($foods as $food) {
                if (count($selected) >= $this->catalog->limit($preferences)) {
                    break;
                }
                $price = $this->catalog->cents($food->price_max);
                if (isset($preferences['budget_max']) && ($this->catalog->cost(collect([$food]))['unknown'] || $total + $price > $this->catalog->cents($preferences['budget_max']))) {
                    continue;
                }
                $selected[] = ['food_id' => $food->id, 'reason' => 'preference_match'];
                $total += $price;
            }
            if ($selected !== []) {
                $plans[] = ['market_id' => (int) $marketId, 'foods' => $selected];
            }
            if (count($plans) >= $preferences['max_markets']) {
                break;
            }
        }

        return $plans;
    }

    private function foodCard(Food $food, array $selection, array $preferences): array
    {
        $reason = collect($selection['foods'])->firstWhere('food_id', $food->id)['reason'];
        // Provider free text never reaches the UI. Display only locally grounded claims.
        $explanation = match ($reason) {
            'must_try' => 'Marked Must-Try in the current catalog.',
            'known_price' => 'Has a numeric price upper bound for food-budget planning.',
            'family_signal' => 'Has a public Family-Friendly review-tag signal, not a facilities or safety certification.',
            'variety' => 'Selected as part of this food combination; you can replace it below.',
            default => 'Matches your confirmed food preferences and parent-stall eligibility.',
        };

        return ['food' => $food, 'stall' => $food->stall, 'price_label' => $this->catalog->cost(collect([$food]))['label'], 'explanation' => $explanation];
    }

    private function templateNotice(array $preferences, $foods): ?string
    {
        return match ($preferences['template'] ?? null) {
            'family_friendly' => $foods->contains(fn ($food) => $food->family_signal)
                ? 'Public Family-Friendly review tags informed this plan; they do not verify children’s facilities, safety or accessibility.'
                : 'No verified family-friendly tag data was available; this is a general short and varied plan.',
            'food_hunting' => $foods->contains(fn ($food) => ! $food->is_must_try)
                ? 'Other active Foods supplement Must-Try choices within your preferences and budget.' : 'All chosen foods are marked Must-Try.',
            'quick_visit' => 'About 20 minutes per stop. Queueing, walking and travel time are excluded; one hour is not guaranteed.',
            'budget' => 'Numeric upper prices only. Display price strings are never used for budget calculations.',
            default => null,
        };
    }

    public function invalidate(User $user, ?string $token = null): void
    {
        if ($token === null || $this->guard->cache()->get('planner-active:'.$user->id) === $token) {
            $this->guard->cache()->forget('planner-active:'.$user->id);
        }
    }

    public function save(User $user, array $data): VisitPlan
    {
        return $this->guard->run($user->id, function () use ($user, $data) {
            $key = $this->key($user, $data['snapshot_id']);
            $receiptKey = $key.':receipt';
            $receipt = $this->guard->cache()->get($receiptKey);
            if (is_int($receipt)) {
                return $user->visitPlans()->findOrFail($receipt);
            }
            $snapshot = $this->guard->cache()->get($key);
            if ($receipt === 'saving' || ! $snapshot || $snapshot['user_id'] !== $user->id
                || $snapshot['expires_at'] < time()
                || $this->guard->cache()->get('planner-active:'.$user->id) !== $data['snapshot_id']) {
                throw ValidationException::withMessages(['snapshot_id' => 'This recommendation has expired, changed or was already submitted. Check My Visit Plans or generate again.']);
            }
            if ($snapshot['date'] < now('Asia/Kuala_Lumpur')->toDateString()) {
                throw ValidationException::withMessages(['visit_date' => 'This visit date has passed. Generate a new plan.']);
            }
            if ($snapshot['date'] !== $snapshot['requested_date'] && ! ($data['confirmed_fallback_date'] ?? false)) {
                throw ValidationException::withMessages(['confirmed_fallback_date' => 'Select Use recommended date before saving this alternative date.']);
            }
            if (! in_array((int) $data['night_market_id'], $snapshot['markets'], true)) {
                throw ValidationException::withMessages(['night_market_id' => 'Choose a market from this recommendation.']);
            }

            return DB::transaction(function () use ($user, $data, $snapshot, $receiptKey) {
                // Lock catalog parent rows before fresh eligibility checks, as well
                // as the owner. Catalog changes cannot race the final validation.
                User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                NightMarket::query()->whereKey($data['night_market_id'])->lockForUpdate()->firstOrFail();
                MarketOperatingDay::query()->where('night_market_id', $data['night_market_id'])->orderBy('id')->lockForUpdate()->get(['id']);
                Stall::query()->where('night_market_id', $data['night_market_id'])->orderBy('id')->lockForUpdate()->get(['id']);
                Food::query()->whereIn('id', $snapshot['candidate_ids'])->orderBy('id')->lockForUpdate()->get(['id']);
                $fresh = $this->catalog->candidates($snapshot['preferences'], $snapshot['date'], $snapshot['candidate_ids']);
                if ($this->catalog->version($fresh) !== $snapshot['version']) {
                    throw ValidationException::withMessages(['snapshot_id' => 'Catalog information changed. Generate again to review current prices and availability.']);
                }
                $selected = $this->catalog->validateSelection(array_map('intval', $data['food_ids']), (int) $data['night_market_id'], $fresh, $snapshot['preferences']);
                // A durable claim before mutation fails closed if a process dies
                // between commit and writing its receipt. It never blindly retries.
                if (! $this->guard->cache()->put($receiptKey, 'saving', 86400)) {
                    throw ValidationException::withMessages(['snapshot_id' => 'Could not protect this save from duplicate submission. Please try later.']);
                }
                $plan = $this->plans->createForClient($user, [
                    'title' => $data['title'], 'night_market_id' => $data['night_market_id'],
                    'visit_date' => $snapshot['date'], 'notes' => $snapshot['preferences']['preference_notes'] ?? null,
                ]);
                foreach ($selected as $food) {
                    $this->plans->addItemForClient($user, $plan->id, ['item_type' => 'food', 'food_id' => $food->id]);
                }
                DB::afterCommit(fn () => $this->guard->cache()->put($receiptKey, $plan->id, 86400));

                return $plan;
            });
        });
    }

    private function key(User $user, string $token): string
    {
        return 'planner-snapshot:'.$user->id.':'.$token;
    }
}
