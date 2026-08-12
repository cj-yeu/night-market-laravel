<?php

namespace Tests\Feature;

use App\Models\Food;
use App\Models\NightMarket;
use App\Models\SocialMediaRecord;
use App\Models\Stall;
use App\Models\User;
use App\Services\SocialMediaDataService;
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
            'engagement_count' => 143,
            'status' => SocialMediaRecord::STATUS_PENDING,
        ]);
    }

    public function test_required_fields_are_validated(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.social-media-records.store'), [])
            ->assertSessionHasErrors([
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
                'platform' => 'LinkedIn',
            ]))
            ->assertSessionHasErrors('platform');
    }

    public function test_youtube_is_accepted(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.social-media-records.store'), $this->validPayload([
                'platform' => 'YouTube',
                'original_post_url' => 'https://www.youtube.com/watch?v=example',
            ]))
            ->assertRedirect(route('admin.social-media-records.index'));

        $this->assertDatabaseHas('social_media_records', ['platform' => 'YouTube']);
    }

    public function test_record_can_be_created_pending_without_confirmed_relations(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.social-media-records.store'), $this->validPayload([
                'night_market_id' => null,
                'food_id' => null,
            ]))
            ->assertRedirect(route('admin.social-media-records.index'));

        $this->assertDatabaseHas('social_media_records', [
            'night_market_id' => null,
            'food_id' => null,
            'status' => SocialMediaRecord::STATUS_PENDING,
        ]);
    }

    public function test_hashtags_are_normalized_deduplicated_and_no_match_text_is_safe(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.social-media-records.store'), $this->validPayload([
                'content_summary' => 'Try this! #PasarMalam #Food #pasarmalam #FOOD.',
            ]))
            ->assertRedirect(route('admin.social-media-records.index'));

        $record = SocialMediaRecord::latest('id')->firstOrFail();
        $this->assertSame(['#pasarmalam', '#food'], $record->extracted_hashtags);

        $this->actingAs($this->admin)
            ->post(route('admin.social-media-records.store'), $this->validPayload([
                'content_summary' => 'Plain public text without any recognizable tags or names.',
            ]))
            ->assertRedirect(route('admin.social-media-records.index'));

        $noMatchRecord = SocialMediaRecord::latest('id')->firstOrFail();
        $this->assertSame([], $noMatchRecord->extracted_hashtags);
        $this->assertSame([], $noMatchRecord->extracted_market_mentions);
        $this->assertSame([], $noMatchRecord->extracted_location_mentions);
        $this->assertSame([], $noMatchRecord->extracted_food_mentions);
    }

    public function test_market_location_and_food_names_are_extracted_from_pasted_text(): void
    {
        $this->market->update([
            'name' => 'Taman Megah Night Market',
            'city' => 'Petaling Jaya',
            'address' => 'Jalan SS 24/2',
        ]);
        $this->food->update(['name' => 'Smoky Chicken Satay']);

        $this->actingAs($this->admin)
            ->post(route('admin.social-media-records.store'), $this->validPayload([
                'content_summary' => 'Visit Taman Megah Night Market in Petaling Jaya for Smoky Chicken Satay.',
            ]))
            ->assertRedirect(route('admin.social-media-records.index'));

        $record = SocialMediaRecord::latest('id')->firstOrFail();
        $this->assertSame(['Taman Megah Night Market'], $record->extracted_market_mentions);
        $this->assertContains('Petaling Jaya', $record->extracted_location_mentions);
        $this->assertSame(['Smoky Chicken Satay'], $record->extracted_food_mentions);
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

    public function test_admin_can_edit_extracted_values_and_update_resets_approval(): void
    {
        $record = SocialMediaRecord::factory()->approved()->create([
            'night_market_id' => $this->market->id,
            'food_id' => $this->food->id,
            'approved_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->patch(route('admin.social-media-records.update', $record), $this->validPayload([
                'extracted_hashtags' => '#NightMarket, #Selangor, #nightmarket',
                'extracted_location_mentions' => 'Petaling Jaya, Selangor',
                'extracted_market_mentions' => 'Confirmed Market Name',
                'extracted_food_mentions' => 'Confirmed Food Name',
            ]))
            ->assertRedirect(route('admin.social-media-records.index'));

        $record->refresh();
        $this->assertSame(['#nightmarket', '#selangor'], $record->extracted_hashtags);
        $this->assertSame(['Petaling Jaya', 'Selangor'], $record->extracted_location_mentions);
        $this->assertSame(['Confirmed Market Name'], $record->extracted_market_mentions);
        $this->assertSame(['Confirmed Food Name'], $record->extracted_food_mentions);
        $this->assertSame(SocialMediaRecord::STATUS_PENDING, $record->status);
        $this->assertNull($record->approved_by);
        $this->assertNull($record->approved_at);
    }

    public function test_admin_can_approve_and_reject_records(): void
    {
        $approvedRecord = SocialMediaRecord::factory()->create([
            'night_market_id' => $this->market->id,
            'food_id' => $this->food->id,
        ]);
        $rejectedRecord = SocialMediaRecord::factory()->create([
            'night_market_id' => null,
            'food_id' => null,
        ]);

        $this->actingAs($this->admin)
            ->patch(route('admin.social-media-records.moderate', $approvedRecord), [
                'status' => SocialMediaRecord::STATUS_APPROVED,
            ])
            ->assertRedirect(route('admin.social-media-records.index'))
            ->assertSessionHas('status', 'The social media record was approved successfully.');

        $approvedRecord->refresh();
        $this->assertSame(SocialMediaRecord::STATUS_APPROVED, $approvedRecord->status);
        $this->assertSame($this->admin->id, $approvedRecord->approved_by);
        $this->assertNotNull($approvedRecord->approved_at);

        $this->actingAs($this->admin)
            ->patch(route('admin.social-media-records.moderate', $rejectedRecord), [
                'status' => SocialMediaRecord::STATUS_REJECTED,
            ])
            ->assertRedirect(route('admin.social-media-records.index'));

        $this->assertSame(SocialMediaRecord::STATUS_REJECTED, $rejectedRecord->refresh()->status);
    }

    public function test_relationless_record_cannot_be_approved(): void
    {
        $record = SocialMediaRecord::factory()->create([
            'night_market_id' => null,
            'food_id' => null,
        ]);

        $this->actingAs($this->admin)
            ->patch(route('admin.social-media-records.moderate', $record), [
                'status' => SocialMediaRecord::STATUS_APPROVED,
            ])
            ->assertSessionHasErrors('night_market_id');

        $this->assertSame(SocialMediaRecord::STATUS_PENDING, $record->refresh()->status);
    }

    public function test_admin_can_delete_a_record(): void
    {
        $record = SocialMediaRecord::factory()->create(['night_market_id' => $this->market->id]);

        $this->actingAs($this->admin)
            ->delete(route('admin.social-media-records.destroy', $record))
            ->assertRedirect(route('admin.social-media-records.index'))
            ->assertSessionHas('status', 'The social media record was deleted successfully.');

        $this->assertDatabaseMissing('social_media_records', ['id' => $record->id]);
        $this->assertDatabaseHas('night_markets', ['id' => $this->market->id]);
        $this->assertDatabaseHas('foods', ['id' => $this->food->id]);
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
        $this->actingAs($client)->patch(route('admin.social-media-records.moderate', $record), [
            'status' => SocialMediaRecord::STATUS_APPROVED,
        ])->assertForbidden();
    }

    public function test_pending_and_rejected_records_are_hidden_but_approved_record_is_visible_to_client(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $approved = SocialMediaRecord::factory()->approved()->create([
            'night_market_id' => $this->market->id,
            'food_id' => $this->food->id,
            'content_summary' => 'Approved highlight visible to clients.',
            'engagement_count' => 143,
        ]);
        $pending = SocialMediaRecord::factory()->create([
            'night_market_id' => $this->market->id,
            'content_summary' => 'Pending highlight remains hidden.',
        ]);
        $rejected = SocialMediaRecord::factory()->rejected()->create([
            'night_market_id' => $this->market->id,
            'content_summary' => 'Rejected highlight remains hidden.',
        ]);

        $this->actingAs($client)
            ->get(route('client.social-media-highlights.index'))
            ->assertOk()
            ->assertSee($approved->content_summary)
            ->assertSee($this->market->name)
            ->assertSee($this->food->name)
            ->assertDontSee($pending->content_summary)
            ->assertDontSee($rejected->content_summary);
    }

    public function test_client_keyword_search_persists_and_reset_returns_approved_records(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $matching = SocialMediaRecord::factory()->approved()->create([
            'night_market_id' => $this->market->id,
            'content_summary' => 'Lantern atmosphere highlight.',
            'extracted_hashtags' => ['#lanterns'],
        ]);
        $other = SocialMediaRecord::factory()->approved()->create([
            'night_market_id' => $this->market->id,
            'content_summary' => 'Popular grilled food highlight.',
        ]);

        $this->actingAs($client)
            ->get(route('client.social-media-highlights.index', ['search' => '#lanterns']))
            ->assertOk()
            ->assertSee($matching->content_summary)
            ->assertDontSee($other->content_summary)
            ->assertSee('value="#lanterns"', false)
            ->assertSee('Reset Search');

        $this->actingAs($client)
            ->get(route('client.social-media-highlights.index'))
            ->assertOk()
            ->assertSee($matching->content_summary)
            ->assertSee($other->content_summary);
    }

    public function test_inaccessible_and_removed_market_relations_are_not_exposed_to_clients(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $inactiveMarket = NightMarket::factory()->inactive()->create();
        $outsideMarket = NightMarket::factory()->create(['state' => 'Johor']);
        $removedMarket = NightMarket::factory()->create();

        foreach ([
            [$inactiveMarket, 'Inactive market highlight.'],
            [$outsideMarket, 'Outside Selangor highlight.'],
            [$removedMarket, 'Removed market highlight.'],
        ] as [$market, $text]) {
            SocialMediaRecord::factory()->approved()->create([
                'night_market_id' => $market->id,
                'content_summary' => $text,
            ]);
        }
        $removedMarket->delete();

        $response = $this->actingAs($client)
            ->get(route('client.social-media-highlights.index'))
            ->assertOk();

        $response
            ->assertDontSee('Inactive market highlight.')
            ->assertDontSee('Outside Selangor highlight.')
            ->assertDontSee('Removed market highlight.');
    }

    public function test_client_insights_use_only_approved_visible_records(): void
    {
        $secondMarket = NightMarket::factory()->create(['name' => 'Second Insight Market']);
        $secondStall = Stall::factory()->create(['night_market_id' => $secondMarket->id]);
        $secondFood = Food::factory()->create(['stall_id' => $secondStall->id, 'name' => 'Second Insight Food']);

        SocialMediaRecord::factory()->approved()->create([
            'night_market_id' => $this->market->id,
            'food_id' => $this->food->id,
            'platform' => 'Instagram',
            'engagement_count' => 100,
        ]);
        SocialMediaRecord::factory()->approved()->create([
            'night_market_id' => $this->market->id,
            'food_id' => $this->food->id,
            'platform' => 'Instagram',
            'engagement_count' => 200,
        ]);
        SocialMediaRecord::factory()->approved()->create([
            'night_market_id' => $secondMarket->id,
            'food_id' => $secondFood->id,
            'platform' => 'YouTube',
            'engagement_count' => 50,
        ]);
        SocialMediaRecord::factory()->create([
            'night_market_id' => $secondMarket->id,
            'food_id' => $secondFood->id,
            'platform' => 'TikTok',
            'engagement_count' => 99999,
        ]);

        $insights = app(SocialMediaDataService::class)->clientInsights([]);

        $this->assertSame(['Instagram' => 2, 'YouTube' => 1], $insights['recordsByPlatform']);
        $this->assertSame(['Instagram' => 300, 'YouTube' => 50], $insights['engagementByPlatform']);
        $this->assertSame($this->market->name, $insights['mostMentionedMarket']['name']);
        $this->assertSame(2, $insights['mostMentionedMarket']['count']);
        $this->assertSame($this->food->name, $insights['mostMentionedFood']['name']);
        $this->assertSame(200, $insights['topEngagementPosts']->first()->engagement_count);
    }

    public function test_admin_cannot_access_client_highlights(): void
    {
        $this->actingAs($this->admin)
            ->get(route('client.social-media-highlights.index'))
            ->assertForbidden();
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
