<?php

namespace Tests\Feature;

use App\Models\Food;
use App\Models\NightMarket;
use App\Models\Review;
use App\Models\User;
use App\Models\VisitPlan;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ClientDashboardAndMarketReviewTest extends TestCase
{
    use DatabaseTransactions;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = User::factory()->create(['role' => User::ROLE_CLIENT, 'is_active' => true]);
        RateLimiter::clear('review-submissions:'.$this->client->id);
    }

    protected function tearDown(): void
    {
        RateLimiter::clear('review-submissions:'.$this->client->id);
        parent::tearDown();
    }

    public function test_client_dashboard_guides_first_time_users_and_summarizes_owned_upcoming_plans(): void
    {
        $this->actingAs($this->client)->get(route('client.home'))
            ->assertOk()->assertSee('Getting Started')->assertSee('Discover a night market')->assertSee('No upcoming plans yet')
            ->assertSee('Smart Visit Planner')->assertSee('My Visit Plans');

        $market = NightMarket::factory()->create();
        $plan = VisitPlan::factory()->for($this->client)->for($market)->create(['title' => 'Friday food trail', 'visit_date' => now()->addWeek()->toDateString()]);
        $this->actingAs($this->client)->get(route('client.home'))
            ->assertOk()->assertSee('1')->assertSee('Friday food trail')->assertSee(route('client.visit-plans.show', $plan), false);
    }

    public function test_market_reviews_are_directly_published_unique_owned_and_public(): void
    {
        $market = NightMarket::factory()->create();
        $payload = ['rating' => 5, 'comment' => '  A detailed market review for visitors.  ', 'tags' => ['many_choices', 'family_friendly']];

        $this->actingAs($this->client)->post(route('client.night-markets.reviews.store', $market), $payload)
            ->assertRedirect(route('night-markets.show', $market));
        $review = Review::query()->where('user_id', $this->client->id)->firstOrFail();
        $this->assertDatabaseHas('reviews', ['id' => $review->id, 'night_market_id' => $market->id, 'food_id' => null, 'comment' => 'A detailed market review for visitors.', 'status' => Review::STATUS_APPROVED]);
        $this->assertSame(['many_choices', 'family_friendly'], $review->tags);

        $this->actingAs($this->client)->post(route('client.night-markets.reviews.store', $market), $payload)->assertSessionHasErrors('comment');
        $this->actingAs($this->client)->patch(route('client.night-markets.reviews.update', [$market, $review]), ['rating' => 4, 'comment' => 'Updated market review remains public.', 'tags' => ['many_choices', 'family_friendly']])->assertRedirect(route('night-markets.show', $market));
        $this->get(route('night-markets.show', $market))->assertSee('Updated market review remains public.')->assertSee('Many choices')->assertSee('Family-friendly')->assertSee('4.0/5');

        try {
            Review::factory()->approved()->create(['user_id' => $this->client->id, 'night_market_id' => $market->id, 'food_id' => null]);
            $this->fail('The database accepted a duplicate market review.');
        } catch (UniqueConstraintViolationException) {
            $this->assertTrue(true);
        }
    }

    public function test_market_review_access_and_target_rules_are_enforced(): void
    {
        $market = NightMarket::factory()->create();
        $unverified = User::factory()->unverified()->create(['role' => User::ROLE_CLIENT]);
        $inactive = User::factory()->create(['role' => User::ROLE_CLIENT, 'is_active' => false]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->get(route('night-markets.show', $market))->assertSee('Log in to Review')->assertSee('Register to Review');
        $this->get(route('client.night-markets.reviews.create', $market))->assertRedirect(route('login'));
        $this->actingAs($unverified)->get(route('client.night-markets.reviews.create', $market))->assertRedirect(route('verification.notice'));
        $this->actingAs($inactive)->get(route('client.night-markets.reviews.create', $market))->assertRedirect(route('login'));
        $this->actingAs($admin)->get(route('client.night-markets.reviews.create', $market))->assertForbidden();
        $this->actingAs($this->client)->post(route('client.night-markets.reviews.store', NightMarket::factory()->inactive()->create()), ['rating' => 5, 'comment' => 'This market should not be reviewable.'])->assertNotFound();

        $food = Food::factory()->create();
        $foodReview = Review::factory()->forFood($food)->approved()->create(['user_id' => $this->client->id]);
        $this->actingAs($this->client)->get(route('client.night-markets.reviews.edit', [$market, $foodReview]))->assertNotFound();
    }

    public function test_admin_sees_target_labels_and_can_delete_market_reviews(): void
    {
        $market = NightMarket::factory()->create();
        $review = Review::factory()->approved()->create(['night_market_id' => $market->id, 'food_id' => null]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->get(route('admin.reviews.index'))->assertOk()->assertSee('Night Market · '.$market->name)->assertSee('Target');
        $this->actingAs($admin)->delete(route('admin.reviews.destroy', $review))->assertRedirect(route('admin.reviews.index'));
        $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
    }

    public function test_navigation_uses_role_aware_logo_and_nested_active_sections(): void
    {
        $market = NightMarket::factory()->create();
        $this->get(route('night-markets.show', $market))->assertSee('href="'.route('home').'"', false)->assertSee('aria-current="page"', false);
        $this->actingAs($this->client)->get(route('client.visit-plans.smart-planner.index'))->assertSee('href="'.route('client.home').'"', false)->assertSee('My Visit Plans')->assertSee('aria-current="page"', false);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertSee('href="'.route('admin.dashboard').'"', false);
    }
}
