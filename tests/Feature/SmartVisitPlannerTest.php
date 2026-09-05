<?php

namespace Tests\Feature;

use App\Models\Food;
use App\Models\MarketOperatingDay;
use App\Models\NightMarket;
use App\Models\Review;
use App\Models\ReviewTag;
use App\Models\Stall;
use App\Models\User;
use App\Models\VisitPlan;
use App\Services\SmartVisitPlannerService;
use App\Support\SmartPlannerTemplate;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class SmartVisitPlannerTest extends TestCase
{
    use DatabaseTransactions;

    private User $client;

    private Carbon $visitDate;

    public function createApplication()
    {
        $app = parent::createApplication();
        $connection = config('database.connections.'.config('database.default'));
        if (! $app->environment('testing') || config('database.default') !== 'mysql'
            || $connection['database'] !== 'night_market_laravel_testing' || $connection['host'] !== '127.0.0.1'
            || (string) $connection['port'] !== '3306' || ! empty($connection['url']) || ! empty($connection['unix_socket'])
            || ! empty($connection['read']) || ! empty($connection['write'])) {
            throw new \RuntimeException('Isolated local testing database required. No schema commands are allowed.');
        }
        if (DB::selectOne('SELECT DATABASE() AS db')->db !== 'night_market_laravel_testing') {
            throw new \RuntimeException('Actual testing database does not match.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake([]);
        Mail::fake();
        Notification::fake();
        config(['cache.stores.file.driver' => 'array']);

        // DatabaseTransactions has already begun. Isolate every test's catalog
        // from retained acceptance records without deleting them or changing
        // timestamps/events. The normal teardown rolls this update back too.
        $this->assertGreaterThan(0, DB::connection()->transactionLevel());
        DB::table('night_markets')->where('status', NightMarket::STATUS_ACTIVE)
            ->update(['status' => NightMarket::STATUS_INACTIVE]);

        Carbon::setTestNow('2026-08-20 10:00:00');
        $this->visitDate = now()->next('Sunday');
        $this->client = User::factory()->create([
            'role' => User::ROLE_CLIENT,
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_smart_planner_routes_enforce_all_client_access_requirements(): void
    {
        $url = route('client.visit-plans.smart-planner.index');
        $unverified = User::factory()->unverified()->create(['role' => User::ROLE_CLIENT]);
        $inactive = User::factory()->create(['role' => User::ROLE_CLIENT, 'is_active' => false]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->get($url)->assertRedirect(route('login'))->assertSessionHas('url.intended', $url);
        $this->post(route('client.visit-plans.smart-planner.recommend'), $this->preferences())->assertRedirect(route('login'));
        $this->post(route('client.visit-plans.smart-planner.store'), [])->assertRedirect(route('login'));
        $this->actingAs($unverified)->get($url)->assertRedirect(route('verification.notice'));
        $this->actingAs($unverified)
            ->post(route('client.visit-plans.smart-planner.recommend'), $this->preferences())
            ->assertRedirect(route('verification.notice'));
        $this->actingAs($inactive)->get($url)->assertRedirect(route('login'));
        $this->assertGuest();
        $this->actingAs($inactive)
            ->post(route('client.visit-plans.smart-planner.recommend'), $this->preferences())
            ->assertRedirect(route('login'));
        $this->assertGuest();

        $this->actingAs($admin)->get($url)->assertForbidden();
        $this->actingAs($admin)->post(route('client.visit-plans.smart-planner.recommend'), $this->preferences())->assertForbidden();
        $this->actingAs($admin)->post(route('client.visit-plans.smart-planner.store'), [])->assertForbidden();

        $this->actingAs($this->client)->get($url)
            ->assertOk()->assertSee('Smart Visit Planner')->assertSee('Suggestions use catalog information and fixed rules.');
    }

    public function test_first_load_defaults_to_the_nearest_active_public_market_date(): void
    {
        $this->marketWithFood('Monday Default Market', day: 'Monday');
        $this->marketWithFood('Saturday Default Market', day: 'Saturday');
        [$hiddenMarket] = $this->marketWithFood('Friday Hidden Market', day: 'Friday');
        $hiddenMarket->update(['status' => NightMarket::STATUS_INACTIVE]);

        $this->actingAs($this->client)
            ->get(route('client.visit-plans.smart-planner.index'))
            ->assertOk()
            ->assertViewHas('preferences.visit_date', now()->next('Saturday')->toDateString());
    }

    public function test_matching_requested_date_is_preserved_without_a_fallback_section(): void
    {
        [$market] = $this->marketWithFood('Requested Date Market');

        $this->actingAs($this->client)
            ->post(route('client.visit-plans.smart-planner.recommend'), $this->preferences())
            ->assertOk()
            ->assertSee('Your Requested Date')
            ->assertSee('Sunday, 23 Aug 2026')
            ->assertSee($market->name)
            ->assertDontSee('Recommended Visit Date')
            ->assertViewHas('plannerResult', fn (array $result): bool => ! $result['uses_fallback']
                && $result['requested_date'] === $this->visitDate->toDateString()
                && $result['recommendation_date'] === $this->visitDate->toDateString());
    }

    public function test_no_operating_market_uses_the_nearest_genuine_fallback_within_fourteen_days(): void
    {
        [$market] = $this->marketWithFood('Nearest Sunday Market');
        $requestedDate = now()->next('Saturday');

        $this->actingAs($this->client)
            ->post(route('client.visit-plans.smart-planner.recommend'), $this->preferences([
                'visit_date' => $requestedDate->toDateString(),
            ]))
            ->assertOk()
            ->assertSee('Your Requested Date')
            ->assertSee('Saturday, 22 Aug 2026')
            ->assertSee('Recommended Visit Date')
            ->assertSee('No public Night Market operates on Saturday, 22 Aug 2026.')
            ->assertSee('The nearest suitable option is')
            ->assertSee($market->name)
            ->assertSee('Sunday, 23 Aug 2026')
            ->assertSee('Use Recommended Date and Create Plan')
            ->assertViewHas('plannerResult', fn (array $result): bool => $result['uses_fallback']
                && $result['requested_date'] === $requestedDate->toDateString()
                && $result['recommendation_date'] === $this->visitDate->toDateString());
    }

    public function test_fallback_continues_to_apply_every_selected_preference(): void
    {
        [$market, , $food] = $this->marketWithFood('Exact Fallback Market', [
            'city' => 'Petaling Jaya',
        ], [
            'halal_status' => Stall::HALAL_CERTIFIED,
        ], [
            'name' => 'Exact Fallback Snack',
            'category' => 'Snacks',
            'is_must_try' => true,
            'price_min' => 10,
            'price_max' => 15,
        ]);
        $this->marketWithFood('Wrong Fallback Market', ['city' => 'Shah Alam'], [
            'halal_status' => Stall::HALAL_UNKNOWN,
        ], [
            'category' => 'Drinks',
            'is_must_try' => false,
            'price_min' => 40,
            'price_max' => 50,
        ]);

        $response = $this->actingAs($this->client)->post(
            route('client.visit-plans.smart-planner.recommend'),
            $this->preferences([
                'visit_date' => now()->next('Saturday')->toDateString(),
                'city' => 'Petaling Jaya',
                'night_market_id' => $market->id,
                'categories' => ['Snacks'],
                'halal_preference' => Stall::HALAL_CERTIFIED,
                'must_try' => true,
                'budget_min' => 5,
                'budget_max' => 20,
            ]),
        );

        $response->assertOk()
            ->assertSee($market->name)
            ->assertSee($food->name)
            ->assertViewHas('recommendations', fn (array $recommendations): bool => collect($recommendations)
                ->pluck('market.id')->all() === [$market->id]);
    }

    public function test_preference_validation_rejects_invalid_dates_budgets_categories_preferences_and_limits(): void
    {
        $market = NightMarket::factory()->inactive()->create(['city' => 'Hidden City']);

        $this->actingAs($this->client)
            ->post(route('client.visit-plans.smart-planner.recommend'), [
                'visit_date' => now()->subDay()->toDateString(),
                'city' => 'Not a public city',
                'night_market_id' => $market->id,
                'budget_min' => 50,
                'budget_max' => 10,
                'categories' => 'not-an-array',
                'halal_preference' => 'invented',
                'must_try' => 'sometimes',
                'max_markets' => 4,
                'preference_notes' => Str::repeat('a', 1001),
            ])
            ->assertSessionHasErrors([
                'visit_date', 'city', 'night_market_id', 'budget_min', 'budget_max', 'categories',
                'halal_preference', 'must_try', 'max_markets', 'preference_notes',
            ]);

        $this->actingAs($this->client)
            ->post(route('client.visit-plans.smart-planner.recommend'), [
                ...$this->preferences(),
                'budget_min' => 10,
                'categories' => ['Invented Category'],
            ])
            ->assertSessionHasErrors(['budget_max', 'categories.0']);
    }

    public function test_only_public_selangor_markets_operating_on_selected_date_and_public_descendants_are_recommended(): void
    {
        [$eligibleMarket, $eligibleStall, $eligibleFood] = $this->marketWithFood('Eligible Sunday Market');
        [$wrongDayMarket] = $this->marketWithFood('Monday Market', day: 'Monday');
        [$inactiveMarket] = $this->marketWithFood('Inactive Market');
        $inactiveMarket->update(['status' => NightMarket::STATUS_INACTIVE]);
        [$outsideMarket] = $this->marketWithFood('Outside Market');
        $outsideMarket->update(['state' => 'Kuala Lumpur']);
        $inactiveStall = Stall::factory()->inactive()->create(['night_market_id' => $eligibleMarket->id, 'name' => 'Hidden Stall']);
        Food::factory()->create(['stall_id' => $inactiveStall->id, 'name' => 'Hidden Stall Food']);
        Food::factory()->inactive()->create(['stall_id' => $eligibleStall->id, 'name' => 'Hidden Food']);

        $response = $this->actingAs($this->client)
            ->post(route('client.visit-plans.smart-planner.recommend'), $this->preferences());

        $response->assertOk()
            ->assertSee($eligibleMarket->name)->assertSee($eligibleFood->name)
            ->assertDontSee('Hidden Stall')->assertDontSee('Hidden Food')
            ->assertViewHas('recommendations', function (array $recommendations) use (
                $eligibleMarket,
                $wrongDayMarket,
                $inactiveMarket,
                $outsideMarket,
            ): bool {
                $marketIds = collect($recommendations)->pluck('market.id');

                return $marketIds->all() === [$eligibleMarket->id]
                    && ! $marketIds->contains($wrongDayMarket->id)
                    && ! $marketIds->contains($inactiveMarket->id)
                    && ! $marketIds->contains($outsideMarket->id);
            });
    }

    public function test_city_market_category_halal_must_try_and_budget_preferences_are_applied_exactly(): void
    {
        [$market, $stall, $food] = $this->marketWithFood('Preferred Market', [
            'city' => 'Petaling Jaya',
        ], [
            'halal_status' => Stall::HALAL_CERTIFIED,
        ], [
            'name' => 'Budget Must-Try Snack',
            'category' => 'Snacks',
            'is_must_try' => true,
            'price_min' => 10,
            'price_max' => 15,
        ]);
        Food::factory()->create([
            'stall_id' => $stall->id,
            'name' => 'Wrong Category Food',
            'category' => 'Drinks',
            'is_must_try' => true,
            'price_min' => 10,
            'price_max' => 15,
        ]);
        [$otherMarket] = $this->marketWithFood('Other City Market', ['city' => 'Shah Alam']);

        $response = $this->actingAs($this->client)->post(
            route('client.visit-plans.smart-planner.recommend'),
            $this->preferences([
                'city' => 'Petaling Jaya',
                'night_market_id' => $market->id,
                'categories' => ['Snacks'],
                'halal_preference' => Stall::HALAL_CERTIFIED,
                'must_try' => true,
                'budget_min' => 5,
                'budget_max' => 20,
            ]),
        );

        $response->assertOk()
            ->assertSee($market->name)->assertSee($food->name)
            ->assertDontSee('Wrong Category Food')
            ->assertSee('Operates on Sunday')->assertSee('matches Petaling Jaya')
            ->assertSee('Halal-certified')->assertSee('Must-Try')
            ->assertSee('fits the selected budget')
            ->assertViewHas('recommendations', fn (array $recommendations): bool => collect($recommendations)
                ->pluck('market.id')->all() === [$market->id]
                && ! collect($recommendations)->pluck('market.id')->contains($otherMarket->id));
    }

    public function test_unknown_prices_are_truthful_excluded_from_totals_and_never_treated_as_zero(): void
    {
        [$market, $stall, $knownFood] = $this->marketWithFood('Mixed Price Market', food: [
            'name' => 'Known Price Food',
            'price_min' => 10,
            'price_max' => 15,
        ]);
        $unknownFood = Food::factory()->create([
            'stall_id' => $stall->id,
            'name' => 'Unknown Price Food',
            'price_min' => null,
            'price_max' => null,
            'price_display' => 'Ask vendor',
        ]);

        $response = $this->actingAs($this->client)->post(
            route('client.visit-plans.smart-planner.recommend'),
            $this->preferences([
                'night_market_id' => $market->id,
                'budget_min' => 5,
                'budget_max' => 20,
            ]),
        );

        $response->assertOk()
            ->assertSee($knownFood->name)->assertSee($unknownFood->name)
            ->assertSee('RM10.00–RM15.00')->assertSee('Price not available')
            ->assertDontSee('Ask vendor')
            ->assertSee('excluded from the estimated total')
            ->assertDontSee('RM0.00')->assertDontSee('within budget');
    }

    public function test_unknown_halal_preference_is_exact_and_never_claimed_as_certified(): void
    {
        [$unknownMarket, , $unknownFood] = $this->marketWithFood('Unknown Halal Market', stall: [
            'halal_status' => Stall::HALAL_UNKNOWN,
        ]);
        [$certifiedMarket] = $this->marketWithFood('Certified Market', stall: [
            'halal_status' => Stall::HALAL_CERTIFIED,
        ]);

        $response = $this->actingAs($this->client)->post(
            route('client.visit-plans.smart-planner.recommend'),
            $this->preferences(['halal_preference' => Stall::HALAL_UNKNOWN]),
        );

        $response->assertOk()
            ->assertSee($unknownMarket->name)->assertSee($unknownFood->name)
            ->assertSee('Halal status not verified')
            ->assertViewHas('recommendations', function (array $recommendations) use ($unknownMarket, $certifiedMarket): bool {
                $marketIds = collect($recommendations)->pluck('market.id');
                $halalStatuses = collect($recommendations)
                    ->flatMap(fn (array $recommendation) => collect($recommendation['stalls'])->pluck('stall.halal_status'));

                return $marketIds->all() === [$unknownMarket->id]
                    && ! $marketIds->contains($certifiedMarket->id)
                    && $halalStatuses->every(fn (string $status): bool => $status === Stall::HALAL_UNKNOWN);
            });
    }

    public function test_recommendation_order_is_stable_by_score_market_name_and_food_name(): void
    {
        [$betaMarket, $betaStall] = $this->marketWithFood('Beta Market', food: ['name' => 'Zulu Food']);
        Food::factory()->create(['stall_id' => $betaStall->id, 'name' => 'Alpha Food']);
        [$alphaMarket] = $this->marketWithFood('Alpha Market', food: ['name' => 'Middle Food']);

        $response = $this->actingAs($this->client)
            ->post(route('client.visit-plans.smart-planner.recommend'), $this->preferences());

        $response->assertOk()
            ->assertSeeInOrder([$alphaMarket->name, $betaMarket->name])
            ->assertSeeInOrder(['Alpha Food', 'Zulu Food']);
    }

    public function test_no_fallback_within_fourteen_days_has_a_truthful_empty_state(): void
    {
        $this->actingAs($this->client)
            ->post(route('client.visit-plans.smart-planner.recommend'), $this->preferences())
            ->assertOk()
            ->assertSee('No public Night Market operates on Sunday, 23 Aug 2026.')
            ->assertSee('No matching option exists within the next 14 days.')
            ->assertViewHas('plannerResult', fn (array $result): bool => $result['fallback_exhausted']
                && $result['recommendation_date'] === null
                && $result['recommendations'] === []);
    }

    public function test_empty_results_explain_market_food_and_budget_root_causes(): void
    {
        [$selectedMarket] = $this->marketWithFood('Monday Selected Market', day: 'Monday');
        $this->marketWithFood('Sunday Operating Market');

        $this->actingAs($this->client)
            ->post(route('client.visit-plans.smart-planner.recommend'), $this->preferences([
                'night_market_id' => $selectedMarket->id,
            ]))
            ->assertOk()
            ->assertSee('none matches your selected city or Night Market.');

        [$foodMarket] = $this->marketWithFood('Food Reason Market', food: ['category' => 'Drinks']);
        $this->marketWithFood('Category Source Market', food: ['category' => 'Snacks'], day: 'Monday');
        $this->actingAs($this->client)
            ->post(route('client.visit-plans.smart-planner.recommend'), $this->preferences([
                'night_market_id' => $foodMarket->id,
                'categories' => ['Snacks'],
            ]))
            ->assertOk()
            ->assertSee('no public Food matches your category, Halal, or Must-Try preferences.');

        [$budgetMarket] = $this->marketWithFood('Budget Reason Market', food: [
            'price_min' => 40,
            'price_max' => 50,
        ]);
        $this->actingAs($this->client)
            ->post(route('client.visit-plans.smart-planner.recommend'), $this->preferences([
                'night_market_id' => $budgetMarket->id,
                'budget_min' => 5,
                'budget_max' => 20,
            ]))
            ->assertOk()
            ->assertSee('none with a complete known price range fits your selected budget.');
    }

    public function test_fallback_date_requires_explicit_confirmation_and_creates_only_on_confirmed_date(): void
    {
        [$market, $stall, $food] = $this->marketWithFood('Confirmed Fallback Market');
        $requestedDate = now()->next('Saturday');
        $data = $this->createPlanData($market, $stall, $food, [
            'requested_date' => $requestedDate->toDateString(),
            'visit_date' => $this->visitDate->toDateString(),
        ]);

        $this->actingAs($this->client)
            ->post(route('client.visit-plans.smart-planner.store'), $data)
            ->assertSessionHasErrors('confirmed_fallback_date');
        $this->assertDatabaseCount('visit_plans', 0);

        $this->actingAs($this->client)
            ->post(route('client.visit-plans.smart-planner.store'), [
                ...$data,
                'confirmed_fallback_date' => true,
            ])
            ->assertRedirect();

        $plan = VisitPlan::query()->sole();
        $this->assertSame($this->visitDate->toDateString(), $plan->visit_date->toDateString());
        $this->assertNotSame($requestedDate->toDateString(), $plan->visit_date->toDateString());
    }

    public function test_confirmed_fallback_date_and_targets_are_recomputed_and_cannot_be_forged(): void
    {
        [$market, $stall, $food] = $this->marketWithFood('Recomputed Fallback Market');
        [, $otherStall, $otherFood] = $this->marketWithFood('Other Secure Market');
        $data = $this->createPlanData($market, $stall, $food, [
            'requested_date' => now()->next('Saturday')->toDateString(),
            'visit_date' => now()->next('Monday')->toDateString(),
            'confirmed_fallback_date' => true,
            'score' => 999999,
            'stall_ids' => [$stall->id, $otherStall->id],
            'food_ids' => [$food->id, $otherFood->id],
        ]);

        $this->actingAs($this->client)
            ->post(route('client.visit-plans.smart-planner.store'), $data)
            ->assertSessionHasErrors('visit_date');

        $this->assertDatabaseCount('visit_plans', 0);
    }

    public function test_create_from_recommendation_is_owned_revalidated_and_ignores_tampered_authority_fields(): void
    {
        [$market, $stall, $food] = $this->marketWithFood('Create Plan Market');
        $otherUser = User::factory()->create();
        $data = $this->createPlanData($market, $stall, $food, [
            'user_id' => $otherUser->id,
            'status' => 'admin',
            'score' => 999999,
            'estimated_total' => 0,
            'redirect_url' => 'https://example.test/unsafe',
        ]);

        $this->actingAs($this->client)
            ->post(route('client.visit-plans.smart-planner.store'), $data)
            ->assertRedirect();

        $plan = VisitPlan::query()->where('title', $data['title'])->firstOrFail();
        $this->assertSame($this->client->id, $plan->user_id);
        $this->assertSame($market->id, $plan->night_market_id);
        $this->assertDatabaseHas('visit_plan_items', ['visit_plan_id' => $plan->id, 'stall_id' => $stall->id]);
        $this->assertDatabaseHas('visit_plan_items', ['visit_plan_id' => $plan->id, 'food_id' => $food->id]);
        $this->assertSame(2, $plan->items()->count());
    }

    public function test_create_rejects_duplicate_and_tampered_targets_without_creating_a_plan(): void
    {
        [$market, $stall, $food] = $this->marketWithFood('Protected Recommendation Market');
        [, $otherStall, $otherFood] = $this->marketWithFood('Other Recommendation Market');

        $duplicateData = $this->createPlanData($market, $stall, $food);
        $duplicateData['food_ids'] = [$food->id, $food->id];
        $this->actingAs($this->client)
            ->post(route('client.visit-plans.smart-planner.store'), $duplicateData)
            ->assertSessionHasErrors('food_ids.0');

        $tamperedData = $this->createPlanData($market, $stall, $food);
        $tamperedData['stall_ids'][] = $otherStall->id;
        $tamperedData['food_ids'][] = $otherFood->id;
        $this->actingAs($this->client)
            ->post(route('client.visit-plans.smart-planner.store'), $tamperedData)
            ->assertSessionHasErrors('food_ids');

        $this->assertDatabaseCount('visit_plans', 0);
        $this->assertDatabaseCount('visit_plan_items', 0);
    }

    public function test_target_becoming_unavailable_rejects_the_entire_creation_safely(): void
    {
        [$market, $stall, $food] = $this->marketWithFood('Changing Recommendation Market');
        $data = $this->createPlanData($market, $stall, $food);
        $food->update(['status' => Food::STATUS_INACTIVE]);

        $this->actingAs($this->client)
            ->post(route('client.visit-plans.smart-planner.store'), $data)
            ->assertSessionHasErrors('night_market_id');

        $this->assertDatabaseCount('visit_plans', 0);
        $this->assertDatabaseCount('visit_plan_items', 0);
    }

    public function test_recommendation_query_count_does_not_grow_per_market_or_food(): void
    {
        $this->marketWithFood('Single Query Market');
        $singleResultQueries = $this->recommendationQueryCount();
        $singleFallbackQueries = $this->dateAwareRecommendationQueryCount();

        for ($index = 1; $index <= 4; $index++) {
            [, $stall] = $this->marketWithFood("Extra Market {$index}");
            Food::factory()->count(3)->create(['stall_id' => $stall->id]);
        }

        $manyResultQueries = $this->recommendationQueryCount();
        $manyFallbackQueries = $this->dateAwareRecommendationQueryCount();
        $this->assertSame($singleResultQueries, $manyResultQueries);
        $this->assertSame($singleFallbackQueries, $manyFallbackQueries);
    }

    public function test_market_options_require_complete_active_schedule_stall_and_food_chain(): void
    {
        [$eligibleMarket] = $this->marketWithFood('Eligible Planning Market');

        $noScheduleMarket = NightMarket::factory()->create(['name' => 'No Schedule Planning Market']);
        $noScheduleStall = Stall::factory()->create(['night_market_id' => $noScheduleMarket->id]);
        Food::factory()->create(['stall_id' => $noScheduleStall->id]);

        $noFoodMarket = NightMarket::factory()->create(['name' => 'No Food Planning Market']);
        MarketOperatingDay::factory()->create([
            'night_market_id' => $noFoodMarket->id,
            'day_of_week' => $this->visitDate->englishDayOfWeek,
        ]);
        Stall::factory()->create(['night_market_id' => $noFoodMarket->id]);

        $inactiveStallMarket = NightMarket::factory()->create(['name' => 'Inactive Stall Planning Market']);
        MarketOperatingDay::factory()->create([
            'night_market_id' => $inactiveStallMarket->id,
            'day_of_week' => $this->visitDate->englishDayOfWeek,
        ]);
        $inactiveStall = Stall::factory()->inactive()->create(['night_market_id' => $inactiveStallMarket->id]);
        Food::factory()->create(['stall_id' => $inactiveStall->id]);

        $this->actingAs($this->client)->get(route('client.visit-plans.smart-planner.index'))
            ->assertOk()
            ->assertSee($eligibleMarket->name)
            ->assertDontSee($noScheduleMarket->name)
            ->assertDontSee($noFoodMarket->name)
            ->assertDontSee($inactiveStallMarket->name);

        $this->actingAs($this->client)->post(route('client.visit-plans.smart-planner.recommend'), $this->preferences([
            'night_market_id' => $noFoodMarket->id,
        ]))->assertSessionHasErrors([
            'night_market_id' => 'The selected Night Market no longer has enough schedule, stall, and food data for planning.',
        ]);
    }

    public function test_empty_planner_uses_the_catalog_data_empty_state(): void
    {
        $this->actingAs($this->client)->get(route('client.visit-plans.smart-planner.index'))
            ->assertOk()
            ->assertSee('No markets currently have enough schedule, stall, and food data for planning.');
    }

    public function test_template_keys_are_whitelisted_and_a_tampered_key_is_rejected(): void
    {
        $this->assertSame([
            'quick_visit',
            'food_hunting',
            'family_friendly',
            'budget',
        ], SmartPlannerTemplate::KEYS);

        $this->marketWithFood('Template Market');

        $this->actingAs($this->client)
            ->get(route('client.visit-plans.smart-planner.index'))
            ->assertOk()
            ->assertSee('1-Hour Quick Visit')
            ->assertSee('Food Hunting Plan')
            ->assertSee('Family-Friendly Plan')
            ->assertSee('Budget Visit Plan');
        $this->actingAs($this->client)
            ->get(route('client.visit-plans.smart-planner.index', [
                'template' => SmartPlannerTemplate::BUDGET,
            ]))
            ->assertOk()
            ->assertSee('Template Active')
            ->assertSee('Budget Limit (RM)');

        $this->actingAs($this->client)
            ->post(route('client.visit-plans.smart-planner.recommend'), $this->preferences([
                'template' => 'untrusted-template',
            ]))
            ->assertSessionHasErrors('template');
    }

    public function test_quick_visit_template_limits_food_stops_and_creates_an_editable_plan(): void
    {
        [$market, $stall] = $this->marketWithFood('Quick Visit Market', food: [
            'name' => 'Quick First Food',
        ]);
        Food::factory()->count(3)->create(['stall_id' => $stall->id]);

        $preferences = $this->preferences([
            'template' => SmartPlannerTemplate::QUICK_VISIT,
            'night_market_id' => $market->id,
        ]);
        $response = $this->actingAs($this->client)
            ->post(route('client.visit-plans.smart-planner.recommend'), $preferences);

        $response->assertOk()
            ->assertSee('1-Hour Quick Visit')
            ->assertSee('about 20 minutes per stop')
            ->assertSee('one hour is not guaranteed')
            ->assertViewHas('recommendations', function (array $recommendations) use ($market): bool {
                $recommendation = $recommendations[0] ?? null;

                return $recommendation !== null
                    && $recommendation['market']->id === $market->id
                    && count($recommendation['foods']) <= 3
                    && $recommendation['stalls'] === [];
            });

        $recommendation = $response->viewData('recommendations')[0];
        $this->actingAs($this->client)
            ->post(route('client.visit-plans.smart-planner.store'), [
                ...$preferences,
                'requested_date' => $this->visitDate->toDateString(),
                'visit_date' => $this->visitDate->toDateString(),
                'title' => 'Quick Visit Plan',
                'food_ids' => collect($recommendation['foods'])->pluck('food.id')->all(),
            ])
            ->assertRedirect();

        $plan = VisitPlan::query()->where('title', 'Quick Visit Plan')->firstOrFail();
        $this->assertSame(count($recommendation['foods']), $plan->items()->whereNotNull('food_id')->count());
        $this->actingAs($this->client)
            ->patch(route('client.visit-plans.update', $plan), [
                'title' => 'Quick Visit Plan Updated',
                'night_market_id' => $market->id,
                'visit_date' => $this->visitDate->toDateString(),
                'notes' => 'This ordinary visit plan remains editable.',
            ])
            ->assertRedirect(route('client.visit-plans.show', $plan));
        $this->assertSame('Quick Visit Plan Updated', $plan->fresh()->title);
    }

    public function test_food_hunting_template_prioritises_must_try_and_explains_active_food_fallback(): void
    {
        [$market, $firstStall, $mustTryFood] = $this->marketWithFood('Food Hunting Market', food: [
            'name' => 'Only Must Try Food',
            'category' => 'Snacks',
            'is_must_try' => true,
        ]);
        $secondStall = Stall::factory()->create(['night_market_id' => $market->id]);
        $thirdStall = Stall::factory()->create(['night_market_id' => $market->id]);
        Food::factory()->create(['stall_id' => $secondStall->id, 'category' => 'Drinks', 'is_must_try' => false]);
        Food::factory()->create(['stall_id' => $thirdStall->id, 'category' => 'Dessert', 'is_must_try' => false]);
        Food::factory()->create(['stall_id' => $firstStall->id, 'category' => 'Grilled', 'is_must_try' => false]);

        $response = $this->actingAs($this->client)
            ->post(route('client.visit-plans.smart-planner.recommend'), $this->preferences([
                'template' => SmartPlannerTemplate::FOOD_HUNTING,
                'night_market_id' => $market->id,
            ]));

        $response->assertOk()
            ->assertSee('Food Hunting Plan')
            ->assertSee('Only 1 Must-Try Food was available, so')
            ->assertViewHas('recommendations', function (array $recommendations) use ($mustTryFood): bool {
                $foods = $recommendations[0]['foods'] ?? [];
                $foodIds = collect($foods)->pluck('food.id');
                $stallIds = collect($foods)->pluck('stall.id')->unique();
                $categories = collect($foods)->pluck('food.category')->filter()->unique();

                return count($foods) <= 5
                    && $foodIds->contains($mustTryFood->id)
                    && $stallIds->count() >= 3
                    && $categories->count() >= 3;
            });
    }

    public function test_family_friendly_template_uses_review_tag_signals_and_transparent_fallback(): void
    {
        [$taggedMarket, , $taggedFood] = $this->marketWithFood('Tagged Family Market', food: [
            'name' => 'Tagged Family Food',
        ]);
        $familyTag = ReviewTag::query()->where('name', 'Family-Friendly')->firstOrFail();
        $review = Review::factory()->approved()->forFood($taggedFood)->create(['rating' => 5]);
        $review->tags()->sync([$familyTag->id]);

        $this->actingAs($this->client)
            ->post(route('client.visit-plans.smart-planner.recommend'), $this->preferences([
                'template' => SmartPlannerTemplate::FAMILY_FRIENDLY,
                'night_market_id' => $taggedMarket->id,
            ]))
            ->assertOk()
            ->assertSee('Public Family-Friendly review tags informed this short, varied plan.')
            ->assertSee('do not verify children’s facilities, safety, or accessibility.');

        [$fallbackMarket] = $this->marketWithFood('Fallback Family Market');
        $this->actingAs($this->client)
            ->post(route('client.visit-plans.smart-planner.recommend'), $this->preferences([
                'template' => SmartPlannerTemplate::FAMILY_FRIENDLY,
                'night_market_id' => $fallbackMarket->id,
            ]))
            ->assertOk()
            ->assertSee('No verified family-friendly tag data was available; this is a general short and varied plan.');
    }

    public function test_budget_template_uses_numeric_price_maximums_only_and_never_exceeds_budget(): void
    {
        [$market, $stall] = $this->marketWithFood('Budget Template Market', food: [
            'name' => 'Budget Food One',
            'price_min' => 5,
            'price_max' => 10,
        ]);
        Food::factory()->create([
            'stall_id' => $stall->id,
            'name' => 'Budget Food Two',
            'price_min' => 8,
            'price_max' => 15,
        ]);
        Food::factory()->create([
            'stall_id' => $stall->id,
            'name' => 'Display Price Only Food',
            'price_min' => null,
            'price_max' => null,
            'price_display' => 'RM1 perhaps',
        ]);

        $response = $this->actingAs($this->client)
            ->post(route('client.visit-plans.smart-planner.recommend'), $this->preferences([
                'template' => SmartPlannerTemplate::BUDGET,
                'night_market_id' => $market->id,
                'budget_min' => 0,
                'budget_max' => 20,
            ]));

        $response->assertOk()
            ->assertSee('Budget Visit Plan')
            ->assertSee('price display text was not used to estimate costs.')
            ->assertDontSee('Display Price Only Food')
            ->assertViewHas('recommendations', function (array $recommendations): bool {
                $foods = $recommendations[0]['foods'] ?? [];
                $total = collect($foods)->sum(fn (array $food) => (float) $food['food']->price_max);

                return $foods !== []
                    && $total <= 20
                    && collect($foods)->every(fn (array $food) => $food['food']->price_max !== null);
            });

        $tooSmall = $this->actingAs($this->client)
            ->post(route('client.visit-plans.smart-planner.recommend'), $this->preferences([
                'template' => SmartPlannerTemplate::BUDGET,
                'night_market_id' => $market->id,
                'budget_min' => 0,
                'budget_max' => 1,
            ]));
        $tooSmall->assertOk()->assertSee('No active Foods with a numeric price maximum fit the selected budget.');
    }

    public function test_templates_reject_an_ineligible_market_parent_chain(): void
    {
        $market = NightMarket::factory()->create(['name' => 'Incomplete Template Market']);
        MarketOperatingDay::factory()->create([
            'night_market_id' => $market->id,
            'day_of_week' => $this->visitDate->englishDayOfWeek,
        ]);
        Stall::factory()->create(['night_market_id' => $market->id]);

        $this->actingAs($this->client)
            ->post(route('client.visit-plans.smart-planner.recommend'), $this->preferences([
                'template' => SmartPlannerTemplate::QUICK_VISIT,
                'night_market_id' => $market->id,
            ]))
            ->assertSessionHasErrors('night_market_id');
    }

    /** @return array{NightMarket, Stall, Food} */
    private function marketWithFood(
        string $name,
        array $market = [],
        array $stall = [],
        array $food = [],
        string $day = 'Sunday',
    ): array {
        $marketModel = NightMarket::factory()->create([
            'name' => $name,
            'city' => 'Petaling Jaya',
            'state' => 'Selangor',
            'status' => NightMarket::STATUS_ACTIVE,
            ...$market,
        ]);
        MarketOperatingDay::factory()->create([
            'night_market_id' => $marketModel->id,
            'day_of_week' => $day,
            'opening_time' => '18:00',
            'closing_time' => '22:00',
        ]);
        $stallModel = Stall::factory()->create([
            'night_market_id' => $marketModel->id,
            'status' => Stall::STATUS_ACTIVE,
            'halal_status' => Stall::HALAL_UNKNOWN,
            ...$stall,
        ]);
        $foodModel = Food::factory()->create([
            'stall_id' => $stallModel->id,
            'status' => Food::STATUS_ACTIVE,
            'price_min' => null,
            'price_max' => null,
            'price_display' => null,
            ...$food,
        ]);

        return [$marketModel, $stallModel, $foodModel];
    }

    /** @return array<string, mixed> */
    private function preferences(array $overrides = []): array
    {
        return array_replace([
            'visit_date' => $this->visitDate->toDateString(),
            'halal_preference' => 'any',
            'must_try' => false,
            'max_markets' => 3,
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function createPlanData(NightMarket $market, Stall $stall, Food $food, array $overrides = []): array
    {
        return array_replace([
            ...$this->preferences(['night_market_id' => $market->id, 'max_markets' => 1]),
            'requested_date' => $this->visitDate->toDateString(),
            'title' => 'Recommended secure plan',
            'stall_ids' => [$stall->id],
            'food_ids' => [$food->id],
        ], $overrides);
    }

    private function recommendationQueryCount(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(SmartVisitPlannerService::class)->recommend($this->preferences());
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function dateAwareRecommendationQueryCount(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(SmartVisitPlannerService::class)->recommendDateAware($this->preferences([
            'visit_date' => now()->next('Saturday')->toDateString(),
        ]));
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
