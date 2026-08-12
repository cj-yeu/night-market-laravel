<?php

namespace Tests\Feature;

use App\Models\Food;
use App\Models\MarketOperatingDay;
use App\Models\NightMarket;
use App\Models\Stall;
use App\Models\User;
use App\Models\VisitPlan;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class VisitPlannerTest extends TestCase
{
    use DatabaseTransactions;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->create([
            'role' => User::ROLE_CLIENT,
            'is_active' => true,
        ]);
    }

    public function test_client_can_create_a_valid_visit_plan_for_an_active_selangor_market_on_its_operating_date(): void
    {
        [$market, $visitDate] = $this->marketWithUpcomingOperatingDate();

        $this->actingAs($this->client)
            ->post(route('client.visit-plans.store'), [
                'title' => 'Friday Food Adventure',
                'night_market_id' => $market->id,
                'visit_date' => $visitDate->toDateString(),
                'notes' => 'Try the grilled food first.',
            ])
            ->assertRedirect();

        $visitPlan = VisitPlan::where('user_id', $this->client->id)->firstOrFail();

        $this->assertSame('Friday Food Adventure', $visitPlan->title);
        $this->assertSame($market->id, $visitPlan->night_market_id);
        $this->assertSame($visitDate->toDateString(), $visitPlan->visit_date->toDateString());
    }

    public function test_required_title_market_and_date_validation_works(): void
    {
        $this->actingAs($this->client)
            ->post(route('client.visit-plans.store'), [])
            ->assertSessionHasErrors(['title', 'night_market_id', 'visit_date']);

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
            ->assertSee('Reset Search');

        $this->actingAs($this->client)
            ->get(route('client.visit-plans.index'))
            ->assertOk()
            ->assertSee($matchingPlan->title)
            ->assertSee($otherPlan->title);
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
            ])
            ->assertRedirect(route('client.visit-plans.show', $visitPlan));

        $this->assertDatabaseHas('visit_plans', [
            'id' => $visitPlan->id,
            'title' => 'Updated Visit Plan',
            'notes' => 'Updated notes.',
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

        $this->actingAs($this->client)
            ->post(route('client.visit-plans.items.store', $visitPlan), [
                'item_type' => 'stall',
                'item_id' => $stall->id,
                'notes' => 'Visit first.',
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

        $otherPlan = VisitPlan::factory()->create(['night_market_id' => $market->id]);
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
        $regularFood = Food::factory()->create(['stall_id' => $stall->id, 'is_must_try' => false]);

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
            ['item_type' => 'food', 'item_id' => $regularFood->id],
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
            ->assertSee('Selected Must-Try Foods')
            ->assertSee($food->name)
            ->assertSee('View Market')
            ->assertSee('Browse Market Stalls');
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

        $this->actingAs($this->client)
            ->delete(route('client.visit-plans.destroy', $visitPlan))
            ->assertRedirect(route('client.visit-plans.index'));

        $this->assertDatabaseMissing('visit_plan_items', ['id' => $item->id]);
        $this->assertDatabaseHas('stalls', ['id' => $stall->id]);
        $this->assertDatabaseHas('foods', ['id' => $food->id]);
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
}
