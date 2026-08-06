<?php

namespace Tests\Feature;

use App\Models\NightMarket;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
    }

    public function test_client_can_submit_a_valid_review_and_it_becomes_pending(): void
    {
        $market = NightMarket::factory()->create();

        $this->actingAs($this->client)
            ->post(route('client.night-markets.reviews.store', $market), [
                'rating' => 5,
                'comment' => 'A wonderful market with plenty of delicious food.',
            ])
            ->assertRedirect(route('client.night-markets.show', $market))
            ->assertSessionHas('status', 'Your review was submitted and is awaiting approval.');

        $this->assertDatabaseHas('reviews', [
            'user_id' => $this->client->id,
            'night_market_id' => $market->id,
            'rating' => 5,
            'comment' => 'A wonderful market with plenty of delicious food.',
            'status' => Review::STATUS_PENDING,
        ]);
    }

    public function test_invalid_rating_and_comment_are_rejected(): void
    {
        $market = NightMarket::factory()->create();

        $this->actingAs($this->client)
            ->from(route('client.night-markets.reviews.create', $market))
            ->post(route('client.night-markets.reviews.store', $market), [
                'rating' => 6,
                'comment' => 'Short',
            ])
            ->assertRedirect(route('client.night-markets.reviews.create', $market))
            ->assertSessionHasErrors(['rating', 'comment']);

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_only_approved_reviews_appear_on_night_market_detail(): void
    {
        $market = NightMarket::factory()->create();

        Review::factory()->approved()->create([
            'night_market_id' => $market->id,
            'rating' => 4,
            'comment' => 'Approved review shown to clients.',
        ]);
        Review::factory()->approved()->create([
            'night_market_id' => $market->id,
            'rating' => 5,
            'comment' => 'Another approved review shown to clients.',
        ]);
        Review::factory()->create([
            'night_market_id' => $market->id,
            'comment' => 'Pending review must remain hidden.',
        ]);
        Review::factory()->rejected()->create([
            'night_market_id' => $market->id,
            'comment' => 'Rejected review must remain hidden.',
        ]);

        $this->actingAs($this->client)
            ->get(route('client.night-markets.show', $market))
            ->assertOk()
            ->assertSee('Approved review shown to clients.')
            ->assertSee('Another approved review shown to clients.')
            ->assertSee('4.5/5')
            ->assertSee('2 reviews')
            ->assertDontSee('Pending review must remain hidden.')
            ->assertDontSee('Rejected review must remain hidden.');
    }

    public function test_non_admin_cannot_access_review_moderation(): void
    {
        $this->actingAs($this->client)
            ->get(route('admin.reviews.index'))
            ->assertForbidden();
    }

    public function test_admin_can_approve_a_pending_review(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $review = Review::factory()->create([
            'comment' => 'Pending review ready for approval.',
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.reviews.update', $review), [
                'status' => Review::STATUS_APPROVED,
            ])
            ->assertRedirect(route('admin.reviews.index'))
            ->assertSessionHas('status', 'The review has been approved.');

        $this->assertSame(Review::STATUS_APPROVED, $review->refresh()->status);

        $this->actingAs($this->client)
            ->get(route('client.night-markets.show', $review->night_market_id))
            ->assertOk()
            ->assertSee('Pending review ready for approval.');
    }

    public function test_client_cannot_submit_a_review_for_an_inactive_market(): void
    {
        $market = NightMarket::factory()->inactive()->create();

        $this->actingAs($this->client)
            ->post(route('client.night-markets.reviews.store', $market), [
                'rating' => 4,
                'comment' => 'This must not be accepted for an inactive market.',
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('reviews', 0);
    }
}
