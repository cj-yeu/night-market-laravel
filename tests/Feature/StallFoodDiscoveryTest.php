<?php

namespace Tests\Feature;

use App\Models\Food;
use App\Models\NightMarket;
use App\Models\Stall;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class StallFoodDiscoveryTest extends TestCase
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

    public function test_active_stalls_for_an_active_market_are_displayed(): void
    {
        $market = NightMarket::factory()->create();
        $stall = Stall::factory()->create([
            'night_market_id' => $market->id,
            'name' => 'Active Satay Stall',
        ]);
        $food = Food::factory()->mustTry()->create([
            'stall_id' => $stall->id,
            'name' => 'Chicken Satay',
        ]);

        $this->actingAs($this->client)
            ->get(route('client.night-markets.stalls.index', $market->id))
            ->assertOk()
            ->assertSee($market->name)
            ->assertSee($stall->name)
            ->assertSee($food->name)
            ->assertSee('Must-Try')
            ->assertSee('View Food Details');
    }

    public function test_inactive_stalls_are_hidden(): void
    {
        $market = NightMarket::factory()->create();
        $inactiveStall = Stall::factory()->inactive()->create([
            'night_market_id' => $market->id,
            'name' => 'Hidden Inactive Stall',
        ]);

        $this->actingAs($this->client)
            ->get(route('client.night-markets.stalls.index', $market->id))
            ->assertOk()
            ->assertDontSee($inactiveStall->name);
    }

    public function test_stalls_and_foods_from_inactive_markets_are_inaccessible(): void
    {
        $inactiveMarket = NightMarket::factory()->inactive()->create();
        $stall = Stall::factory()->create(['night_market_id' => $inactiveMarket->id]);
        $food = Food::factory()->create(['stall_id' => $stall->id]);

        $this->actingAs($this->client)
            ->get(route('client.night-markets.stalls.index', $inactiveMarket->id))
            ->assertNotFound();

        $this->actingAs($this->client)
            ->get(route('client.foods.show', $food->id))
            ->assertNotFound();
    }

    public function test_searching_by_stall_name_works(): void
    {
        $market = NightMarket::factory()->create();
        $matchingStall = Stall::factory()->create([
            'night_market_id' => $market->id,
            'name' => 'Pak Ali Satay',
        ]);
        $otherStall = Stall::factory()->create([
            'night_market_id' => $market->id,
            'name' => 'Sweet Drinks Corner',
        ]);

        $this->actingAs($this->client)
            ->get(route('client.night-markets.stalls.index', [
                'nightMarket' => $market->id,
                'search' => 'Pak Ali',
            ]))
            ->assertOk()
            ->assertSee($matchingStall->name)
            ->assertDontSee($otherStall->name);
    }

    public function test_searching_by_stall_description_works(): void
    {
        $market = NightMarket::factory()->create();
        $matchingStall = Stall::factory()->create([
            'night_market_id' => $market->id,
            'name' => 'Matching Description Stall',
            'description' => 'Traditional charcoal cooking specialists.',
        ]);
        $otherStall = Stall::factory()->create([
            'night_market_id' => $market->id,
            'name' => 'Unrelated Description Stall',
            'description' => 'Cold fruit drinks.',
        ]);

        $this->actingAs($this->client)
            ->get(route('client.night-markets.stalls.index', [
                'nightMarket' => $market->id,
                'search' => 'charcoal cooking',
            ]))
            ->assertOk()
            ->assertSee($matchingStall->name)
            ->assertDontSee($otherStall->name);
    }

    public function test_searching_by_food_name_works(): void
    {
        $market = NightMarket::factory()->create();
        $matchingStall = Stall::factory()->create(['night_market_id' => $market->id]);
        $otherStall = Stall::factory()->create(['night_market_id' => $market->id]);
        Food::factory()->create([
            'stall_id' => $matchingStall->id,
            'name' => 'Crispy Banana Fritters',
        ]);
        Food::factory()->create([
            'stall_id' => $otherStall->id,
            'name' => 'Iced Lemon Tea',
        ]);

        $this->actingAs($this->client)
            ->get(route('client.night-markets.stalls.index', [
                'nightMarket' => $market->id,
                'search' => 'Banana Fritters',
            ]))
            ->assertOk()
            ->assertSee($matchingStall->name)
            ->assertDontSee($otherStall->name);
    }

    public function test_searching_by_food_description_works(): void
    {
        $market = NightMarket::factory()->create();
        $matchingStall = Stall::factory()->create(['night_market_id' => $market->id]);
        $otherStall = Stall::factory()->create(['night_market_id' => $market->id]);
        Food::factory()->create([
            'stall_id' => $matchingStall->id,
            'description' => 'Handmade with a fragrant pandan filling.',
        ]);
        Food::factory()->create([
            'stall_id' => $otherStall->id,
            'description' => 'Freshly grilled over charcoal.',
        ]);

        $this->actingAs($this->client)
            ->get(route('client.night-markets.stalls.index', [
                'nightMarket' => $market->id,
                'search' => 'pandan filling',
            ]))
            ->assertOk()
            ->assertSee($matchingStall->name)
            ->assertDontSee($otherStall->name);
    }

    public function test_category_filter_works(): void
    {
        $market = NightMarket::factory()->create();
        $matchingStall = Stall::factory()->create(['night_market_id' => $market->id]);
        $otherStall = Stall::factory()->create(['night_market_id' => $market->id]);
        Food::factory()->create(['stall_id' => $matchingStall->id, 'category' => 'Dessert']);
        Food::factory()->create(['stall_id' => $otherStall->id, 'category' => 'Drinks']);

        $this->actingAs($this->client)
            ->get(route('client.night-markets.stalls.index', [
                'nightMarket' => $market->id,
                'category' => 'Dessert',
            ]))
            ->assertOk()
            ->assertSee($matchingStall->name)
            ->assertDontSee($otherStall->name);
    }

    public function test_combined_search_and_category_filter_work_together(): void
    {
        $market = NightMarket::factory()->create();
        $matchingStall = Stall::factory()->create([
            'night_market_id' => $market->id,
            'name' => 'Grill Master',
        ]);
        $wrongCategory = Stall::factory()->create([
            'night_market_id' => $market->id,
            'name' => 'Grill Desserts',
        ]);
        $wrongSearch = Stall::factory()->create([
            'night_market_id' => $market->id,
            'name' => 'Roasted Corner',
        ]);
        Food::factory()->create(['stall_id' => $matchingStall->id, 'category' => 'Grilled']);
        Food::factory()->create(['stall_id' => $wrongCategory->id, 'category' => 'Dessert']);
        Food::factory()->create(['stall_id' => $wrongSearch->id, 'category' => 'Grilled']);

        $this->actingAs($this->client)
            ->get(route('client.night-markets.stalls.index', [
                'nightMarket' => $market->id,
                'search' => 'Grill',
                'category' => 'Grilled',
            ]))
            ->assertOk()
            ->assertSee($matchingStall->name)
            ->assertDontSee($wrongCategory->name)
            ->assertDontSee($wrongSearch->name);
    }

    public function test_food_keyword_search_and_category_filter_match_the_same_food(): void
    {
        $market = NightMarket::factory()->create();
        $matchingStall = Stall::factory()->create(['night_market_id' => $market->id]);
        $wrongCategoryStall = Stall::factory()->create(['night_market_id' => $market->id]);
        $wrongKeywordStall = Stall::factory()->create(['night_market_id' => $market->id]);

        Food::factory()->create([
            'stall_id' => $matchingStall->id,
            'name' => 'Pandan Coconut Roll',
            'category' => 'Dessert',
        ]);
        Food::factory()->create([
            'stall_id' => $wrongCategoryStall->id,
            'name' => 'Pandan Cooler',
            'category' => 'Drinks',
        ]);
        Food::factory()->create([
            'stall_id' => $wrongKeywordStall->id,
            'name' => 'Chocolate Cake',
            'category' => 'Dessert',
        ]);

        $this->actingAs($this->client)
            ->get(route('client.night-markets.stalls.index', [
                'nightMarket' => $market->id,
                'search' => 'Pandan',
                'category' => 'Dessert',
            ]))
            ->assertOk()
            ->assertSee($matchingStall->name)
            ->assertDontSee($wrongCategoryStall->name)
            ->assertDontSee($wrongKeywordStall->name);
    }

    public function test_filter_values_persist_after_submission(): void
    {
        $market = NightMarket::factory()->create();
        $stall = Stall::factory()->create([
            'night_market_id' => $market->id,
            'name' => 'Persistent Search Stall',
        ]);
        Food::factory()->create([
            'stall_id' => $stall->id,
            'category' => 'Dessert',
        ]);

        $response = $this->actingAs($this->client)
            ->get(route('client.night-markets.stalls.index', [
                'nightMarket' => $market->id,
                'search' => 'Persistent',
                'category' => 'Dessert',
            ]));

        $response
            ->assertOk()
            ->assertSee('value="Persistent"', false)
            ->assertSee('Reset Search/Filters');
        $this->assertMatchesRegularExpression(
            '/<option value="Dessert"\s+selected>/',
            $response->getContent()
        );
    }

    public function test_reset_action_returns_the_full_stall_list(): void
    {
        $market = NightMarket::factory()->create();
        $matchingStall = Stall::factory()->create([
            'night_market_id' => $market->id,
            'name' => 'Filtered Satay Stall',
        ]);
        $otherStall = Stall::factory()->create([
            'night_market_id' => $market->id,
            'name' => 'Full List Drinks Stall',
        ]);

        $this->actingAs($this->client)
            ->get(route('client.night-markets.stalls.index', [
                'nightMarket' => $market->id,
                'search' => 'Satay',
            ]))
            ->assertOk()
            ->assertSee($matchingStall->name)
            ->assertDontSee($otherStall->name)
            ->assertSee('href="'.route('client.night-markets.stalls.index', $market->id).'"', false);

        $this->actingAs($this->client)
            ->get(route('client.night-markets.stalls.index', $market->id))
            ->assertOk()
            ->assertSee($matchingStall->name)
            ->assertSee($otherStall->name);
    }

    public function test_unmatched_filters_show_the_empty_state(): void
    {
        $market = NightMarket::factory()->create();
        Stall::factory()->create(['night_market_id' => $market->id]);

        $this->actingAs($this->client)
            ->get(route('client.night-markets.stalls.index', [
                'nightMarket' => $market->id,
                'search' => 'Nothing Matches This',
            ]))
            ->assertOk()
            ->assertSee('No stalls or foods found')
            ->assertSee('Clear Filters');
    }

    public function test_market_without_stalls_shows_the_empty_state(): void
    {
        $market = NightMarket::factory()->create();

        $this->actingAs($this->client)
            ->get(route('client.night-markets.stalls.index', $market->id))
            ->assertOk()
            ->assertSee('No stalls or foods found')
            ->assertSee('Reset Search/Filters');
    }

    public function test_active_food_detail_is_accessible(): void
    {
        $market = NightMarket::factory()->create(['name' => 'Food Detail Market']);
        $stall = Stall::factory()->create([
            'night_market_id' => $market->id,
            'name' => 'Food Detail Stall',
        ]);
        $food = Food::factory()->mustTry()->create([
            'stall_id' => $stall->id,
            'name' => 'Signature Grilled Fish',
            'category' => 'Grilled',
            'description' => 'Fresh fish grilled with aromatic spices.',
        ]);

        $this->actingAs($this->client)
            ->get(route('client.foods.show', $food->id))
            ->assertOk()
            ->assertSee($food->name)
            ->assertSee($stall->name)
            ->assertSee($market->name)
            ->assertSee('Grilled')
            ->assertSee('Fresh fish grilled with aromatic spices.')
            ->assertSee('Must-Try')
            ->assertSee('Back to Stalls')
            ->assertSee('Back to Market');
    }

    public function test_inactive_stall_or_food_direct_url_is_not_accessible(): void
    {
        $market = NightMarket::factory()->create();
        $inactiveStall = Stall::factory()->inactive()->create(['night_market_id' => $market->id]);
        $foodAtInactiveStall = Food::factory()->create(['stall_id' => $inactiveStall->id]);

        $activeStall = Stall::factory()->create(['night_market_id' => $market->id]);
        $inactiveFood = Food::factory()->inactive()->create(['stall_id' => $activeStall->id]);

        $this->actingAs($this->client)
            ->get(route('client.foods.show', $foodAtInactiveStall->id))
            ->assertNotFound();

        $this->actingAs($this->client)
            ->get(route('client.foods.show', $inactiveFood->id))
            ->assertNotFound();
    }

    public function test_food_from_a_non_selangor_market_is_not_accessible(): void
    {
        $market = NightMarket::factory()->create(['state' => 'Johor']);
        $stall = Stall::factory()->create(['night_market_id' => $market->id]);
        $food = Food::factory()->create(['stall_id' => $stall->id]);

        $this->actingAs($this->client)
            ->get(route('client.foods.show', $food->id))
            ->assertNotFound();
    }
}
