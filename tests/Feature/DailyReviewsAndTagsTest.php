<?php

namespace Tests\Feature;

use App\Models\Food;
use App\Models\NightMarket;
use App\Models\Review;
use App\Models\ReviewTag;
use App\Models\Stall;
use App\Models\User;
use App\Services\ReviewService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DailyReviewsAndTagsTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_client_can_review_different_targets_but_not_the_same_market_or_food_twice_today(): void
    {
        Carbon::setTestNow('2026-08-28 12:00:00');
        $client = $this->client();
        $market = NightMarket::factory()->create();
        $otherMarket = NightMarket::factory()->create();
        $food = $this->food($market);
        $otherFood = $this->food($market);

        $this->actingAs($client)->post(route('client.night-markets.reviews.store', $market), $this->payload())->assertRedirect();
        $this->actingAs($client)->post(route('client.night-markets.reviews.store', $otherMarket), $this->payload())->assertRedirect();
        $this->actingAs($client)->post(route('client.foods.reviews.store', $food), $this->payload())->assertRedirect();
        $this->actingAs($client)->post(route('client.foods.reviews.store', $otherFood), $this->payload())->assertRedirect();

        $message = 'You have already submitted a review for this item today. Please try again tomorrow.';
        RateLimiter::clear('review-submissions:'.$client->id);
        $this->actingAs($client)->post(route('client.night-markets.reviews.store', $market), $this->payload())->assertSessionHasErrors(['comment' => $message]);
        RateLimiter::clear('review-submissions:'.$client->id);
        $this->actingAs($client)->post(route('client.foods.reviews.store', $food), $this->payload())->assertSessionHasErrors(['comment' => $message]);
        $this->assertSame(4, Review::query()->count());
    }

    public function test_different_clients_and_the_next_day_can_review_the_same_target(): void
    {
        $market = NightMarket::factory()->create();
        Carbon::setTestNow('2026-08-28 12:00:00');
        $this->actingAs($this->client())->post(route('client.night-markets.reviews.store', $market), $this->payload())->assertRedirect();
        $this->actingAs($this->client())->post(route('client.night-markets.reviews.store', $market), $this->payload())->assertRedirect();
        Carbon::setTestNow('2026-08-29 12:00:00');
        $this->actingAs($this->client())->post(route('client.night-markets.reviews.store', $market), $this->payload())->assertRedirect();

        $this->assertSame(3, Review::query()->where('night_market_id', $market->id)->count());
    }

    public function test_database_unique_conflict_is_converted_to_the_friendly_daily_limit_message(): void
    {
        Carbon::setTestNow('2026-08-28 12:00:00');
        $client = $this->client();
        $market = NightMarket::factory()->create();
        Review::factory()->create(['user_id' => $client->id, 'night_market_id' => $market->id, 'food_id' => null, 'review_date' => now()->toDateString()]);

        $service = new class extends ReviewService
        {
            protected function hasReviewToday(User $user, ?int $marketId = null, ?int $foodId = null): bool
            {
                return false;
            }
        };

        try {
            $service->createMarketForClient($client, $market, $this->payload());
            $this->fail('Expected the database unique conflict to be converted to validation feedback.');
        } catch (ValidationException $exception) {
            $this->assertSame('You have already submitted a review for this item today. Please try again tomorrow.', $exception->errors()['comment'][0]);
        }
    }

    public function test_review_tags_are_whitelisted_persisted_and_shown_in_public_and_profile_views(): void
    {
        $client = $this->client();
        $market = NightMarket::factory()->create();
        $tags = ReviewTag::query()->whereIn('name', ['Tasty', 'Clean'])->pluck('id')->all();

        $this->actingAs($client)->post(route('client.night-markets.reviews.store', $market), $this->payload(['tags' => $tags]))->assertRedirect();
        $review = Review::query()->firstOrFail();
        $this->assertSame(['Clean', 'Tasty'], $review->tags()->orderBy('name')->pluck('name')->all());
        $this->get(route('night-markets.show', $market))->assertSee('Tasty')->assertSee('Clean');
        $this->actingAs($client)->get(route('profile.edit'))->assertSee('Market Reviews')->assertSee('Tasty');

        $this->actingAs($client)->post(route('client.night-markets.reviews.store', NightMarket::factory()->create()), $this->payload(['tags' => [999999]]))->assertSessionHasErrors('tags.0');
    }

    public function test_profile_only_shows_the_current_clients_market_and_food_reviews_and_guests_are_redirected(): void
    {
        $client = $this->client();
        $other = $this->client();
        $market = NightMarket::factory()->create(['name' => 'My Market']);
        $food = $this->food($market, 'My Food');
        Review::factory()->create(['user_id' => $client->id, 'night_market_id' => $market->id, 'food_id' => null, 'review_date' => now()->toDateString(), 'comment' => 'My market review is visible here.']);
        Review::factory()->create(['user_id' => $client->id, 'night_market_id' => null, 'food_id' => $food->id, 'review_date' => now()->toDateString(), 'comment' => 'My food review is visible here.']);
        Review::factory()->create(['user_id' => $other->id, 'night_market_id' => $market->id, 'food_id' => null, 'review_date' => now()->toDateString(), 'comment' => 'Other client private review.']);

        $this->get(route('profile.edit'))->assertRedirect(route('login'));
        $this->actingAs($client)->get(route('profile.edit'))->assertSee('My Market')->assertSee('My Food')->assertDontSee('Other client private review.');
    }

    private function client(): User
    {
        return User::factory()->create(['role' => User::ROLE_CLIENT, 'is_active' => true]);
    }

    private function food(NightMarket $market, ?string $name = null): Food
    {
        return Food::factory()->create(['stall_id' => Stall::factory()->create(['night_market_id' => $market->id])->id, 'name' => $name ?? fake()->unique()->words(2, true)]);
    }

    private function payload(array $override = []): array
    {
        return [...['rating' => 5, 'comment' => 'A thoughtful review that is long enough.'], ...$override];
    }
}
