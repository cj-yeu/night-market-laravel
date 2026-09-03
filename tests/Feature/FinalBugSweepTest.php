<?php

namespace Tests\Feature;

use App\Models\CatalogAuditLog;
use App\Models\Food;
use App\Models\NightMarket;
use App\Models\Review;
use App\Models\ReviewTag;
use App\Models\Stall;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class FinalBugSweepTest extends TestCase
{
    use DatabaseTransactions;

    public function test_market_and_food_review_forms_only_show_their_own_tag_sets_and_reject_cross_target_tags(): void
    {
        $client = $this->client();
        $market = NightMarket::factory()->create();
        $food = Food::factory()->create(['stall_id' => Stall::factory()->create(['night_market_id' => $market->id])->id]);
        $marketTag = ReviewTag::query()->where('name', 'Clean')->firstOrFail();
        $foodTag = ReviewTag::query()->where('name', 'Tasty')->firstOrFail();

        $this->actingAs($client)->get(route('client.night-markets.reviews.create', $market))
            ->assertSee('Easy Access')->assertDontSee('Good Portion');
        $this->actingAs($client)->get(route('client.foods.reviews.create', $food))
            ->assertSee('Good Portion')->assertDontSee('Easy Access');

        $this->actingAs($client)->post(route('client.night-markets.reviews.store', $market), $this->reviewPayload(['tags' => [$foodTag->id]]))
            ->assertSessionHasErrors('tags.0');
        $this->actingAs($client)->post(route('client.foods.reviews.store', $food), $this->reviewPayload(['tags' => [$marketTag->id]]))
            ->assertSessionHasErrors('tags.0');
    }

    public function test_admin_can_delete_an_unused_inactive_food_but_not_active_or_dependent_catalog_records(): void
    {
        $admin = $this->admin();
        $market = NightMarket::factory()->inactive()->create();
        $stall = Stall::factory()->inactive()->create(['night_market_id' => $market->id]);
        $food = Food::factory()->inactive()->create(['stall_id' => $stall->id]);

        $this->actingAs($admin)->delete(route('admin.foods.destroy', $food))->assertRedirect(route('admin.foods.index'));
        $this->assertDatabaseMissing('foods', ['id' => $food->id]);

        $activeFood = Food::factory()->create(['stall_id' => $stall->id]);
        $this->actingAs($admin)->delete(route('admin.foods.destroy', $activeFood))
            ->assertSessionHasErrors('catalog');
        $this->assertDatabaseHas('foods', ['id' => $activeFood->id]);

        $blockedStall = Stall::factory()->inactive()->create(['night_market_id' => $market->id]);
        Food::factory()->create(['stall_id' => $blockedStall->id]);
        $this->actingAs($admin)->delete(route('admin.stalls.destroy', $blockedStall))
            ->assertSessionHasErrors('catalog');

        $blockedMarket = NightMarket::factory()->inactive()->create();
        Stall::factory()->create(['night_market_id' => $blockedMarket->id]);
        $this->actingAs($admin)->delete(route('admin.night-markets.destroy', $blockedMarket))
            ->assertSessionHasErrors('catalog');
    }

    public function test_catalog_delete_routes_are_not_available_to_guests_or_clients(): void
    {
        $food = Food::factory()->inactive()->create();
        $this->delete(route('admin.foods.destroy', $food))->assertRedirect(route('login'));
        $this->actingAs($this->client())->delete(route('admin.foods.destroy', $food))->assertForbidden();
    }

    public function test_stall_category_filter_collapses_variants_and_matches_the_main_category(): void
    {
        $market = NightMarket::factory()->create();
        Stall::factory()->create(['night_market_id' => $market->id, 'name' => 'Dessert Exact', 'category' => ' Dessert ']);
        Stall::factory()->create(['night_market_id' => $market->id, 'name' => 'Ice Cream Dessert', 'category' => 'Dessert / Ice Cream']);
        Stall::factory()->create(['night_market_id' => $market->id, 'name' => 'Savory Stall', 'category' => 'Savory']);

        $this->get(route('stalls.index', ['category' => ' dessert ']))
            ->assertOk()->assertSee('Dessert Exact')->assertSee('Ice Cream Dessert')->assertDontSee('Savory Stall')
            ->assertSee('Dessert')->assertDontSee('Dessert / Ice Cream');
    }

    public function test_market_city_control_is_rendered_and_enforced(): void
    {
        $this->actingAs($this->admin())->get(route('admin.night-markets.create'))
            ->assertOk()->assertSee('Select a Selangor city or town')->assertSee('Shah Alam');

        $this->actingAs($this->admin())->post(route('admin.night-markets.store'), [
            'name' => 'Invalid City Market', 'address' => '1 Example Road', 'city' => 'Unknown City',
            'status' => NightMarket::STATUS_ACTIVE,
            'operating_days' => [['day_of_week' => 'Friday', 'opening_time' => '18:00', 'closing_time' => '23:00']],
        ])->assertSessionHasErrors('city');
    }

    public function test_activity_log_uses_bootstrap_pagination_markup(): void
    {
        $admin = $this->admin();
        foreach (range(1, 25) as $index) {
            CatalogAuditLog::query()->create([
                'user_id' => $admin->id,
                'entity_type' => 'food',
                'entity_id' => $index,
                'action' => 'updated',
                'summary' => 'Updated food '.$index,
                'created_at' => now(),
            ]);
        }

        $this->actingAs($admin)->get(route('admin.catalog-activity.index', ['page' => 2]))
            ->assertOk()->assertSee('class="pagination"', false);
    }

    public function test_food_filter_uses_the_current_url_and_contains_bfcache_protection(): void
    {
        $this->get(route('foods.index'))->assertOk()
            ->assertSee('name="min_price" inputmode="decimal" value=""', false)
            ->assertSee("window.addEventListener('pageshow'", false)
            ->assertSee("query.get(name) || ''", false);
    }

    public function test_client_can_delete_only_their_own_review_and_tag_pivots_are_removed(): void
    {
        $owner = $this->client();
        $other = $this->client();
        $food = Food::factory()->create();
        $review = Review::factory()->approved()->forFood($food)->create(['user_id' => $owner->id]);
        $review->tags()->attach(ReviewTag::query()->where('name', 'Tasty')->value('id'));

        $this->actingAs($other)->delete(route('client.foods.reviews.destroy', [$food, $review]))->assertForbidden();
        $this->actingAs($owner)->delete(route('client.foods.reviews.destroy', [$food, $review]))
            ->assertRedirect(route('profile.edit'));
        $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
        $this->assertDatabaseMissing('review_review_tag', ['review_id' => $review->id]);
    }

    public function test_google_avatar_security_and_nightbite_branding_are_present(): void
    {
        $this->assertTrue(User::isTrustedGoogleAvatarUrl('https://lh3.googleusercontent.com/a/example'));
        $this->assertFalse(User::isTrustedGoogleAvatarUrl('http://lh3.googleusercontent.com/a/example'));
        $this->assertFalse(User::isTrustedGoogleAvatarUrl('https://example.test/avatar.png'));

        config()->set('app.name', 'NightBite');
        $this->get(route('login'))->assertOk()->assertSee('NightBite');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
    }

    private function client(): User
    {
        return User::factory()->create(['role' => User::ROLE_CLIENT, 'is_active' => true]);
    }

    /** @param array<string, mixed> $override */
    private function reviewPayload(array $override = []): array
    {
        return [...['rating' => 5, 'comment' => 'A detailed review that is long enough.'], ...$override];
    }
}
