<?php

namespace Tests\Feature;

use App\Models\Food;
use App\Models\NightMarket;
use App\Models\Review;
use App\Models\Stall;
use App\Models\User;
use App\Services\ReviewService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ReviewRatingsTest extends TestCase
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
        RateLimiter::clear($this->rateLimitKey($this->client));
    }

    protected function tearDown(): void
    {
        RateLimiter::clear($this->rateLimitKey($this->client));

        parent::tearDown();
    }

    public function test_review_routes_enforce_authentication_verification_activity_and_client_role(): void
    {
        $food = Food::factory()->create();
        $unverified = User::factory()->unverified()->create(['role' => User::ROLE_CLIENT]);
        $inactive = User::factory()->create(['role' => User::ROLE_CLIENT, 'is_active' => false]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->get(route('client.foods.reviews.create', $food))->assertRedirect(route('login'));
        $this->post(route('client.foods.reviews.store', $food), $this->validReview())->assertRedirect(route('login'));
        $this->actingAs($unverified)->get(route('client.foods.reviews.create', $food))->assertRedirect(route('verification.notice'));
        $this->actingAs($inactive)->get(route('client.foods.reviews.create', $food))
            ->assertRedirect(route('login'))->assertSessionHas('error', 'Your account is inactive. Please contact an administrator.');
        $this->assertGuest();
        $this->actingAs($admin)->get(route('client.foods.reviews.create', $food))->assertForbidden();
        $this->actingAs($admin)->post(route('client.foods.reviews.store', $food), $this->validReview())->assertForbidden();
        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_verified_client_review_is_trimmed_owned_and_published_immediately_for_route_food(): void
    {
        $food = Food::factory()->create();
        $otherFood = Food::factory()->create();
        $otherUser = User::factory()->create();

        $this->actingAs($this->client)
            ->post(route('client.foods.reviews.store', $food), [
                ...$this->validReview([
                    'comment' => '  Excellent noodles and friendly service.  ',
                    'tags' => ['tasty', 'good_value'],
                ]),
                'user_id' => $otherUser->id,
                'food_id' => $otherFood->id,
                'status' => Review::STATUS_REJECTED,
                'role' => User::ROLE_ADMIN,
            ])
            ->assertRedirect(route('foods.show', $food))
            ->assertSessionHas('status', 'Your review has been published.');

        $this->assertDatabaseHas('reviews', [
            'user_id' => $this->client->id,
            'food_id' => $food->id,
            'night_market_id' => null,
            'rating' => 5,
            'comment' => 'Excellent noodles and friendly service.',
            'status' => Review::STATUS_APPROVED,
        ]);
        $this->get(route('foods.show', $food))
            ->assertOk()->assertSee('Excellent noodles and friendly service.')->assertSee('Tasty')->assertSee('Good value')->assertSee('5.0/5');

        $this->assertSame(['tasty', 'good_value'], Review::query()->where('user_id', $this->client->id)->firstOrFail()->tags);
    }

    public function test_invalid_and_whitespace_only_reviews_are_rejected_without_consuming_rate_limit(): void
    {
        $food = Food::factory()->create();

        for ($attempt = 0; $attempt < 7; $attempt++) {
            $this->actingAs($this->client)
                ->post(route('client.foods.reviews.store', $food), ['rating' => '4.5', 'comment' => '   '])
                ->assertSessionHasErrors(['rating', 'comment']);
        }

        $this->actingAs($this->client)
            ->post(route('client.foods.reviews.store', $food), ['rating' => 5, 'comment' => ['invalid']])
            ->assertSessionHasErrors(['comment']);

        $this->assertSame(0, RateLimiter::attempts($this->rateLimitKey($this->client)));
        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_review_tags_are_limited_to_the_allowed_food_choices(): void
    {
        $food = Food::factory()->create();

        $this->actingAs($this->client)
            ->post(route('client.foods.reviews.store', $food), $this->validReview(['tags' => ['easy_parking']]))
            ->assertSessionHasErrors(['tags.0']);
    }

    public function test_rate_limiter_blocks_the_sixth_valid_submission_attempt(): void
    {
        $foods = Food::factory()->count(6)->create();

        foreach ($foods->take(5) as $index => $food) {
            $this->actingAs($this->client)
                ->post(route('client.foods.reviews.store', $food), $this->validReview([
                    'comment' => "Valid review submission number {$index}.",
                ]))
                ->assertRedirect(route('foods.show', $food));
        }

        $this->actingAs($this->client)
            ->post(route('client.foods.reviews.store', $foods->last()), $this->validReview())
            ->assertSessionHasErrors(['comment' => 'Too many review submissions. Please wait a minute and try again.']);

        $this->assertDatabaseCount('reviews', 5);
    }

    public function test_one_review_per_client_per_food_per_day_is_guarded_in_application_and_database(): void
    {
        $food = Food::factory()->create();
        $review = Review::factory()->forFood($food)->approved()->create(['user_id' => $this->client->id]);

        $this->actingAs($this->client)
            ->get(route('client.foods.reviews.create', $food))
            ->assertRedirect(route('client.foods.reviews.edit', [$food, $review]));
        $this->actingAs($this->client)
            ->post(route('client.foods.reviews.store', $food), $this->validReview())
            ->assertSessionHasErrors(['comment']);

        $this->assertDatabaseCount('reviews', 1);
    }

    public function test_client_can_add_a_new_food_review_on_a_later_day_without_replacing_history(): void
    {
        $food = Food::factory()->create();
        $yesterday = now(Review::REVIEW_TIMEZONE)->subDay()->toDateString();

        Review::factory()->forFood($food)->approved()->create([
            'user_id' => $this->client->id,
            'review_date' => $yesterday,
            'comment' => 'Yesterday the noodles were excellent.',
        ]);

        $this->actingAs($this->client)
            ->get(route('client.foods.reviews.create', $food))
            ->assertOk()
            ->assertSee('Publish Review');

        $this->actingAs($this->client)
            ->post(route('client.foods.reviews.store', $food), $this->validReview([
                'comment' => 'Today the noodles are still excellent.',
            ]))
            ->assertRedirect(route('foods.show', $food));

        $this->assertSame(2, Review::query()
            ->where('user_id', $this->client->id)
            ->where('food_id', $food->id)
            ->count());
        $this->assertDatabaseHas('reviews', ['user_id' => $this->client->id, 'food_id' => $food->id, 'review_date' => Review::currentReviewDate()]);
        $this->get(route('foods.show', $food))
            ->assertSee('Yesterday the noodles were excellent.')
            ->assertSee('Today the noodles are still excellent.');
    }

    public function test_database_unique_index_rejects_a_duplicate_user_and_food_pair_on_the_same_day(): void
    {
        $food = Food::factory()->create();
        Review::factory()->forFood($food)->approved()->create(['user_id' => $this->client->id]);

        try {
            Review::factory()->forFood($food)->approved()->create(['user_id' => $this->client->id]);
            $this->fail('The database accepted a duplicate user and Food review.');
        } catch (UniqueConstraintViolationException) {
            $this->assertSame(1, Review::query()
                ->where('user_id', $this->client->id)
                ->where('food_id', $food->id)
                ->whereDate('review_date', Review::currentReviewDate())
                ->count());
        }
    }

    public function test_client_can_edit_only_own_review_and_edit_remains_public(): void
    {
        $food = Food::factory()->create();
        $review = Review::factory()->forFood($food)->approved()->create([
            'user_id' => $this->client->id,
            'rating' => 2,
            'comment' => 'Original review content.',
        ]);
        $other = Review::factory()->forFood($food)->approved()->create();

        $this->actingAs($this->client)
            ->get(route('client.foods.reviews.edit', [$food, $review]))
            ->assertOk()->assertSee('Edit My Review');
        $this->actingAs($this->client)
            ->patch(route('client.foods.reviews.update', [$food, $review]), $this->validReview([
                'rating' => 4,
                'comment' => 'Updated review content stays public.',
            ]))
            ->assertRedirect(route('foods.show', $food));

        $this->assertDatabaseHas('reviews', [
            'id' => $review->id, 'rating' => 4, 'comment' => 'Updated review content stays public.',
            'status' => Review::STATUS_APPROVED,
        ]);
        $this->actingAs($this->client)->get(route('client.foods.reviews.edit', [$food, $other]))->assertForbidden();
        $this->actingAs($this->client)
            ->patch(route('client.foods.reviews.update', [$food, $other]), $this->validReview())
            ->assertForbidden();
        $this->get(route('foods.show', $food))->assertSee('Updated review content stays public.');
    }

    public function test_route_food_mismatch_cannot_move_or_update_a_review(): void
    {
        $food = Food::factory()->create();
        $otherFood = Food::factory()->create();
        $review = Review::factory()->forFood($food)->approved()->create(['user_id' => $this->client->id]);

        $this->actingAs($this->client)
            ->patch(route('client.foods.reviews.update', [$otherFood, $review]), $this->validReview())
            ->assertNotFound();

        $this->assertSame($food->id, $review->fresh()->food_id);
    }

    public function test_reviews_are_rejected_for_non_public_food_stall_or_market(): void
    {
        $inactiveFood = Food::factory()->inactive()->create();
        $inactiveStall = Stall::factory()->inactive()->create();
        $stallFood = Food::factory()->for($inactiveStall)->create();
        $inactiveMarket = NightMarket::factory()->inactive()->create();
        $marketStall = Stall::factory()->for($inactiveMarket)->create();
        $marketFood = Food::factory()->for($marketStall)->create();

        foreach ([$inactiveFood, $stallFood, $marketFood] as $food) {
            $this->actingAs($this->client)
                ->post(route('client.foods.reviews.store', $food), $this->validReview())
                ->assertNotFound();
        }

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_public_summary_uses_only_food_reviews_and_calculates_distribution(): void
    {
        $food = Food::factory()->create();
        $otherFood = Food::factory()->create();
        Review::factory()->forFood($food)->approved()->create(['rating' => 5]);
        Review::factory()->forFood($food)->approved()->create(['rating' => 3]);
        Review::factory()->forFood($otherFood)->approved()->create(['rating' => 1]);
        Review::factory()->forFood($food)->rejected()->create(['rating' => 1]);

        $summary = app(ReviewService::class)->publicSummaryForFood($food, null);

        $this->assertSame(4.0, $summary['averageRating']);
        $this->assertSame(2, $summary['reviewCount']);
        $this->assertSame([5 => 1, 4 => 0, 3 => 1, 2 => 0, 1 => 0], $summary['ratingDistribution']);
        foreach ($summary['reviews'] as $review) {
            $this->assertTrue($review->relationLoaded('user'));
            $this->assertEqualsCanonicalizing(['id', 'name', 'avatar_path'], array_keys($review->user->getAttributes()));
        }
    }

    public function test_public_output_escapes_comments_and_never_exposes_private_author_data(): void
    {
        $food = Food::factory()->create();
        $reviewer = User::factory()->create(['name' => 'Public Reviewer', 'email' => 'private-reviewer@example.test']);
        Review::factory()->forFood($food)->approved()->create([
            'user_id' => $reviewer->id,
            'comment' => '<script>alert("review")</script> Safe words.',
        ]);

        $this->get(route('foods.show', $food))
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(&quot;review&quot;)&lt;/script&gt; Safe words.', false)
            ->assertDontSee('<script>alert("review")</script>', false)
            ->assertSee('Public Reviewer')
            ->assertDontSee($reviewer->email);
    }

    public function test_legacy_market_reviews_are_preserved_but_not_guessed_onto_a_food(): void
    {
        $food = Food::factory()->create();
        Review::factory()->approved()->create([
            'user_id' => $this->client->id,
            'night_market_id' => $food->stall->night_market_id,
            'food_id' => null,
            'comment' => 'Legacy market feedback remains unassigned.',
        ]);

        $this->assertDatabaseCount('reviews', 1);
        $this->get(route('foods.show', $food))
            ->assertOk()->assertDontSee('Legacy market feedback remains unassigned.')->assertSee('No reviews yet.');
    }

    public function test_food_page_shows_the_correct_review_action_for_each_audience(): void
    {
        $food = Food::factory()->create();

        $this->get(route('foods.show', $food))
            ->assertSee('Log in to Review')->assertSee('Register to Review')->assertDontSee('Write a Review');
        $unverified = User::factory()->unverified()->create(['role' => User::ROLE_CLIENT]);
        $this->actingAs($unverified)->get(route('foods.show', $food))->assertSee('Verify Email to Review');
        $this->actingAs($this->client)->get(route('foods.show', $food))->assertSee('Write a Review');

        $review = Review::factory()->forFood($food)->approved()->create(['user_id' => $this->client->id]);
        $this->actingAs($this->client)->get(route('foods.show', $food))
            ->assertSee('Edit My Review')->assertSee(route('client.foods.reviews.edit', [$food, $review]), false);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->get(route('foods.show', $food))
            ->assertDontSee('Write a Review')->assertDontSee('Edit My Review');
    }

    public function test_admin_access_is_authorized_and_management_hides_sensitive_user_fields(): void
    {
        $food = Food::factory()->create();
        $reviewer = User::factory()->create(['name' => 'Visible Name', 'email' => 'hidden@example.test']);
        $review = Review::factory()->forFood($food)->approved()->create(['user_id' => $reviewer->id]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->get(route('admin.reviews.index'))->assertRedirect(route('login'));
        $this->delete(route('admin.reviews.destroy', $review))->assertRedirect(route('login'));
        $this->actingAs($this->client)->get(route('admin.reviews.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.reviews.index'))
            ->assertOk()->assertSee('Visible Name')->assertSee($food->name)
            ->assertSee(route('admin.reviews.destroy', $review), false)
            ->assertSee('name="_token"', false)
            ->assertDontSee($reviewer->email)->assertDontSee('Approve')->assertDontSee('Reject');
    }

    public function test_admin_filters_are_bound_literal_combined_and_preserved_through_pagination(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $market = NightMarket::factory()->create();
        $stall = Stall::factory()->for($market)->create();
        $food = Food::factory()->for($stall)->create();
        $matching = Review::factory()->forFood($food)->approved()->create([
            'rating' => 4,
            'comment' => 'Literal 100%_\\ review phrase.',
            'created_at' => '2026-08-10 12:00:00',
        ]);
        Review::factory()->forFood($food)->approved()->create(['comment' => 'Literal 100AX review phrase.']);

        $filters = [
            'search' => '100%_\\', 'market_id' => $market->id, 'stall_id' => $stall->id,
            'food_id' => $food->id, 'rating' => 4, 'date_from' => '2026-08-01', 'date_to' => '2026-08-20',
        ];
        $response = $this->actingAs($admin)->get(route('admin.reviews.index', $filters));

        $response->assertOk()->assertSee($matching->comment)->assertDontSee('Literal 100AX review phrase.')
            ->assertSee('Reset Filters')->assertSee('value="2026-08-01"', false);

        Review::factory()->count(16)->approved()->create(['comment' => 'Pagination review phrase.']);
        $pagination = $this->actingAs($admin)->get(route('admin.reviews.index', ['search' => 'Pagination review']));
        $pagination->assertOk()->assertSee('search=Pagination%20review', false);
    }

    public function test_admin_can_delete_only_route_bound_review_and_rating_updates_immediately(): void
    {
        $food = Food::factory()->create();
        $review = Review::factory()->forFood($food)->approved()->create(['rating' => 5, 'user_id' => $this->client->id]);
        $unrelated = Review::factory()->forFood($food)->approved()->create(['rating' => 3]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($this->client)->delete(route('admin.reviews.destroy', $review))->assertForbidden();
        $this->actingAs($admin)->delete(route('admin.reviews.destroy', $review))
            ->assertRedirect(route('admin.reviews.index'))->assertSessionHas('status', 'The review has been deleted.');

        $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
        $this->assertDatabaseHas('reviews', ['id' => $unrelated->id]);
        $this->get(route('foods.show', $food))->assertDontSee((string) $review->comment)->assertSee('3.0/5');
        $this->actingAs($this->client)->get(route('client.foods.reviews.edit', [$food, $review]))->assertNotFound();

        RateLimiter::clear($this->rateLimitKey($this->client));
        $this->actingAs($this->client)
            ->post(route('client.foods.reviews.store', $food), $this->validReview(['comment' => 'Replacement review is allowed.']))
            ->assertRedirect(route('foods.show', $food));
    }

    public function test_approve_reject_and_get_mutation_routes_do_not_exist(): void
    {
        $review = Review::factory()->create();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->assertFalse(app('router')->has('admin.reviews.update'));
        $this->assertFalse(app('router')->has('client.foods.reviews.destroy'));
        $this->actingAs($admin)->patch('/admin/reviews/'.$review->id, ['status' => Review::STATUS_APPROVED])->assertMethodNotAllowed();
        $this->actingAs($admin)->get('/admin/reviews/'.$review->id)->assertMethodNotAllowed();
        $this->assertDatabaseHas('reviews', ['id' => $review->id]);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function validReview(array $overrides = []): array
    {
        return array_replace([
            'rating' => 5,
            'comment' => 'A detailed and valid Food review.',
        ], $overrides);
    }

    private function rateLimitKey(User $user): string
    {
        return 'review-submissions:'.$user->getAuthIdentifier();
    }
}
