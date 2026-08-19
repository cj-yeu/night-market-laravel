<?php

namespace Tests\Feature;

use App\Models\MarketOperatingDay;
use App\Models\NightMarket;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class NightMarketPublicExperienceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_public_index_and_details_remain_available_to_every_role(): void
    {
        $market = NightMarket::factory()->create(['name' => 'Public Experience Market']);

        $this->get(route('night-markets.index'))->assertOk()->assertSee($market->name);
        $this->get(route('night-markets.show', $market))->assertOk()->assertSee($market->name);

        foreach ([User::ROLE_CLIENT, User::ROLE_ADMIN] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->get(route('night-markets.index'))->assertOk();
            $this->actingAs($user)->get(route('night-markets.show', $market))->assertOk();
        }
    }

    public function test_city_operating_day_and_search_filters_combine(): void
    {
        $match = NightMarket::factory()->create([
            'name' => 'Lantern Garden Market',
            'address' => 'Jalan Cahaya, Shah Alam',
            'city' => 'Shah Alam',
        ]);
        $wrongCity = NightMarket::factory()->create(['name' => 'Lantern Klang Market', 'city' => 'Klang']);
        $wrongDay = NightMarket::factory()->create(['name' => 'Lantern Morning Market', 'city' => 'Shah Alam']);
        foreach ([$match, $wrongCity] as $market) {
            MarketOperatingDay::factory()->create(['night_market_id' => $market->id, 'day_of_week' => 'Friday']);
        }
        MarketOperatingDay::factory()->create(['night_market_id' => $wrongDay->id, 'day_of_week' => 'Monday']);

        $this->get(route('night-markets.index', [
            'search' => '  Lantern   ',
            'city' => ' Shah Alam ',
            'operating_day' => 'Friday',
        ]))->assertOk()
            ->assertSee($match->name)
            ->assertDontSee($wrongCity->name)
            ->assertDontSee($wrongDay->name);
    }

    public function test_sorting_is_validated_and_stable(): void
    {
        NightMarket::factory()->create(['name' => 'Alpha Market', 'city' => 'Zulu City']);
        NightMarket::factory()->create(['name' => 'Zulu Market', 'city' => 'Alpha City']);

        $this->get(route('night-markets.index', ['sort' => 'name_asc']))
            ->assertOk()->assertSeeInOrder(['Alpha Market', 'Zulu Market']);
        $this->get(route('night-markets.index', ['sort' => 'name_desc']))
            ->assertOk()->assertSeeInOrder(['Zulu Market', 'Alpha Market']);
        $this->get(route('night-markets.index', ['sort' => 'city_asc']))
            ->assertOk()->assertSeeInOrder(['Zulu Market', 'Alpha Market']);
        $this->get(route('night-markets.index', ['sort' => 'drop_table']))
            ->assertSessionHasErrors('sort');
    }

    public function test_search_treats_percent_underscore_and_backslash_as_literals(): void
    {
        $literal = NightMarket::factory()->create(['name' => 'Market 100%_Safe\\Place']);
        $ordinary = NightMarket::factory()->create(['name' => 'Market 100X Safe Place']);

        $this->get(route('night-markets.index', ['search' => '%_']))
            ->assertOk()->assertSee($literal->name)->assertDontSee($ordinary->name);
        $this->get(route('night-markets.index', ['search' => '\\Place']))
            ->assertOk()->assertSee($literal->name)->assertDontSee($ordinary->name);
    }

    public function test_pagination_preserves_filters_and_sorting(): void
    {
        NightMarket::factory()->count(13)->create(['city' => 'Paging City']);

        $response = $this->get(route('night-markets.index', [
            'city' => 'Paging City',
            'sort' => 'name_desc',
        ]));

        $response->assertOk()
            ->assertSee('of 13 night markets')
            ->assertSee('city=Paging%20City', false)
            ->assertSee('sort=name_desc', false);
    }

    public function test_public_filter_options_exclude_non_public_markets(): void
    {
        NightMarket::factory()->create(['city' => 'Visible Option City']);
        NightMarket::factory()->inactive()->create(['city' => 'Inactive Secret City']);
        NightMarket::factory()->create(['city' => 'Outside Secret City', 'state' => 'Johor']);

        $this->get(route('night-markets.index'))
            ->assertOk()
            ->assertSee('Visible Option City')
            ->assertDontSee('Inactive Secret City')
            ->assertDontSee('Outside Secret City');
    }

    public function test_schedule_is_weekly_ordered_and_formatted_without_inventing_missing_data(): void
    {
        $market = NightMarket::factory()->create();
        MarketOperatingDay::factory()->create([
            'night_market_id' => $market->id,
            'day_of_week' => 'Friday',
            'opening_time' => '18:30',
            'closing_time' => '23:15',
        ]);
        MarketOperatingDay::factory()->create([
            'night_market_id' => $market->id,
            'day_of_week' => 'Monday',
            'opening_time' => '17:00',
            'closing_time' => '21:00',
        ]);

        $this->get(route('night-markets.show', $market))
            ->assertOk()
            ->assertSeeInOrder(['Monday', '5:00 PM', '9:00 PM', 'Friday', '6:30 PM', '11:15 PM']);

        $noSchedule = NightMarket::factory()->create();
        $this->get(route('night-markets.show', $noSchedule))
            ->assertOk()->assertSee('Operating schedule not available.');
    }

    public function test_google_maps_url_is_fixed_encoded_and_hidden_for_blank_addresses(): void
    {
        $market = NightMarket::factory()->create([
            'address' => 'Jalan 1 & 2, Shah Alam?destination=https://evil.test',
        ]);
        $expected = 'https://www.google.com/maps/search/?api=1&amp;query='.
            rawurlencode($market->address);

        $this->get(route('night-markets.show', $market))
            ->assertOk()
            ->assertSee('href="'.$expected.'"', false)
            ->assertSee('target="_blank"', false)
            ->assertSee('rel="noopener noreferrer"', false);

        $blankAddress = NightMarket::factory()->create(['address' => '   ']);
        $this->get(route('night-markets.show', $blankAddress))
            ->assertOk()->assertDontSee('View on Google Maps');
    }

    public function test_home_and_public_pages_show_cover_or_local_placeholder(): void
    {
        $withImage = NightMarket::factory()->create(['name' => 'Covered Market']);
        $withImage->forceFill(['image_path' => 'night-markets/123e4567-e89b-42d3-a456-426614174000.jpg'])->save();
        $placeholder = NightMarket::factory()->create(['name' => 'Placeholder Market']);

        $this->get(route('home'))->assertOk()->assertSee('Featured Night Markets');
        $this->get(route('night-markets.index'))
            ->assertOk()
            ->assertSee('/storage/night-markets/123e4567-e89b-42d3-a456-426614174000.jpg', false)
            ->assertSee('/images/night-market-placeholder.svg', false);
        $this->get(route('night-markets.show', $placeholder))
            ->assertOk()->assertSee('/images/night-market-placeholder.svg', false);
    }
}
