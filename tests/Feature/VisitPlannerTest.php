<?php

namespace Tests\Feature;

use App\Models\Food;
use App\Models\MarketOperatingDay;
use App\Models\NightMarket;
use App\Models\Stall;
use App\Models\User;
use App\Models\VisitPlan;
use App\Models\VisitPlanItem;
use App\Services\VisitPlanService;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VisitPlannerTest extends TestCase
{
    use DatabaseTransactions;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-20 10:00:00');

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

    public function test_planner_routes_enforce_authentication_verification_activity_and_client_role(): void
    {
        [$market, $visitDate] = $this->marketWithUpcomingOperatingDate();
        $plan = VisitPlan::factory()->create([
            'user_id' => $this->client->id,
            'night_market_id' => $market->id,
        ]);
        $item = VisitPlanItem::factory()->create(['visit_plan_id' => $plan->id]);
        $unverified = User::factory()->unverified()->create(['role' => User::ROLE_CLIENT]);
        $inactive = User::factory()->create(['role' => User::ROLE_CLIENT, 'is_active' => false]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $indexUrl = route('client.visit-plans.index');
        $this->get($indexUrl)->assertRedirect(route('login'))->assertSessionHas('url.intended', $indexUrl);
        $this->actingAs($unverified)->get($indexUrl)->assertRedirect(route('verification.notice'));
        $this->actingAs($inactive)->get($indexUrl)->assertRedirect(route('login'));
        $this->assertGuest();

        foreach ([
            route('client.visit-plans.index'),
            route('client.visit-plans.create'),
            route('client.visit-plans.show', $plan),
            route('client.visit-plans.edit', $plan),
        ] as $url) {
            $this->actingAs($admin)->get($url)->assertForbidden();
        }

        $this->actingAs($admin)->post(route('client.visit-plans.store'), [
            'title' => 'Forbidden plan',
            'night_market_id' => $market->id,
            'visit_date' => $visitDate->toDateString(),
        ])->assertForbidden();
        $this->actingAs($admin)->patch(route('client.visit-plans.update', $plan), [])->assertForbidden();
        $this->actingAs($admin)->delete(route('client.visit-plans.destroy', $plan))->assertForbidden();
        $this->actingAs($admin)->post(route('client.visit-plans.items.store', $plan), [])->assertForbidden();
        $this->actingAs($admin)
            ->delete(route('client.visit-plans.items.destroy', [$plan, $item]))
            ->assertForbidden();
    }

    public function test_client_can_create_a_valid_visit_plan_for_an_active_selangor_market_on_its_operating_date(): void
    {
        [$market, $visitDate] = $this->marketWithUpcomingOperatingDate();
        $otherUser = User::factory()->create();

        $this->actingAs($this->client)
            ->post(route('client.visit-plans.store'), [
                'title' => 'Friday Food Adventure',
                'night_market_id' => $market->id,
                'visit_date' => $visitDate->toDateString(),
                'notes' => 'Try the grilled food first.',
                'user_id' => $otherUser->id,
                'status' => 'completed',
            ])
            ->assertRedirect();

        $visitPlan = VisitPlan::where('user_id', $this->client->id)->firstOrFail();

        $this->assertSame('Friday Food Adventure', $visitPlan->title);
        $this->assertSame($market->id, $visitPlan->night_market_id);
        $this->assertSame($visitDate->toDateString(), $visitPlan->visit_date->toDateString());
        $this->assertSame($this->client->id, $visitPlan->user_id);
    }

    public function test_required_title_market_and_date_validation_works(): void
    {
        $this->actingAs($this->client)
            ->post(route('client.visit-plans.store'), [])
            ->assertSessionHasErrors(['title', 'night_market_id', 'visit_date']);

        $this->actingAs($this->client)
            ->post(route('client.visit-plans.store'), [
                'title' => ['invalid'],
                'night_market_id' => ['invalid'],
                'visit_date' => ['invalid'],
                'notes' => ['invalid'],
            ])
            ->assertSessionHasErrors(['title', 'night_market_id', 'visit_date', 'notes']);

        $this->assertDatabaseCount('visit_plans', 0);
    }

    public function test_visit_date_outside_market_operating_schedule_is_rejected(): void
    {
        $market = NightMarket::factory()->create();
        MarketOperatingDay::factory()->create([
            'night_market_id' => $market->id,
            'day_of_week' => 'Monday',
        ]);
        $tuesday = Carbon::now()->next('Tuesday');

        $this->actingAs($this->client)
            ->post(route('client.visit-plans.store'), [
                'title' => 'Wrong Day Plan',
                'night_market_id' => $market->id,
                'visit_date' => $tuesday->toDateString(),
            ])
            ->assertSessionHasErrors([
                'visit_date' => 'The selected night market does not operate on Tuesday. Choose one of its operating days.',
            ]);

        $this->assertDatabaseCount('visit_plans', 0);
    }

    public function test_inactive_or_non_selangor_market_cannot_be_used(): void
    {
        $visitDate = Carbon::now()->addWeek();
        $inactiveMarket = NightMarket::factory()->inactive()->create();
        $outsideMarket = NightMarket::factory()->create(['state' => 'Kuala Lumpur']);

        foreach ([$inactiveMarket, $outsideMarket] as $market) {
            MarketOperatingDay::factory()->create([
                'night_market_id' => $market->id,
                'day_of_week' => $visitDate->englishDayOfWeek,
            ]);

            $this->actingAs($this->client)
                ->post(route('client.visit-plans.store'), [
                    'title' => 'Ineligible Market Plan',
                    'night_market_id' => $market->id,
                    'visit_date' => $visitDate->toDateString(),
                ])
                ->assertSessionHasErrors('night_market_id');
        }

        $this->assertDatabaseCount('visit_plans', 0);
    }

    public function test_client_sees_only_own_visit_plans(): void
    {
        $ownPlan = VisitPlan::factory()->create([
            'user_id' => $this->client->id,
            'title' => 'My Visible Plan',
        ]);
        $otherPlan = VisitPlan::factory()->create(['title' => 'Another Client Plan']);

        $this->actingAs($this->client)
            ->get(route('client.visit-plans.index'))
            ->assertOk()
            ->assertSee($ownPlan->title)
            ->assertDontSee($otherPlan->title);
    }

    public function test_plan_search_persists_and_reset_returns_all_own_plans(): void
    {
        $matchingMarket = NightMarket::factory()->create(['name' => 'Searchable Market']);
        $otherMarket = NightMarket::factory()->create(['name' => 'Different Market']);
        $matchingPlan = VisitPlan::factory()->create([
            'user_id' => $this->client->id,
            'night_market_id' => $matchingMarket->id,
            'title' => 'Lantern Food Trip',
        ]);
        $otherPlan = VisitPlan::factory()->create([
            'user_id' => $this->client->id,
            'night_market_id' => $otherMarket->id,
            'title' => 'Weekend Visit',
        ]);

        $this->actingAs($this->client)
            ->get(route('client.visit-plans.index', ['search' => 'Searchable']))
            ->assertOk()
            ->assertSee($matchingPlan->title)
            ->assertDontSee($otherPlan->title)
            ->assertSee('value="Searchable"', false)
            ->assertSee('Reset Filters');

        $this->actingAs($this->client)
            ->get(route('client.visit-plans.index'))
            ->assertOk()
            ->assertSee($matchingPlan->title)
            ->assertSee($otherPlan->title);
    }

    public function test_search_status_filters_pagination_and_stable_date_ordering_are_safe(): void
    {
        $market = NightMarket::factory()->create();
        $today = VisitPlan::factory()->create([
            'user_id' => $this->client->id,
            'night_market_id' => $market->id,
            'title' => 'Today itinerary',
            'visit_date' => now()->toDateString(),
        ]);
        $upcoming = VisitPlan::factory()->create([
            'user_id' => $this->client->id,
            'night_market_id' => $market->id,
            'title' => 'Upcoming itinerary',
            'visit_date' => now()->addDay()->toDateString(),
        ]);
        $past = VisitPlan::factory()->create([
            'user_id' => $this->client->id,
            'night_market_id' => $market->id,
            'title' => 'Past itinerary',
            'visit_date' => now()->subDay()->toDateString(),
        ]);

        $this->actingAs($this->client)->get(route('client.visit-plans.index'))
            ->assertOk()
            ->assertSeeInOrder([$today->title, $upcoming->title, $past->title]);
        $this->actingAs($this->client)->get(route('client.visit-plans.index', ['status' => 'upcoming']))
            ->assertSee($upcoming->title)->assertDontSee($today->title)->assertDontSee($past->title);
        $this->actingAs($this->client)->get(route('client.visit-plans.index', ['status' => 'today']))
            ->assertSee($today->title)->assertDontSee($upcoming->title);
        $this->actingAs($this->client)->get(route('client.visit-plans.index', ['status' => 'past']))
            ->assertSee($past->title)->assertDontSee($upcoming->title);

        $literal = VisitPlan::factory()->create([
            'user_id' => $this->client->id,
            'night_market_id' => $market->id,
            'title' => 'Literal 100%_\\ planner search',
        ]);
        $wildcardMatch = VisitPlan::factory()->create([
            'user_id' => $this->client->id,
            'night_market_id' => $market->id,
            'title' => 'Literal 100AX planner search',
        ]);
        $this->actingAs($this->client)
            ->get(route('client.visit-plans.index', ['search' => '100%_\\']))
            ->assertSee($literal->title)->assertDontSee($wildcardMatch->title);

        VisitPlan::factory()->count(10)->create([
            'user_id' => $this->client->id,
            'night_market_id' => $market->id,
            'title' => 'Paged upcoming plan',
            'visit_date' => now()->addWeek()->toDateString(),
        ]);
        $pagination = $this->actingAs($this->client)->get(route('client.visit-plans.index', [
            'search' => 'Paged upcoming',
            'status' => 'upcoming',
        ]));
        $pagination->assertOk()->assertSee('search=Paged%20upcoming', false)->assertSee('status=upcoming', false);
    }

    public function test_plan_directory_eager_loading_does_not_add_queries_per_plan(): void
    {
        $market = NightMarket::factory()->create();
        VisitPlan::factory()->create([
            'user_id' => $this->client->id,
            'night_market_id' => $market->id,
        ]);

        $singlePlanQueries = $this->directoryQueryCount();
        VisitPlan::factory()->count(8)->create([
            'user_id' => $this->client->id,
            'night_market_id' => $market->id,
        ]);
        $ninePlanQueries = $this->directoryQueryCount();

        $this->assertSame($singlePlanQueries, $ninePlanQueries);
        $page = app(VisitPlanService::class)->plansForClient($this->client);
        $this->assertTrue($page->first()->relationLoaded('nightMarket'));
        $this->assertEqualsCanonicalizing(
            ['id', 'name', 'city', 'state', 'status'],
            array_keys($page->first()->nightMarket->getAttributes()),
        );
    }

    public function test_client_cannot_view_update_or_delete_another_clients_plan(): void
    {
        [$market, $visitDate] = $this->marketWithUpcomingOperatingDate();
        $otherPlan = VisitPlan::factory()->create([
            'night_market_id' => $market->id,
            'title' => 'Protected Plan',
        ]);
        $updateData = [
            'title' => 'Unauthorized Update',
            'night_market_id' => $market->id,
            'visit_date' => $visitDate->toDateString(),
        ];

        $this->actingAs($this->client)
            ->get(route('client.visit-plans.show', $otherPlan))
            ->assertNotFound();

        $this->actingAs($this->client)
            ->patch(route('client.visit-plans.update', $otherPlan), $updateData)
            ->assertNotFound();

        $this->actingAs($this->client)
            ->delete(route('client.visit-plans.destroy', $otherPlan))
            ->assertNotFound();

        $this->assertDatabaseHas('visit_plans', [
            'id' => $otherPlan->id,
            'title' => 'Protected Plan',
        ]);
    }

    public function test_client_can_update_and_delete_own_plan(): void
    {
        [$market, $visitDate] = $this->marketWithUpcomingOperatingDate();
        $otherUser = User::factory()->create();
        $visitPlan = VisitPlan::factory()->create([
            'user_id' => $this->client->id,
            'night_market_id' => $market->id,
        ]);

        $this->actingAs($this->client)
            ->patch(route('client.visit-plans.update', $visitPlan), [
                'title' => 'Updated Visit Plan',
                'night_market_id' => $market->id,
                'visit_date' => $visitDate->toDateString(),
                'notes' => 'Updated notes.',
                'user_id' => $otherUser->id,
                'status' => 'past',
            ])
            ->assertRedirect(route('client.visit-plans.show', $visitPlan));

        $this->assertDatabaseHas('visit_plans', [
            'id' => $visitPlan->id,
            'title' => 'Updated Visit Plan',
            'notes' => 'Updated notes.',
            'user_id' => $this->client->id,
        ]);

        $this->actingAs($this->client)
            ->delete(route('client.visit-plans.destroy', $visitPlan))
            ->assertRedirect(route('client.visit-plans.index'))
            ->assertSessionHas('status', 'Your visit plan was deleted successfully.');

        $this->assertDatabaseMissing('visit_plans', ['id' => $visitPlan->id]);
    }

    public function test_client_can_add_and_remove_only_valid_items_for_the_plan_market(): void
    {
        [$market] = $this->marketWithUpcomingOperatingDate();
        $visitPlan = VisitPlan::factory()->create([
            'user_id' => $this->client->id,
            'night_market_id' => $market->id,
        ]);
        $stall = Stall::factory()->create(['night_market_id' => $market->id, 'name' => 'Plan Stall']);
        $food = Food::factory()->mustTry()->create(['stall_id' => $stall->id, 'name' => 'Plan Food']);
        $otherStall = Stall::factory()->create(['name' => 'Wrong Market Stall']);
        $otherPlan = VisitPlan::factory()->create(['night_market_id' => $market->id]);

        $this->actingAs($this->client)
            ->post(route('client.visit-plans.items.store', $visitPlan), [
                'item_type' => 'stall',
                'item_id' => $stall->id,
                'notes' => 'Visit first.',
                'visit_plan_id' => $otherPlan->id,
                'user_id' => $otherPlan->user_id,
            ])
            ->assertRedirect(route('client.visit-plans.show', $visitPlan));

        $this->actingAs($this->client)
            ->post(route('client.visit-plans.items.store', $visitPlan), [
                'item_type' => 'food',
                'item_id' => $food->id,
            ])
            ->assertRedirect(route('client.visit-plans.show', $visitPlan));

        $this->assertDatabaseHas('visit_plan_items', [
            'visit_plan_id' => $visitPlan->id,
            'stall_id' => $stall->id,
            'food_id' => null,
            'item_type' => 'stall',
            'item_name' => 'Plan Stall',
        ]);
        $this->assertDatabaseMissing('visit_plan_items', [
            'visit_plan_id' => $otherPlan->id,
            'stall_id' => $stall->id,
        ]);

        $this->actingAs($this->client)
            ->post(route('client.visit-plans.items.store', $otherPlan), [
                'item_type' => 'stall',
                'item_id' => $stall->id,
            ])
            ->assertNotFound();
        $this->assertDatabaseHas('visit_plan_items', [
            'visit_plan_id' => $visitPlan->id,
            'stall_id' => null,
            'food_id' => $food->id,
            'item_type' => 'food',
            'item_name' => 'Plan Food',
        ]);

        $this->actingAs($this->client)
            ->post(route('client.visit-plans.items.store', $visitPlan), [
                'item_type' => 'stall',
                'item_id' => $otherStall->id,
            ])
            ->assertSessionHasErrors('item_id');

        $item = $visitPlan->items()->where('item_name', 'Plan Stall')->firstOrFail();

        $this->actingAs($this->client)
            ->delete(route('client.visit-plans.items.destroy', [$visitPlan, $item]))
            ->assertRedirect(route('client.visit-plans.show', $visitPlan));

        $this->assertDatabaseMissing('visit_plan_items', ['id' => $item->id]);

        $otherItem = $otherPlan->items()->create([
            'item_type' => 'stall',
            'item_name' => 'Protected Item',
            'sort_order' => 1,
        ]);

        $this->actingAs($this->client)
            ->delete(route('client.visit-plans.items.destroy', [$otherPlan, $otherItem]))
            ->assertNotFound();

        $this->assertDatabaseHas('visit_plan_items', ['id' => $otherItem->id]);
    }

    public function test_duplicate_and_cross_market_plan_items_are_rejected(): void
    {
        [$market] = $this->marketWithUpcomingOperatingDate();
        $visitPlan = VisitPlan::factory()->create([
            'user_id' => $this->client->id,
            'night_market_id' => $market->id,
        ]);
        $stall = Stall::factory()->create(['night_market_id' => $market->id]);
        $food = Food::factory()->mustTry()->create(['stall_id' => $stall->id]);
        $otherStall = Stall::factory()->create();
        $otherFood = Food::factory()->mustTry()->create(['stall_id' => $otherStall->id]);
        $inactiveFood = Food::factory()->inactive()->create(['stall_id' => $stall->id]);

        foreach ([
            ['item_type' => 'stall', 'item_id' => $stall->id],
            ['item_type' => 'food', 'item_id' => $food->id],
        ] as $itemData) {
            $this->actingAs($this->client)
                ->post(route('client.visit-plans.items.store', $visitPlan), $itemData)
                ->assertRedirect(route('client.visit-plans.show', $visitPlan));

            $this->actingAs($this->client)
                ->post(route('client.visit-plans.items.store', $visitPlan), $itemData)
                ->assertSessionHasErrors([
                    'item_id' => 'This item has already been added to the visit plan.',
                ]);
        }

        foreach ([
            ['item_type' => 'stall', 'item_id' => $otherStall->id],
            ['item_type' => 'food', 'item_id' => $otherFood->id],
            ['item_type' => 'food', 'item_id' => $inactiveFood->id],
        ] as $invalidItemData) {
            $this->actingAs($this->client)
                ->post(route('client.visit-plans.items.store', $visitPlan), $invalidItemData)
                ->assertSessionHasErrors('item_id');
        }

        $this->assertSame(2, $visitPlan->items()->count());
    }

    public function test_plan_detail_separates_selected_stalls_and_must_try_foods(): void
    {
        [$market] = $this->marketWithUpcomingOperatingDate();
        $visitPlan = VisitPlan::factory()->create([
            'user_id' => $this->client->id,
            'night_market_id' => $market->id,
        ]);
        $stall = Stall::factory()->create(['night_market_id' => $market->id, 'name' => 'Selected Plan Stall']);
        $food = Food::factory()->mustTry()->create(['stall_id' => $stall->id, 'name' => 'Selected Plan Food']);
        $visitPlan->items()->create([
            'stall_id' => $stall->id,
            'item_type' => 'stall',
            'item_name' => $stall->name,
            'sort_order' => 1,
        ]);
        $visitPlan->items()->create([
            'food_id' => $food->id,
            'item_type' => 'food',
            'item_name' => $food->name,
            'sort_order' => 2,
        ]);

        $this->actingAs($this->client)
            ->get(route('client.visit-plans.show', $visitPlan))
            ->assertOk()
            ->assertSee('Selected Stalls')
            ->assertSee($stall->name)
            ->assertSee('Selected Foods')
            ->assertSee($food->name)
            ->assertSee('View Market')
            ->assertSee('Browse Market Stalls');
    }

    public function test_unavailable_saved_catalog_records_do_not_leak_hidden_names_and_remain_removable(): void
    {
        [$market] = $this->marketWithUpcomingOperatingDate();
        $visitPlan = VisitPlan::factory()->create([
            'user_id' => $this->client->id,
            'night_market_id' => $market->id,
        ]);
        $stall = Stall::factory()->inactive()->create([
            'night_market_id' => $market->id,
            'name' => 'Hidden Inactive Stall Name',
        ]);
        $foodStall = Stall::factory()->inactive()->create(['night_market_id' => $market->id]);
        $food = Food::factory()->create([
            'stall_id' => $foodStall->id,
            'name' => 'Hidden Food Name',
        ]);
        $stallItem = $visitPlan->items()->create([
            'stall_id' => $stall->id,
            'item_type' => 'stall',
            'item_name' => $stall->name,
            'sort_order' => 1,
        ]);
        $visitPlan->items()->create([
            'food_id' => $food->id,
            'item_type' => 'food',
            'item_name' => $food->name,
            'sort_order' => 2,
        ]);

        $this->actingAs($this->client)->get(route('client.visit-plans.show', $visitPlan))
            ->assertOk()
            ->assertSee('No longer available')
            ->assertDontSee($stall->name)
            ->assertDontSee($food->name)
            ->assertSee(route('client.visit-plans.items.destroy', [$visitPlan, $stallItem]), false);

        $marketName = $market->name;
        $market->update(['status' => NightMarket::STATUS_INACTIVE]);
        $this->actingAs($this->client)->get(route('client.visit-plans.show', $visitPlan))
            ->assertSee('No longer available')->assertDontSee($marketName);
        $this->actingAs($this->client)->get(route('client.visit-plans.index'))
            ->assertSee('No longer available')->assertDontSee($marketName);
    }

    public function test_public_catalog_calls_to_action_use_intended_login_and_add_regular_public_food(): void
    {
        [$market] = $this->marketWithUpcomingOperatingDate();
        $stall = Stall::factory()->create(['night_market_id' => $market->id, 'name' => 'CTA Stall']);
        $food = Food::factory()->create([
            'stall_id' => $stall->id,
            'name' => 'Regular CTA Food',
            'is_must_try' => false,
        ]);
        $plan = VisitPlan::factory()->create([
            'user_id' => $this->client->id,
            'night_market_id' => $market->id,
        ]);
        $targetUrl = route('client.visit-plans.index', ['item_type' => 'food', 'item_id' => $food->id]);

        $this->get(route('foods.show', $food))->assertSee($targetUrl)->assertSee('Add to Visit Plan');
        $this->get($targetUrl)->assertRedirect(route('login'))->assertSessionHas('url.intended', function (string $url) use ($food): bool {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return parse_url($url, PHP_URL_PATH) === '/client/visit-plans'
                && ($query['item_type'] ?? null) === 'food'
                && ($query['item_id'] ?? null) === (string) $food->id;
        });

        $this->actingAs($this->client)->get($targetUrl)
            ->assertOk()->assertSee($food->name)->assertSee($plan->title)->assertSee('Add to This Plan');
        $this->actingAs($this->client)->post(route('client.visit-plans.items.store', $plan), [
            'item_type' => 'food',
            'item_id' => $food->id,
        ])->assertRedirect(route('client.visit-plans.show', $plan));
        $this->assertDatabaseHas('visit_plan_items', [
            'visit_plan_id' => $plan->id,
            'food_id' => $food->id,
        ]);

        $this->actingAs($this->client)
            ->get(route('client.visit-plans.create', ['night_market_id' => $market->id]))
            ->assertOk()->assertSee('value="'.$market->id.'"', false)->assertSee('selected', false);
        $this->get(route('stalls.index'))->assertSee(route('client.visit-plans.index', [
            'item_type' => 'stall',
            'item_id' => $stall->id,
        ]));
    }

    public function test_inactive_catalog_target_is_rejected_and_database_indexes_prevent_duplicates(): void
    {
        [$market] = $this->marketWithUpcomingOperatingDate();
        $plan = VisitPlan::factory()->create([
            'user_id' => $this->client->id,
            'night_market_id' => $market->id,
        ]);
        $inactiveStall = Stall::factory()->inactive()->create(['night_market_id' => $market->id]);
        $this->actingAs($this->client)->get(route('client.visit-plans.index', [
            'item_type' => 'stall',
            'item_id' => $inactiveStall->id,
        ]))->assertSessionHasErrors('item_id');

        $stall = Stall::factory()->create(['night_market_id' => $market->id]);
        VisitPlanItem::factory()->create([
            'visit_plan_id' => $plan->id,
            'stall_id' => $stall->id,
            'item_type' => 'stall',
        ]);

        try {
            VisitPlanItem::factory()->create([
                'visit_plan_id' => $plan->id,
                'stall_id' => $stall->id,
                'item_type' => 'stall',
            ]);
            $this->fail('The database accepted a duplicate plan and Stall pair.');
        } catch (UniqueConstraintViolationException) {
            $this->assertSame(1, $plan->items()->where('stall_id', $stall->id)->count());
        }
    }

    public function test_deleting_a_plan_removes_items_but_preserves_master_records(): void
    {
        [$market] = $this->marketWithUpcomingOperatingDate();
        $visitPlan = VisitPlan::factory()->create([
            'user_id' => $this->client->id,
            'night_market_id' => $market->id,
        ]);
        $stall = Stall::factory()->create(['night_market_id' => $market->id]);
        $food = Food::factory()->mustTry()->create(['stall_id' => $stall->id]);
        $item = $visitPlan->items()->create([
            'food_id' => $food->id,
            'item_type' => 'food',
            'item_name' => $food->name,
            'sort_order' => 1,
        ]);
        $otherPlan = VisitPlan::factory()->create(['night_market_id' => $market->id]);
        $otherItem = $otherPlan->items()->create([
            'stall_id' => $stall->id,
            'item_type' => 'stall',
            'item_name' => $stall->name,
            'sort_order' => 1,
        ]);

        $this->actingAs($this->client)
            ->delete(route('client.visit-plans.destroy', $visitPlan))
            ->assertRedirect(route('client.visit-plans.index'));

        $this->assertDatabaseMissing('visit_plan_items', ['id' => $item->id]);
        $this->assertDatabaseHas('stalls', ['id' => $stall->id]);
        $this->assertDatabaseHas('foods', ['id' => $food->id]);
        $this->assertDatabaseHas('visit_plans', ['id' => $otherPlan->id]);
        $this->assertDatabaseHas('visit_plan_items', ['id' => $otherItem->id]);
    }

    public function test_master_item_deletion_nulls_the_relation_and_preserves_legacy_name(): void
    {
        [$market] = $this->marketWithUpcomingOperatingDate();
        $visitPlan = VisitPlan::factory()->create([
            'user_id' => $this->client->id,
            'night_market_id' => $market->id,
        ]);
        $stall = Stall::factory()->create(['night_market_id' => $market->id, 'name' => 'Historical Stall']);
        $item = $visitPlan->items()->create([
            'stall_id' => $stall->id,
            'item_type' => 'stall',
            'item_name' => $stall->name,
            'sort_order' => 1,
        ]);

        $stall->delete();

        $this->assertDatabaseHas('visit_plan_items', [
            'id' => $item->id,
            'stall_id' => null,
            'item_name' => 'Historical Stall',
        ]);
    }

    private function marketWithUpcomingOperatingDate(): array
    {
        $visitDate = Carbon::now()->addWeek();
        $market = NightMarket::factory()->create([
            'status' => NightMarket::STATUS_ACTIVE,
            'state' => 'Selangor',
        ]);
        MarketOperatingDay::factory()->create([
            'night_market_id' => $market->id,
            'day_of_week' => $visitDate->englishDayOfWeek,
            'opening_time' => '18:00',
            'closing_time' => '22:00',
        ]);

        return [$market, $visitDate];
    }

    private function directoryQueryCount(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(VisitPlanService::class)->plansForClient($this->client);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
