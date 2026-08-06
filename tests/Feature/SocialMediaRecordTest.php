<?php

namespace Tests\Feature;

use App\Models\Food;
use App\Models\NightMarket;
use App\Models\SocialMediaRecord;
use App\Models\Stall;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SocialMediaRecordTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private NightMarket $market;

    private Food $food;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $this->market = NightMarket::factory()->create([
            'status' => NightMarket::STATUS_ACTIVE,
            'state' => 'Selangor',
        ]);
        $stall = Stall::factory()->create(['night_market_id' => $this->market->id]);
        $this->food = Food::factory()->create(['stall_id' => $stall->id]);
    }

    public function test_admin_can_open_the_social_media_records_list(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.social-media-records.index'))
            ->assertOk()
            ->assertSee('Social Media Records')
            ->assertSee('Add Social Media Record');
    }

    public function test_admin_can_create_a_valid_record(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.social-media-records.store'), $this->validPayload())
            ->assertRedirect(route('admin.social-media-records.index'))
            ->assertSessionHas('status', 'The social media record was added successfully.');

        $this->assertDatabaseHas('social_media_records', [
            'night_market_id' => $this->market->id,
            'food_id' => $this->food->id,
            'platform' => 'Instagram',
            'likes' => 120,
            'comments' => 15,
            'shares' => 8,
        ]);
    }

    public function test_required_fields_are_validated(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.social-media-records.store'), [])
            ->assertSessionHasErrors([
                'night_market_id',
                'platform',
                'original_post_url',
                'content_summary',
                'posted_date',
                'likes',
                'comments',
                'shares',
            ]);
    }

    public function test_invalid_platform_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.social-media-records.store'), $this->validPayload([
                'platform' => 'YouTube',
            ]))
            ->assertSessionHasErrors('platform');
    }

    public function test_invalid_url_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.social-media-records.store'), $this->validPayload([
                'original_post_url' => 'not-a-url',
            ]))
            ->assertSessionHasErrors('original_post_url');
    }

    public function test_future_posted_date_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.social-media-records.store'), $this->validPayload([
                'posted_date' => now()->addDay()->toDateString(),
            ]))
            ->assertSessionHasErrors('posted_date');
    }

    public function test_negative_likes_comments_and_shares_are_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.social-media-records.store'), $this->validPayload([
                'likes' => -1,
                'comments' => -1,
                'shares' => -1,
            ]))
            ->assertSessionHasErrors(['likes', 'comments', 'shares']);
    }

    public function test_related_food_from_another_market_is_rejected(): void
    {
        $otherFood = Food::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.social-media-records.store'), $this->validPayload([
                'food_id' => $otherFood->id,
            ]))
            ->assertSessionHasErrors([
                'food_id' => 'The selected food must be active and belong to the selected night market.',
            ]);
    }

    public function test_inactive_or_non_selangor_market_is_rejected(): void
    {
        $inactiveMarket = NightMarket::factory()->inactive()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.social-media-records.store'), $this->validPayload([
                'night_market_id' => $inactiveMarket->id,
                'food_id' => null,
            ]))
            ->assertSessionHasErrors('night_market_id');
    }

    public function test_admin_can_update_a_record(): void
    {
        $record = SocialMediaRecord::factory()->create([
            'night_market_id' => $this->market->id,
            'food_id' => $this->food->id,
        ]);

        $this->actingAs($this->admin)
            ->patch(route('admin.social-media-records.update', $record), $this->validPayload([
                'platform' => 'TikTok',
                'content_summary' => 'Updated public post summary.',
                'likes' => 999,
            ]))
            ->assertRedirect(route('admin.social-media-records.index'))
            ->assertSessionHas('status', 'The social media record was updated successfully.');

        $this->assertDatabaseHas('social_media_records', [
            'id' => $record->id,
            'platform' => 'TikTok',
            'content_summary' => 'Updated public post summary.',
            'likes' => 999,
        ]);
    }

    public function test_admin_can_delete_a_record(): void
    {
        $record = SocialMediaRecord::factory()->create(['night_market_id' => $this->market->id]);

        $this->actingAs($this->admin)
            ->delete(route('admin.social-media-records.destroy', $record))
            ->assertRedirect(route('admin.social-media-records.index'))
            ->assertSessionHas('status', 'The social media record was deleted successfully.');

        $this->assertDatabaseMissing('social_media_records', ['id' => $record->id]);
    }

    public function test_keyword_search_works(): void
    {
        $matchingRecord = SocialMediaRecord::factory()->create([
            'night_market_id' => $this->market->id,
            'content_summary' => 'Viral satay recommendation from visitors.',
        ]);
        $otherRecord = SocialMediaRecord::factory()->create([
            'content_summary' => 'A completely unrelated drinks post.',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.social-media-records.index', ['search' => 'Viral satay']))
            ->assertOk()
            ->assertSee($matchingRecord->content_summary)
            ->assertDontSee($otherRecord->content_summary);
    }

    public function test_market_and_platform_filters_work_together(): void
    {
        $matchingRecord = SocialMediaRecord::factory()->create([
            'night_market_id' => $this->market->id,
            'platform' => 'Facebook',
            'content_summary' => 'Matching filtered record.',
        ]);
        $wrongPlatform = SocialMediaRecord::factory()->create([
            'night_market_id' => $this->market->id,
            'platform' => 'Instagram',
            'content_summary' => 'Wrong platform record.',
        ]);
        $wrongMarket = SocialMediaRecord::factory()->create([
            'platform' => 'Facebook',
            'content_summary' => 'Wrong market record.',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.social-media-records.index', [
                'night_market_id' => $this->market->id,
                'platform' => 'Facebook',
            ]))
            ->assertOk()
            ->assertSee($matchingRecord->content_summary)
            ->assertDontSee($wrongPlatform->content_summary)
            ->assertDontSee($wrongMarket->content_summary);
    }

    public function test_client_cannot_access_any_social_media_admin_route(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $record = SocialMediaRecord::factory()->create(['night_market_id' => $this->market->id]);

        $this->actingAs($client)->get(route('admin.social-media-records.index'))->assertForbidden();
        $this->actingAs($client)->get(route('admin.social-media-records.create'))->assertForbidden();
        $this->actingAs($client)->post(route('admin.social-media-records.store'), $this->validPayload())->assertForbidden();
        $this->actingAs($client)->get(route('admin.social-media-records.edit', $record))->assertForbidden();
        $this->actingAs($client)->patch(route('admin.social-media-records.update', $record), $this->validPayload())->assertForbidden();
        $this->actingAs($client)->delete(route('admin.social-media-records.destroy', $record))->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'night_market_id' => $this->market->id,
            'food_id' => $this->food->id,
            'platform' => 'Instagram',
            'original_post_url' => 'https://www.instagram.com/p/example-post',
            'content_summary' => 'Visitors recommend the popular food and friendly market atmosphere.',
            'posted_date' => now()->subDay()->toDateString(),
            'likes' => 120,
            'comments' => 15,
            'shares' => 8,
        ], $overrides);
    }
}
