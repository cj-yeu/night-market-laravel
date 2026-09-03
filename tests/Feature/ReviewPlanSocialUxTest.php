<?php

namespace Tests\Feature;

use App\Models\Food;
use App\Models\MarketOperatingDay;
use App\Models\NightMarket;
use App\Models\Review;
use App\Models\Stall;
use App\Models\User;
use App\Models\VisitPlan;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ReviewPlanSocialUxTest extends TestCase
{
    use DatabaseTransactions;

    public function test_public_market_and_food_rating_summaries_use_only_approved_reviews(): void
    {
        $market = NightMarket::factory()->create(['name' => 'Rated Public Market']);
        $stall = Stall::factory()->create(['night_market_id' => $market->id]);
        $food = Food::factory()->create(['stall_id' => $stall->id, 'name' => 'Rated Public Food']);

        Review::factory()->approved()->create([
            'night_market_id' => $market->id,
            'rating' => 5,
        ]);
        Review::factory()->approved()->create([
            'night_market_id' => $market->id,
            'rating' => 3,
        ]);
        Review::factory()->create([
            'night_market_id' => $market->id,
            'rating' => 1,
        ]);
        Review::factory()->forFood($food)->approved()->create(['rating' => 4]);
        Review::factory()->forFood($food)->rejected()->create(['rating' => 1]);

        $this->get(route('night-markets.index'))
            ->assertOk()
            ->assertSee('4.0')
            ->assertSee('2 reviews');

        $this->get(route('night-markets.show', $market))
            ->assertOk()
            ->assertSee('4.0')
            ->assertSee('2 reviews');

        $this->get(route('foods.index'))
            ->assertOk()
            ->assertSee('Rated Public Food')
            ->assertSee('4.0')
            ->assertSee('1 review');

        $this->get(route('foods.show', $food))
            ->assertOk()
            ->assertSee('4.0')
            ->assertSee('1 review');
    }

    public function test_public_catalog_shows_a_clear_empty_rating_state_when_no_approved_review_exists(): void
    {
        $market = NightMarket::factory()->create(['name' => 'Unrated Public Market']);
        Review::factory()->create(['night_market_id' => $market->id]);

        $this->get(route('night-markets.index'))
            ->assertOk()
            ->assertSee('Unrated Public Market')
            ->assertSee('No reviews yet');
    }

    public function test_plan_item_form_separates_stalls_and_foods_and_server_uses_the_selected_type(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $market = NightMarket::factory()->create(['name' => 'Typed Plan Market']);
        MarketOperatingDay::factory()->create([
            'night_market_id' => $market->id,
            'day_of_week' => now()->addWeek()->englishDayOfWeek,
        ]);
        $plan = VisitPlan::factory()->create([
            'user_id' => $client->id,
            'night_market_id' => $market->id,
        ]);
        $stall = Stall::factory()->create(['night_market_id' => $market->id, 'name' => 'Typed Stall']);
        $food = Food::factory()->create(['stall_id' => $stall->id, 'name' => 'Grouped Food']);
        $otherStall = Stall::factory()->create(['night_market_id' => $market->id, 'name' => 'Other Typed Stall']);
        $otherFood = Food::factory()->create(['stall_id' => $otherStall->id, 'name' => 'Other Grouped Food']);

        $this->actingAs($client)
            ->get(route('client.visit-plans.show', $plan))
            ->assertOk()
            ->assertSee('Choose a Stall')
            ->assertSee('Choose a Food')
            ->assertSee('name="stall_id"', false)
            ->assertSee('name="food_id"', false)
            ->assertDontSee('name="item_id"', false)
            ->assertSee('<optgroup label="Typed Stall">', false)
            ->assertSee('<optgroup label="Other Typed Stall">', false);

        $this->actingAs($client)
            ->post(route('client.visit-plans.items.store', $plan), [
                'item_type' => 'stall',
                'stall_id' => $stall->id,
                'food_id' => $food->id,
            ])
            ->assertRedirect(route('client.visit-plans.show', $plan));

        $this->assertDatabaseHas('visit_plan_items', [
            'visit_plan_id' => $plan->id,
            'stall_id' => $stall->id,
            'food_id' => null,
        ]);

        $this->actingAs($client)
            ->get(route('client.visit-plans.show', $plan))
            ->assertDontSee('value="'.$stall->id.'"', false)
            ->assertSee('value="'.$food->id.'"', false);
    }

    public function test_plan_item_server_validation_requires_the_current_typed_field_and_rechecks_eligibility(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $market = NightMarket::factory()->create();
        $plan = VisitPlan::factory()->create([
            'user_id' => $client->id,
            'night_market_id' => $market->id,
        ]);
        $stall = Stall::factory()->create(['night_market_id' => $market->id]);
        $inactiveFood = Food::factory()->inactive()->create(['stall_id' => $stall->id]);

        $this->actingAs($client)
            ->post(route('client.visit-plans.items.store', $plan), [
                'item_type' => 'food',
                'stall_id' => $stall->id,
            ])
            ->assertSessionHasErrors('food_id');

        $this->actingAs($client)
            ->post(route('client.visit-plans.items.store', $plan), [
                'item_type' => 'food',
                'food_id' => $inactiveFood->id,
            ])
            ->assertSessionHasErrors('food_id');
    }
}
