<?php

namespace Tests\Feature;

use App\Models\Food;
use App\Models\MarketOperatingDay;
use App\Models\NightMarket;
use App\Models\Stall;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class NightMarketDiscoveryTest extends TestCase
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

    public function test_active_markets_appear_in_the_list(): void
    {
        $market = NightMarket::factory()->create(['name' => 'SS2 Monday Market']);

        $this->actingAs($this->client)
            ->get(route('night-markets.index'))
            ->assertOk()
            ->assertSee($market->name)
            ->assertSee($market->address)
            ->assertSee($market->city);
    }

    public function test_inactive_markets_are_hidden_from_the_list(): void
    {
        $inactiveMarket = NightMarket::factory()->inactive()->create([
            'name' => 'Hidden Inactive Market',
        ]);

        $this->actingAs($this->client)
            ->get(route('night-markets.index'))
            ->assertOk()
            ->assertDontSee($inactiveMarket->name);
    }

    public function test_active_markets_outside_selangor_are_hidden_from_the_list(): void
    {
        $outsideSelangor = NightMarket::factory()->create([
            'name' => 'Outside Selangor Market',
            'state' => 'Kuala Lumpur',
        ]);

        $this->actingAs($this->client)
            ->get(route('night-markets.index'))
            ->assertOk()
            ->assertDontSee($outsideSelangor->name);
    }

    public function test_search_by_market_name_works(): void
    {
        $matchingMarket = NightMarket::factory()->create(['name' => 'Taman Connaught Night Market']);
        $otherMarket = NightMarket::factory()->create(['name' => 'Klang Riverside Market']);

        $this->actingAs($this->client)
            ->get(route('night-markets.index', ['search' => 'Connaught']))
            ->assertOk()
            ->assertSee($matchingMarket->name)
            ->assertDontSee($otherMarket->name);
    }

    public function test_search_by_location_works(): void
    {
        $matchingMarket = NightMarket::factory()->create([
            'name' => 'Location Match Market',
            'address' => 'Jalan Setia Prima, Shah Alam',
        ]);
        $otherMarket = NightMarket::factory()->create([
            'name' => 'Different Location Market',
            'address' => 'Jalan Meru, Klang',
        ]);

        $this->actingAs($this->client)
            ->get(route('night-markets.index', ['search' => 'Setia Prima']))
            ->assertOk()
            ->assertSee($matchingMarket->name)
            ->assertDontSee($otherMarket->name);
    }

    public function test_district_filter_works(): void
    {
        $matchingMarket = NightMarket::factory()->create([
            'name' => 'Petaling District Market',
            'city' => 'Petaling',
        ]);
        $otherMarket = NightMarket::factory()->create([
            'name' => 'Klang District Market',
            'city' => 'Klang',
        ]);

        $this->actingAs($this->client)
            ->get(route('night-markets.index', ['district' => 'Petaling']))
            ->assertOk()
            ->assertSee($matchingMarket->name)
            ->assertDontSee($otherMarket->name);
    }

    public function test_combined_search_and_district_filter_work_together(): void
    {
        $matchingMarket = NightMarket::factory()->create([
            'name' => 'Sri Muda Community Market',
            'city' => 'Petaling',
        ]);
        $wrongDistrict = NightMarket::factory()->create([
            'name' => 'Sri Muda Klang Market',
            'city' => 'Klang',
        ]);
        $wrongSearch = NightMarket::factory()->create([
            'name' => 'Petaling Weekend Market',
            'city' => 'Petaling',
        ]);

        $this->actingAs($this->client)
            ->get(route('night-markets.index', [
                'search' => 'Sri Muda',
                'district' => 'Petaling',
            ]))
            ->assertOk()
            ->assertSee($matchingMarket->name)
            ->assertDontSee($wrongDistrict->name)
            ->assertDontSee($wrongSearch->name);
    }

    public function test_operating_day_filter_works(): void
    {
        $mondayMarket = NightMarket::factory()->create(['name' => 'Monday Schedule Market']);
        $fridayMarket = NightMarket::factory()->create(['name' => 'Friday Schedule Market']);
        MarketOperatingDay::factory()->create([
            'night_market_id' => $mondayMarket->id,
            'day_of_week' => 'Monday',
        ]);
        MarketOperatingDay::factory()->create([
            'night_market_id' => $fridayMarket->id,
            'day_of_week' => 'Friday',
        ]);

        $this->actingAs($this->client)
            ->get(route('night-markets.index', ['operating_day' => 'Monday']))
            ->assertOk()
            ->assertSee($mondayMarket->name)
            ->assertDontSee($fridayMarket->name);
    }

    public function test_district_and_operating_day_filters_work_together(): void
    {
        $matchingMarket = NightMarket::factory()->create([
            'name' => 'Petaling Friday Market',
            'city' => 'Petaling',
        ]);
        $wrongDistrict = NightMarket::factory()->create([
            'name' => 'Klang Friday Market',
            'city' => 'Klang',
        ]);
        $wrongDay = NightMarket::factory()->create([
            'name' => 'Petaling Monday Market',
            'city' => 'Petaling',
        ]);

        foreach ([$matchingMarket, $wrongDistrict] as $market) {
            MarketOperatingDay::factory()->create([
                'night_market_id' => $market->id,
                'day_of_week' => 'Friday',
            ]);
        }
        MarketOperatingDay::factory()->create([
            'night_market_id' => $wrongDay->id,
            'day_of_week' => 'Monday',
        ]);

        $this->actingAs($this->client)
            ->get(route('night-markets.index', [
                'district' => 'Petaling',
                'operating_day' => 'Friday',
            ]))
            ->assertOk()
            ->assertSee($matchingMarket->name)
            ->assertDontSee($wrongDistrict->name)
            ->assertDontSee($wrongDay->name);
    }

    public function test_search_district_and_operating_day_filters_work_together(): void
    {
        $matchingMarket = NightMarket::factory()->create([
            'name' => 'Setia Friday Food Market',
            'city' => 'Petaling',
        ]);
        $wrongSearch = NightMarket::factory()->create([
            'name' => 'Petaling Friday Community Market',
            'city' => 'Petaling',
        ]);
        $wrongDistrict = NightMarket::factory()->create([
            'name' => 'Setia Friday Klang Market',
            'city' => 'Klang',
        ]);
        $wrongDay = NightMarket::factory()->create([
            'name' => 'Setia Monday Food Market',
            'city' => 'Petaling',
        ]);

        foreach ([$matchingMarket, $wrongSearch, $wrongDistrict] as $market) {
            MarketOperatingDay::factory()->create([
                'night_market_id' => $market->id,
                'day_of_week' => 'Friday',
            ]);
        }
        MarketOperatingDay::factory()->create([
            'night_market_id' => $wrongDay->id,
            'day_of_week' => 'Monday',
        ]);

        $this->actingAs($this->client)
            ->get(route('night-markets.index', [
                'search' => 'Setia',
                'district' => 'Petaling',
                'operating_day' => 'Friday',
            ]))
            ->assertOk()
            ->assertSee($matchingMarket->name)
            ->assertDontSee($wrongSearch->name)
            ->assertDontSee($wrongDistrict->name)
            ->assertDontSee($wrongDay->name);
    }

    public function test_filter_values_persist_in_the_response(): void
    {
        $market = NightMarket::factory()->create([
            'name' => 'Persistent Search Market',
            'city' => 'Petaling',
        ]);
        MarketOperatingDay::factory()->create([
            'night_market_id' => $market->id,
            'day_of_week' => 'Friday',
        ]);

        $this->actingAs($this->client)
            ->get(route('night-markets.index', [
                'search' => 'Persistent',
                'district' => 'Petaling',
                'operating_day' => 'Friday',
            ]))
            ->assertOk()
            ->assertSee('value="Persistent"', false)
            ->assertSee('value="Petaling"', false)
            ->assertSee('value="Friday"', false)
            ->assertSee('Reset Filters');
    }

    public function test_reset_filters_returns_the_full_active_selangor_market_list(): void
    {
        $firstMarket = NightMarket::factory()->create(['name' => 'Full List First Market']);
        $secondMarket = NightMarket::factory()->create(['name' => 'Full List Second Market']);

        $filteredResponse = $this->actingAs($this->client)
            ->get(route('night-markets.index', ['search' => 'First']));

        $filteredResponse
            ->assertOk()
            ->assertSee($firstMarket->name)
            ->assertDontSee($secondMarket->name)
            ->assertSee('href="'.route('night-markets.index').'"', false);

        $this->actingAs($this->client)
            ->get(route('night-markets.index'))
            ->assertOk()
            ->assertSee($firstMarket->name)
            ->assertSee($secondMarket->name);
    }

    public function test_unmatched_filters_show_the_empty_state(): void
    {
        NightMarket::factory()->create();

        $this->actingAs($this->client)
            ->get(route('night-markets.index', ['search' => 'No Such Market Anywhere']))
            ->assertOk()
            ->assertSee('No night markets found')
            ->assertSee('Clear Filters');
    }

    public function test_active_market_detail_page_is_accessible(): void
    {
        $market = NightMarket::factory()->create([
            'name' => 'Accessible Market',
            'city' => 'Petaling',
        ]);
        MarketOperatingDay::factory()->create([
            'night_market_id' => $market->id,
            'day_of_week' => 'Monday',
            'opening_time' => '18:00',
            'closing_time' => '22:00',
        ]);
        $activeStall = Stall::factory()->create([
            'night_market_id' => $market->id,
            'name' => 'Accessible Active Stall',
        ]);
        $mustTryFood = Food::factory()->mustTry()->create([
            'stall_id' => $activeStall->id,
            'name' => 'Accessible Must-Try Food',
        ]);
        $inactiveStall = Stall::factory()->inactive()->create([
            'night_market_id' => $market->id,
            'name' => 'Hidden Inactive Detail Stall',
        ]);
        Food::factory()->mustTry()->create([
            'stall_id' => $inactiveStall->id,
            'name' => 'Hidden Food At Inactive Stall',
        ]);

        $this->actingAs($this->client)
            ->get(route('night-markets.show', $market->id))
            ->assertOk()
            ->assertSee($market->name)
            ->assertSee($market->address)
            ->assertSee('Petaling')
            ->assertSee('Monday')
            ->assertSee('6:00 PM')
            ->assertSee('10:00 PM')
            ->assertSee($activeStall->name)
            ->assertSee($mustTryFood->name)
            ->assertDontSee($inactiveStall->name)
            ->assertDontSee('Hidden Food At Inactive Stall');
    }

    public function test_inactive_market_detail_page_is_not_accessible(): void
    {
        $inactiveMarket = NightMarket::factory()->inactive()->create();

        $this->actingAs($this->client)
            ->get(route('night-markets.show', $inactiveMarket->id))
            ->assertNotFound();
    }
}
