<?php

namespace Tests\Feature;

use App\Models\Food;
use App\Models\NightMarket;
use App\Models\Stall;
use App\Models\User;
use App\Services\StallFoodService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PublicCatalogDiscoveryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_public_catalog_routes_are_available_to_guests_clients_and_admins(): void
    {
        $this->get(route('stalls.index'))->assertOk()->assertSee('Explore Stalls');
        $this->get(route('foods.index'))->assertOk()->assertSee('Explore Foods');

        foreach ([User::ROLE_CLIENT, User::ROLE_ADMIN] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->get(route('stalls.index'))->assertOk();
            $this->actingAs($user)->get(route('foods.index'))->assertOk();
        }
    }

    public function test_non_public_descendants_and_filter_options_are_hidden(): void
    {
        $publicMarket = NightMarket::factory()->create(['name' => 'Public Option Market', 'city' => 'Public City']);
        $publicStall = Stall::factory()->for($publicMarket)->create(['name' => 'Public Option Stall', 'category' => 'Public Category']);
        $publicFood = Food::factory()->for($publicStall)->create(['name' => 'Public Option Food', 'category' => 'Public Food Category']);

        $inactiveMarket = NightMarket::factory()->inactive()->create(['name' => 'Private Market Option', 'city' => 'Private City']);
        $inactiveMarketStall = Stall::factory()->for($inactiveMarket)->create(['name' => 'Private Parent Stall', 'category' => 'Private Stall Category']);
        $inactiveMarketFood = Food::factory()->for($inactiveMarketStall)->create(['name' => 'Private Parent Food', 'category' => 'Private Food Category']);
        $inactiveStall = Stall::factory()->inactive()->for($publicMarket)->create(['name' => 'Inactive Stall Option']);
        $inactiveStallFood = Food::factory()->for($inactiveStall)->create(['name' => 'Inactive Stall Food']);
        $inactiveFood = Food::factory()->inactive()->for($publicStall)->create(['name' => 'Inactive Food Option']);

        $this->get(route('stalls.index'))->assertOk()
            ->assertSee($publicStall->name)->assertSee('Public Category')->assertSee('Public City')
            ->assertDontSee($inactiveMarket->name)->assertDontSee('Private City')
            ->assertDontSee($inactiveMarketStall->name)->assertDontSee('Private Stall Category')
            ->assertDontSee($inactiveStall->name);

        $this->get(route('foods.index'))->assertOk()
            ->assertSee($publicFood->name)->assertSee('Public Food Category')
            ->assertDontSee($inactiveMarketFood->name)->assertDontSee('Private Food Category')
            ->assertDontSee($inactiveStallFood->name)->assertDontSee($inactiveFood->name);

        foreach ([$inactiveMarketFood, $inactiveStallFood, $inactiveFood] as $food) {
            $this->get(route('foods.show', $food))->assertNotFound();
        }
    }

    public function test_stall_search_parent_city_category_and_halal_filters_work(): void
    {
        $market = NightMarket::factory()->create(['name' => 'Target Market', 'city' => 'Klang']);
        $match = Stall::factory()->for($market)->create([
            'name' => 'Target Noodle Stall',
            'description' => 'Traditional family recipes',
            'category' => 'Noodles',
            'halal_status' => Stall::HALAL_UNKNOWN,
        ]);
        $other = Stall::factory()->create([
            'name' => 'Other Dessert Stall',
            'category' => 'Dessert',
            'halal_status' => Stall::HALAL_CERTIFIED,
        ]);

        foreach (['Target Noodle', 'family recipes'] as $search) {
            $this->get(route('stalls.index', ['search' => $search]))
                ->assertOk()->assertSee($match->name)->assertDontSee($other->name);
        }

        $this->get(route('stalls.index', [
            'night_market_id' => $market->id,
            'city' => 'Klang',
            'category' => 'Noodles',
            'halal_status' => Stall::HALAL_UNKNOWN,
        ]))->assertOk()->assertSee($match->name)->assertDontSee($other->name)
            ->assertSee('Unknown');
    }

    public function test_stall_sort_validation_literal_search_pagination_and_empty_state_are_safe(): void
    {
        $marketA = NightMarket::factory()->create(['name' => 'Alpha Market']);
        $marketZ = NightMarket::factory()->create(['name' => 'Zulu Market']);
        $alpha = Stall::factory()->for($marketZ)->create(['name' => 'Alpha Stall']);
        $zulu = Stall::factory()->for($marketA)->create(['name' => 'Zulu Stall']);

        $descending = $this->get(route('stalls.index', ['sort' => 'name_desc']))->assertOk()->getContent();
        $this->assertLessThan(strpos($descending, 'data-stall-id="'.$alpha->id.'"'), strpos($descending, 'data-stall-id="'.$zulu->id.'"'));
        $byMarket = $this->get(route('stalls.index', ['sort' => 'market_asc']))->assertOk()->getContent();
        $this->assertLessThan(strpos($byMarket, 'data-stall-id="'.$alpha->id.'"'), strpos($byMarket, 'data-stall-id="'.$zulu->id.'"'));

        $literal = Stall::factory()->for($marketA)->create(['name' => 'Literal 100%_\\ Stall']);
        foreach (['%', '_', '\\'] as $character) {
            $this->get(route('stalls.index', ['search' => $character]))
                ->assertOk()->assertSee($literal->name)->assertDontSee($alpha->name);
        }

        $this->get(route('stalls.index', ['sort' => 'unsafe']))->assertSessionHasErrors('sort');
        $this->get(route('stalls.index', ['halal_status' => 'certified-ish']))->assertSessionHasErrors('halal_status');
        $this->get(route('stalls.index', ['search' => str_repeat('x', 101)]))->assertSessionHasErrors('search');

        Stall::factory()->count(13)->for($marketA)->create(['description' => 'Paged stall marker']);
        $this->get(route('stalls.index', ['search' => 'Paged stall marker', 'sort' => 'name_desc']))
            ->assertOk()->assertSee('Page 1 of 2')->assertSee('search=Paged%20stall%20marker', false)->assertSee('sort=name_desc', false);
        $this->get(route('stalls.index', ['search' => 'No matching stall anywhere']))
            ->assertOk()->assertSee('No stalls found')->assertSee('Clear Filters');
    }

    public function test_food_search_and_relationship_metadata_filters_work_together(): void
    {
        $market = NightMarket::factory()->create(['name' => 'Food Target Market']);
        $stall = Stall::factory()->for($market)->create(['name' => 'Food Target Stall', 'halal_status' => Stall::HALAL_MUSLIM_OWNED_OR_CLAIMED]);
        $match = Food::factory()->mustTry()->for($stall)->create([
            'name' => 'Target Satay',
            'description' => 'Charcoal grilled skewers',
            'category' => 'Grilled',
        ]);
        $other = Food::factory()->create(['name' => 'Other Dessert']);

        foreach (['Target Satay', 'grilled skewers'] as $search) {
            $this->get(route('foods.index', ['search' => $search]))
                ->assertOk()->assertSee($match->name)->assertDontSee($other->name);
        }

        $this->get(route('foods.index', [
            'night_market_id' => $market->id,
            'stall_id' => $stall->id,
            'category' => 'Grilled',
            'halal_status' => Stall::HALAL_MUSLIM_OWNED_OR_CLAIMED,
            'is_must_try' => 'true',
        ]))->assertOk()->assertSee($match->name)->assertDontSee($other->name)
            ->assertSee('Muslim-owned/claimed (not certification)');

        $this->get(route('foods.index', ['is_must_try' => 'false']))
            ->assertOk()->assertSee($other->name)->assertDontSee($match->name);
    }

    public function test_numeric_price_filters_use_range_overlap_and_exclude_display_only_prices(): void
    {
        $stall = Stall::factory()->create();
        $fixed = Food::factory()->for($stall)->create(['name' => 'Fixed Eight', 'price_min' => 8, 'price_max' => 8]);
        $range = Food::factory()->for($stall)->create(['name' => 'Range Five Twelve', 'price_min' => 5, 'price_max' => 12]);
        $low = Food::factory()->for($stall)->create(['name' => 'Low Two', 'price_min' => 2, 'price_max' => null]);
        $high = Food::factory()->for($stall)->create(['name' => 'High Twenty', 'price_min' => 20, 'price_max' => null]);
        $displayOnly = Food::factory()->for($stall)->create(['name' => 'Market Price Display', 'price_min' => null, 'price_max' => null, 'price_display' => 'Market price']);

        $overlap = $this->get(route('foods.index', ['min_price' => '10.00', 'max_price' => '15.00']))->assertOk();
        $overlap->assertSee($range->name)->assertDontSee($fixed->name)->assertDontSee($low->name)
            ->assertDontSee($high->name)->assertDontSee($displayOnly->name);

        $this->get(route('foods.index', ['min_price' => '10']))->assertOk()
            ->assertSee($range->name)->assertSee($high->name)->assertDontSee($fixed->name)->assertDontSee($displayOnly->name);
        $this->get(route('foods.index', ['max_price' => '6']))->assertOk()
            ->assertSee($range->name)->assertSee($low->name)->assertDontSee($fixed->name)->assertDontSee($displayOnly->name);
        $this->get(route('foods.index'))->assertOk()->assertSee($displayOnly->name)->assertSee('Market price');
    }

    public function test_food_price_validation_sorting_pagination_and_empty_state_are_safe(): void
    {
        $stall = Stall::factory()->create();
        $cheap = Food::factory()->for($stall)->create(['name' => 'Cheap Food', 'price_min' => 2, 'price_max' => 3, 'is_must_try' => false]);
        $expensive = Food::factory()->for($stall)->create(['name' => 'Expensive Food', 'price_min' => 20, 'price_max' => 30, 'is_must_try' => true]);

        $ascending = $this->get(route('foods.index', ['sort' => 'price_low_high']))->assertOk()->getContent();
        $this->assertLessThan(strpos($ascending, 'data-food-id="'.$expensive->id.'"'), strpos($ascending, 'data-food-id="'.$cheap->id.'"'));
        $descending = $this->get(route('foods.index', ['sort' => 'price_high_low']))->assertOk()->getContent();
        $this->assertLessThan(strpos($descending, 'data-food-id="'.$cheap->id.'"'), strpos($descending, 'data-food-id="'.$expensive->id.'"'));
        $mustTryFirst = $this->get(route('foods.index', ['sort' => 'must_try_first']))->assertOk()->getContent();
        $this->assertLessThan(strpos($mustTryFirst, 'data-food-id="'.$cheap->id.'"'), strpos($mustTryFirst, 'data-food-id="'.$expensive->id.'"'));

        foreach ([
            ['min_price' => '-1'],
            ['max_price' => '1.234'],
            ['min_price' => '10', 'max_price' => '9'],
            ['sort' => 'random'],
            ['is_must_try' => 'perhaps'],
        ] as $invalid) {
            $this->get(route('foods.index', $invalid))->assertSessionHasErrors();
        }

        Food::factory()->count(13)->for($stall)->create(['description' => 'Paged food marker']);
        $this->get(route('foods.index', ['search' => 'Paged food marker', 'sort' => 'name_desc']))
            ->assertOk()->assertSee('Page 1 of 2')->assertSee('search=Paged%20food%20marker', false)->assertSee('sort=name_desc', false);
        $this->get(route('foods.index', ['search' => 'No matching food anywhere']))
            ->assertOk()->assertSee('No foods found')->assertSee('Clear Filters');
    }

    public function test_home_and_market_must_try_showcases_use_only_relevant_public_records(): void
    {
        $market = NightMarket::factory()->create(['name' => 'Showcase Market']);
        $stall = Stall::factory()->for($market)->create();
        $visible = Food::factory()->mustTry()->for($stall)->create([
            'name' => 'Visible Showcase Food',
            'recommendation_reason' => 'A real catalog reason.',
        ]);
        $ordinary = Food::factory()->for($stall)->create(['name' => 'Ordinary Public Food']);
        $otherMarketFood = Food::factory()->mustTry()->create(['name' => 'Other Market Must Try']);
        $inactiveFood = Food::factory()->inactive()->mustTry()->for($stall)->create(['name' => 'Inactive Must Try']);
        $inactiveStall = Stall::factory()->inactive()->for($market)->create();
        $inactiveStallFood = Food::factory()->mustTry()->for($inactiveStall)->create(['name' => 'Inactive Stall Must Try']);

        $this->get(route('home'))->assertOk()->assertSee($visible->name)->assertSee('A real catalog reason.')
            ->assertSee($otherMarketFood->name)->assertDontSee($ordinary->name)
            ->assertDontSee($inactiveFood->name)->assertDontSee($inactiveStallFood->name)
            ->assertSee(route('foods.show', $visible), false);

        $this->get(route('night-markets.show', $market))->assertOk()
            ->assertSee($visible->name)->assertSee('A real catalog reason.')
            ->assertDontSee($otherMarketFood->name)->assertDontSee($ordinary->name)
            ->assertDontSee($inactiveFood->name)->assertDontSee($inactiveStallFood->name)
            ->assertSee(route('foods.show', $visible), false);
    }

    public function test_empty_must_try_showcase_and_parent_navigation_are_clear_and_correct(): void
    {
        $market = NightMarket::factory()->create(['name' => 'Navigation Market']);
        $stall = Stall::factory()->for($market)->create(['name' => 'Navigation Stall']);
        $food = Food::factory()->for($stall)->create(['name' => 'Navigation Food']);
        $unrelatedMarket = NightMarket::factory()->create();

        $this->get(route('home'))->assertOk()->assertSee('No Must-Try foods are available right now.');
        $this->get(route('night-markets.show', $market))->assertOk()
            ->assertSee(route('night-markets.stalls.index', $market), false)
            ->assertSee(route('foods.index', ['stall_id' => $stall->id, 'night_market_id' => $market->id]));
        $this->get(route('night-markets.stalls.index', $market))->assertOk()
            ->assertSee($stall->name)->assertSee($food->name)->assertSee(route('foods.show', $food), false);
        $this->get(route('foods.show', $food))->assertOk()
            ->assertSee($stall->name)->assertSee($market->name)
            ->assertSee(route('night-markets.show', $market), false)
            ->assertSee('Write a Review')->assertSee('Add to Visit Plan');

        $this->get(route('foods.index', ['stall_id' => $stall->id, 'night_market_id' => $unrelatedMarket->id]))
            ->assertOk()->assertDontSee($food->name)->assertSee('No foods found');
    }

    public function test_public_catalog_queries_eager_load_displayed_parent_relationships(): void
    {
        $market = NightMarket::factory()->create();
        $stalls = Stall::factory()->count(12)->for($market)->create();
        foreach ($stalls as $stall) {
            Food::factory()->for($stall)->create();
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $stallPage = app(StallFoodService::class)->discoverPublicStalls([]);
        foreach ($stallPage as $stall) {
            $stall->nightMarket->name;
        }
        $stallQueryCount = count(DB::getQueryLog());

        DB::flushQueryLog();
        $foodPage = app(StallFoodService::class)->discoverPublicFoods([]);
        foreach ($foodPage as $food) {
            $food->stall->name;
            $food->stall->nightMarket->name;
        }
        $foodQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(3, $stallQueryCount);
        $this->assertLessThanOrEqual(4, $foodQueryCount);
    }
}
