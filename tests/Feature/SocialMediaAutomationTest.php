<?php

namespace Tests\Feature;

use App\Models\CatalogImportProposal;
use App\Models\Food;
use App\Models\NightMarket;
use App\Models\SocialMediaRecord;
use App\Models\SocialMediaSource;
use App\Models\Stall;
use App\Models\User;
use App\Services\SocialMediaDiscoveryService;
use App\Services\YouTubeVideoUrlCanonicalizer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SocialMediaAutomationTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['services.youtube.data_api_key' => null]);
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
    }

    public function test_automation_imports_enforce_guest_client_admin_and_super_admin_access(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN, 'is_active' => true]);

        $this->get(route('admin.social-media.automation.index'))->assertRedirect(route('login'));
        $this->actingAs($client)->get(route('admin.social-media.automation.index'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.social-media.automation.index'))
            ->assertRedirect(route('admin.ai-import.history'));
        $this->actingAs($superAdmin)->get(route('admin.social-media.automation.create'))->assertRedirect(route('admin.ai-import.index'));
        $this->get(route('admin.ai-import.index'))->assertOk()->assertSee('Search Sources');
    }

    public function test_gap_detection_only_returns_eligible_records_without_active_children(): void
    {
        $marketGap = NightMarket::factory()->create(['name' => 'Gap Market']);
        $marketWithActiveStall = NightMarket::factory()->create(['name' => 'Covered Market']);
        $inactiveMarket = NightMarket::factory()->inactive()->create(['name' => 'Inactive Gap Market']);
        $outsideMarket = NightMarket::factory()->create(['name' => 'Outside Gap Market', 'state' => 'Johor']);

        Stall::factory()->create(['night_market_id' => $marketWithActiveStall->id]);
        $stallGap = Stall::factory()->create(['night_market_id' => $marketWithActiveStall->id, 'name' => 'Gap Stall']);
        $stallWithFood = Stall::factory()->create(['night_market_id' => $marketWithActiveStall->id, 'name' => 'Covered Stall']);
        Food::factory()->create(['stall_id' => $stallWithFood->id]);
        Stall::factory()->create(['night_market_id' => $inactiveMarket->id, 'name' => 'Inactive Parent Stall']);
        Stall::factory()->create(['night_market_id' => $outsideMarket->id, 'name' => 'Outside Parent Stall']);

        $service = app(SocialMediaDiscoveryService::class);
        $marketIds = $service->activeMarketsWithoutActiveStalls()->modelKeys();
        $stallIds = $service->activeStallsWithoutActiveFoods()->modelKeys();

        $this->assertContains($marketGap->id, $marketIds);
        $this->assertNotContains($marketWithActiveStall->id, $marketIds);
        $this->assertNotContains($inactiveMarket->id, $marketIds);
        $this->assertNotContains($outsideMarket->id, $marketIds);
        $this->assertContains($stallGap->id, $stallIds);
        $this->assertNotContains($stallWithFood->id, $stallIds);
        $this->assertNotContains($inactiveMarket->stalls()->first()->id, $stallIds);
        $this->assertNotContains($outsideMarket->stalls()->first()->id, $stallIds);
    }

    public function test_supported_youtube_variants_canonicalize_without_http_requests(): void
    {
        $canonicalizer = app(YouTubeVideoUrlCanonicalizer::class);
        $urls = [
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ&utm_source=test',
            'https://youtube.com/watch?v=dQw4w9WgXcQ',
            'https://m.youtube.com/watch?v=dQw4w9WgXcQ&feature=share',
            'https://youtu.be/dQw4w9WgXcQ?t=12',
            'https://www.youtube.com/shorts/dQw4w9WgXcQ?si=tracking',
            'https://www.youtube.com/embed/dQw4w9WgXcQ?rel=0',
        ];

        foreach ($urls as $url) {
            $source = $canonicalizer->canonicalize($url);

            $this->assertSame('youtube', $source['platform']);
            $this->assertSame('dQw4w9WgXcQ', $source['external_content_id']);
            $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $source['canonical_url']);
            $this->assertSame(hash('sha256', $source['canonical_url']), $source['url_fingerprint']);
        }

        Http::assertNothingSent();
    }

    public function test_malformed_non_youtube_and_non_https_urls_are_rejected_without_http_requests(): void
    {
        $market = NightMarket::factory()->create();

        foreach ([
            'http://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://youtube.com.evil.example/watch?v=dQw4w9WgXcQ',
            'https://www.youtube.com/channel/UC1234567890',
            'https://www.youtube.com/playlist?list=PL123',
            'https://www.youtube.com/watch?v=short',
            'https://user:secret@www.youtube.com/watch?v=dQw4w9WgXcQ',
        ] as $url) {
            $this->actingAs($this->admin)
                ->from(route('admin.social-media.automation.create'))
                ->post(route('admin.social-media.automation.store'), $this->proposalPayload($market, [
                    'youtube_url' => $url,
                ]))
                ->assertRedirect(route('admin.social-media.automation.create'))
                ->assertSessionHasErrors('youtube_url');
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('social_media_sources', 0);
        $this->assertDatabaseCount('catalog_import_proposals', 0);
    }

    public function test_same_youtube_video_does_not_create_duplicate_sources_or_drafts(): void
    {
        $market = NightMarket::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.social-media.automation.store'), $this->proposalPayload($market, [
                'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ?feature=share',
            ]))
            ->assertRedirect();

        $this->actingAs($this->admin)
            ->post(route('admin.social-media.automation.store'), $this->proposalPayload($market, [
                'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&utm_campaign=test',
            ]))
            ->assertRedirect();

        $this->assertDatabaseCount('social_media_sources', 1);
        $this->assertDatabaseCount('catalog_import_proposals', 1);
        $this->assertDatabaseHas('social_media_sources', [
            'canonical_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'external_content_id' => 'dQw4w9WgXcQ',
            'metadata_status' => SocialMediaSource::METADATA_FAILED,
            'failure_code' => 'youtube_config_missing',
        ]);
        Http::assertNothingSent();
    }

    public function test_existing_market_and_existing_stall_targets_are_revalidated_and_new_market_has_no_matches(): void
    {
        $market = NightMarket::factory()->create();
        $stall = Stall::factory()->create(['night_market_id' => $market->id]);
        $inactiveMarket = NightMarket::factory()->inactive()->create();
        $inactiveParentStall = Stall::factory()->create(['night_market_id' => $inactiveMarket->id]);

        $this->actingAs($this->admin)
            ->post(route('admin.social-media.automation.store'), [
                'youtube_url' => 'https://www.youtube.com/watch?v=aqz-KE-bpKQ',
                'target_type' => CatalogImportProposal::TARGET_EXISTING_MARKET,
                'matched_stall_id' => $stall->id,
            ])
            ->assertSessionHasErrors(['matched_night_market_id', 'matched_stall_id']);

        $this->actingAs($this->admin)
            ->post(route('admin.social-media.automation.store'), [
                'youtube_url' => 'https://www.youtube.com/watch?v=9bZkp7q19f0',
                'target_type' => CatalogImportProposal::TARGET_EXISTING_STALL,
                'matched_stall_id' => $inactiveParentStall->id,
            ])
            ->assertSessionHasErrors('matched_stall_id');

        $this->actingAs($this->admin)
            ->post(route('admin.social-media.automation.store'), [
                'youtube_url' => 'https://www.youtube.com/watch?v=3JZ_D3ELwOQ',
                'target_type' => CatalogImportProposal::TARGET_NEW_MARKET,
                'matched_night_market_id' => $market->id,
            ])
            ->assertSessionHasErrors('target_type');

        $response = $this->actingAs($this->admin)
            ->post(route('admin.social-media.automation.store'), [
                'youtube_url' => 'https://www.youtube.com/watch?v=kJQP7kiw5Fk',
                'target_type' => CatalogImportProposal::TARGET_EXISTING_STALL,
                'matched_stall_id' => $stall->id,
            ]);

        $proposal = CatalogImportProposal::latest('id')->firstOrFail();
        $response->assertRedirect(route('admin.ai-import.show', $proposal));
        $this->assertSame($market->id, $proposal->matched_night_market_id);
        $this->assertSame($stall->id, $proposal->matched_stall_id);

        $this->actingAs($this->admin)
            ->post(route('admin.social-media.automation.store'), [
                'youtube_url' => 'https://www.youtube.com/watch?v=60ItHLz5WEA',
                'target_type' => CatalogImportProposal::TARGET_NEW_MARKET,
            ])
            ->assertRedirect();

        $newMarketProposal = CatalogImportProposal::latest('id')->firstOrFail();
        $this->assertSame(CatalogImportProposal::TARGET_NEW_MARKET, $newMarketProposal->target_type);
        $this->assertNull($newMarketProposal->matched_night_market_id);
        $this->assertNull($newMarketProposal->matched_stall_id);
        Http::assertNothingSent();
    }

    public function test_draft_proposal_creates_no_catalog_records_and_preserves_public_highlights(): void
    {
        $market = NightMarket::factory()->create();
        $stall = Stall::factory()->create(['night_market_id' => $market->id]);
        $food = Food::factory()->create(['stall_id' => $stall->id]);
        $highlight = SocialMediaRecord::factory()->approved()->create([
            'night_market_id' => $market->id,
            'food_id' => $food->id,
            'content_summary' => 'Existing public highlight remains available.',
        ]);
        $counts = [
            'markets' => NightMarket::query()->count(),
            'stalls' => Stall::query()->count(),
            'foods' => Food::query()->count(),
        ];

        $this->actingAs($this->admin)
            ->post(route('admin.social-media.automation.store'), $this->proposalPayload($market, [
                'youtube_url' => 'https://www.youtube.com/watch?v=oHg5SJYRHA0',
                'target_type' => CatalogImportProposal::TARGET_NEW_MARKET,
                'matched_night_market_id' => null,
            ]))
            ->assertRedirect();

        $proposal = CatalogImportProposal::latest('id')->firstOrFail();
        $this->actingAs($this->admin)
            ->get(route('admin.ai-import.show', $proposal))
            ->assertOk()
            ->assertSee('Metadata retrieval needs attention.')
            ->assertSee('No Night Market, Stall, or Food record has been created.');

        $this->assertSame($counts['markets'], NightMarket::query()->count());
        $this->assertSame($counts['stalls'], Stall::query()->count());
        $this->assertSame($counts['foods'], Food::query()->count());
        $this->get(route('social-media-highlights.index'))
            ->assertOk()
            ->assertSee($highlight->content_summary);
        Http::assertNothingSent();
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function proposalPayload(NightMarket $market, array $overrides = []): array
    {
        return array_merge([
            'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'target_type' => CatalogImportProposal::TARGET_EXISTING_MARKET,
            'matched_night_market_id' => $market->id,
        ], $overrides);
    }
}
