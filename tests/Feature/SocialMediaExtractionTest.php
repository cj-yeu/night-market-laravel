<?php

namespace Tests\Feature;

use App\Contracts\HostnameResolver;
use App\Models\Food;
use App\Models\NightMarket;
use App\Models\SocialMediaRecord;
use App\Models\Stall;
use App\Models\User;
use App\Services\SocialMediaDataService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SocialMediaExtractionTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private NightMarket $market;

    private Food $food;

    private FakeSocialHostnameResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new FakeSocialHostnameResolver;
        $this->app->instance(HostnameResolver::class, $this->resolver);
        Http::preventStrayRequests();

        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $this->market = NightMarket::factory()->create([
            'status' => NightMarket::STATUS_ACTIVE,
            'state' => 'Selangor',
        ]);
        $stall = Stall::factory()->create(['night_market_id' => $this->market->id]);
        $this->food = Food::factory()->create(['stall_id' => $stall->id]);
    }

    public function test_extraction_routes_enforce_guest_client_inactive_and_admin_access(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $inactiveAdmin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => false]);
        $routes = [
            ['get', route('admin.social-media.extract.create')],
            ['post', route('admin.social-media.extract.extract')],
            ['get', route('admin.social-media.extract.review')],
            ['post', route('admin.social-media.extract.store')],
        ];

        foreach ($routes as [$method, $url]) {
            $this->{$method}($url)->assertRedirect(route('login'));
        }

        foreach ($routes as [$method, $url]) {
            $this->actingAs($client)->{$method}($url)->assertForbidden();
        }

        $this->actingAs($inactiveAdmin)->get(route('admin.social-media.extract.create'))
            ->assertRedirect(route('login'));
        $this->assertGuest();

        $this->actingAs($this->admin)->get(route('admin.social-media.extract.create'))
            ->assertOk()->assertSee('Extract Public Post Metadata');
    }

    public function test_unsupported_schemes_hosts_credentials_localhost_and_ip_literals_are_rejected_without_requests(): void
    {
        foreach ([
            'ftp://www.instagram.com/post/1',
            'https://user:secret@www.instagram.com/post/1',
            'https://localhost/post/1',
            'https://127.0.0.1/post/1',
            'https://10.0.0.8/post/1',
            'https://example.com/post/1',
        ] as $url) {
            $this->actingAs($this->admin)
                ->from(route('admin.social-media.extract.create'))
                ->post(route('admin.social-media.extract.extract'), ['source_url' => $url])
                ->assertRedirect(route('admin.social-media.extract.create'))
                ->assertSessionHasErrors('source_url');
        }

        Http::assertNothingSent();
    }

    public function test_private_loopback_link_local_and_reserved_dns_results_are_rejected_before_http(): void
    {
        foreach (['10.0.0.5', '127.0.0.1', '169.254.20.1', '224.0.0.1'] as $unsafeIp) {
            $this->resolver->addresses = ['www.instagram.com' => [$unsafeIp]];

            $this->actingAs($this->admin)
                ->post(route('admin.social-media.extract.extract'), [
                    'source_url' => 'https://www.instagram.com/p/safe-looking',
                ])
                ->assertRedirect(route('admin.social-media.extract.review'))
                ->assertSessionHas('social_media_extraction_error');
        }

        Http::assertNothingSent();
    }

    public function test_redirect_destination_is_revalidated_before_following(): void
    {
        Http::fake([
            'https://www.instagram.com/*' => Http::response('', 302, [
                'Location' => 'http://127.0.0.1/internal',
            ]),
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.social-media.extract.extract'), [
                'source_url' => 'https://www.instagram.com/p/redirect',
            ])
            ->assertRedirect(route('admin.social-media.extract.review'))
            ->assertSessionHas('social_media_extraction_error');

        Http::assertSentCount(1);
    }

    public function test_cross_platform_redirect_is_rejected_after_safe_revalidation(): void
    {
        Http::fake([
            'https://www.instagram.com/*' => Http::response('', 302, [
                'Location' => 'https://www.youtube.com/watch?v=other',
            ]),
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.social-media.extract.extract'), [
                'source_url' => 'https://www.instagram.com/p/redirect',
            ])
            ->assertSessionHas('social_media_extraction_error');

        Http::assertSentCount(1);
    }

    public function test_oversized_html_is_rejected_without_storing_a_response_body(): void
    {
        Http::fake([
            'https://www.instagram.com/*' => Http::response(str_repeat('a', 524289), 200, [
                'Content-Type' => 'text/html',
            ]),
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.social-media.extract.extract'), [
                'source_url' => 'https://www.instagram.com/p/large',
            ])
            ->assertSessionHas('social_media_extraction_error', fn (string $message) => str_contains($message, 'too large'));

        $this->assertDatabaseCount('social_media_records', 0);
    }

    public function test_timeout_and_connection_failure_have_safe_manual_fallback(): void
    {
        Http::fake([
            'https://www.instagram.com/*' => Http::failedConnection('sensitive network detail'),
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.social-media.extract.extract'), [
                'source_url' => 'https://www.instagram.com/p/unavailable?token=secret',
            ])
            ->assertRedirect(route('admin.social-media.extract.review'))
            ->assertSessionHas('social_media_extraction_error', function (string $message): bool {
                return str_contains($message, 'timed out') && ! str_contains($message, 'token=secret');
            });
    }

    public function test_safe_open_graph_metadata_is_extracted_into_editable_review_fields(): void
    {
        Http::fake([
            'https://www.instagram.com/*' => Http::response(<<<'HTML'
                <!doctype html>
                <html><head>
                <meta property="og:title" content="&lt;b&gt;Selangor Food Night&lt;/b&gt;">
                <meta property="og:description" content="Try the satay &amp; noodles tonight.">
                <meta property="article:published_time" content="2026-08-19T19:00:00+08:00">
                <meta property="og:image" content="https://images.cdninstagram.com/public/post.jpg">
                <link rel="canonical" href="https://www.instagram.com/p/canonical">
                </head></html>
                HTML, 200, ['Content-Type' => 'text/html; charset=UTF-8']),
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('admin.social-media.extract.extract'), [
                'source_url' => 'https://www.instagram.com/p/submitted',
            ])
            ->assertRedirect(route('admin.social-media.extract.review'));

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://www.instagram.com/p/submitted'
            && ! $request->hasHeader('Authorization')
            && ! $request->hasHeader('Cookie'));

        $response = $this->actingAs($this->admin)->get(route('admin.social-media.extract.review'));
        $response->assertOk()
            ->assertSee('Public metadata was found')
            ->assertSee('value="Instagram"', false)
            ->assertSee('value="https://www.instagram.com/p/canonical"', false)
            ->assertSee('value="Selangor Food Night"', false)
            ->assertSee('Try the satay & noodles tonight.')
            ->assertSee('value="2026-08-19"', false)
            ->assertSee('value="https://images.cdninstagram.com/public/post.jpg"', false);
    }

    public function test_reviewed_extraction_is_saved_pending_with_only_allowed_fields(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.social-media.extract.store'), $this->recordPayload([
                'extraction_status' => SocialMediaRecord::EXTRACTION_SUCCEEDED,
                'extracted_title' => 'Editable extracted title',
                'external_image_url' => 'https://images.cdninstagram.com/public/post.jpg',
                'status' => SocialMediaRecord::STATUS_APPROVED,
                'approved_by' => $this->admin->id,
            ]))
            ->assertRedirect(route('admin.social-media-records.index'));

        $record = SocialMediaRecord::latest('id')->firstOrFail();
        $this->assertSame(SocialMediaRecord::STATUS_PENDING, $record->status);
        $this->assertNull($record->approved_by);
        $this->assertSame(SocialMediaRecord::EXTRACTION_SUCCEEDED, $record->extraction_status);
        $this->assertSame('Editable extracted title', $record->extracted_title);
    }

    public function test_failed_extraction_can_be_completed_manually_and_saved_pending(): void
    {
        Http::fake([
            'https://www.facebook.com/*' => Http::response('Blocked', 403),
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.social-media.extract.extract'), [
                'source_url' => 'https://www.facebook.com/public/post',
            ])
            ->assertRedirect(route('admin.social-media.extract.review'));

        $this->actingAs($this->admin)->get(route('admin.social-media.extract.review'))
            ->assertOk()->assertSee('Automatic extraction was unavailable')
            ->assertSee('value="Facebook"', false);

        $this->actingAs($this->admin)
            ->post(route('admin.social-media.extract.store'), $this->recordPayload([
                'platform' => 'Facebook',
                'original_post_url' => 'https://www.facebook.com/public/post',
                'extraction_status' => SocialMediaRecord::EXTRACTION_FAILED,
            ]))
            ->assertRedirect(route('admin.social-media-records.index'));

        $this->assertDatabaseHas('social_media_records', [
            'platform' => 'Facebook',
            'extraction_status' => SocialMediaRecord::EXTRACTION_FAILED,
            'status' => SocialMediaRecord::STATUS_PENDING,
        ]);
    }

    public function test_unsafe_or_unresolvable_external_images_are_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.social-media.extract.store'), $this->recordPayload([
                'external_image_url' => 'http://images.cdninstagram.com/post.jpg',
            ]))
            ->assertSessionHasErrors('external_image_url');

        $this->resolver->addresses = ['images.cdninstagram.com' => ['127.0.0.1']];

        $this->actingAs($this->admin)
            ->post(route('admin.social-media.extract.store'), $this->recordPayload([
                'external_image_url' => 'https://images.cdninstagram.com/post.jpg',
            ]))
            ->assertSessionHasErrors('external_image_url');
    }

    public function test_admin_search_filters_dates_paginates_and_treats_wildcards_literally(): void
    {
        $literal = SocialMediaRecord::factory()->create([
            'night_market_id' => $this->market->id,
            'platform' => 'Instagram',
            'status' => SocialMediaRecord::STATUS_PENDING,
            'posted_date' => '2026-08-10',
            'content_summary' => 'Literal 100%_social phrase.',
        ]);
        SocialMediaRecord::factory()->create([
            'night_market_id' => $this->market->id,
            'content_summary' => 'Literal 100AXsocial phrase.',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.social-media-records.index', [
                'search' => '100%_social',
                'platform' => 'Instagram',
                'status' => SocialMediaRecord::STATUS_PENDING,
                'posted_from' => '2026-08-01',
                'posted_to' => '2026-08-15',
            ]))
            ->assertOk()->assertSee($literal->content_summary)
            ->assertDontSee('Literal 100AXsocial phrase.');

        SocialMediaRecord::factory()->count(16)->create([
            'night_market_id' => $this->market->id,
            'platform' => 'Instagram',
        ]);
        $this->actingAs($this->admin)
            ->get(route('admin.social-media-records.index', ['platform' => 'Instagram']))
            ->assertOk()->assertSee('platform=Instagram', false);
    }

    public function test_public_highlights_require_approved_public_market_and_public_matching_food(): void
    {
        $visible = SocialMediaRecord::factory()->approved()->create([
            'night_market_id' => $this->market->id,
            'food_id' => $this->food->id,
            'content_summary' => 'Visible approved social highlight.',
        ]);
        $inactiveFood = Food::factory()->inactive()->create(['stall_id' => $this->food->stall_id]);
        SocialMediaRecord::factory()->approved()->create([
            'night_market_id' => $this->market->id,
            'food_id' => $inactiveFood->id,
            'content_summary' => 'Hidden inactive food highlight.',
        ]);
        $otherFood = Food::factory()->create();
        SocialMediaRecord::factory()->approved()->create([
            'night_market_id' => $this->market->id,
            'food_id' => $otherFood->id,
            'content_summary' => 'Hidden mismatched food highlight.',
        ]);

        $this->get(route('social-media-highlights.index'))
            ->assertOk()->assertSee($visible->content_summary)
            ->assertDontSee('Hidden inactive food highlight.')
            ->assertDontSee('Hidden mismatched food highlight.');
    }

    public function test_public_links_and_images_use_only_safe_urls_with_local_placeholder_fallback(): void
    {
        SocialMediaRecord::factory()->approved()->create([
            'night_market_id' => $this->market->id,
            'original_post_url' => 'https://www.instagram.com/p/public',
            'external_image_url' => 'https://images.cdninstagram.com/post.jpg',
            'content_summary' => 'Safe URL highlight.',
        ]);
        SocialMediaRecord::factory()->approved()->create([
            'night_market_id' => $this->market->id,
            'original_post_url' => 'javascript:alert(1)',
            'external_image_url' => 'https://evil.example/tracker.jpg',
            'content_summary' => 'Unsafe URL highlight.',
        ]);

        $this->get(route('social-media-highlights.index'))
            ->assertOk()
            ->assertSee('href="https://www.instagram.com/p/public" target="_blank" rel="noopener noreferrer"', false)
            ->assertSee('src="https://images.cdninstagram.com/post.jpg"', false)
            ->assertSee(asset('images/night-market-placeholder.svg'))
            ->assertDontSee('javascript:alert(1)', false)
            ->assertDontSee('evil.example', false);
    }

    public function test_public_highlight_query_count_does_not_grow_per_record(): void
    {
        SocialMediaRecord::factory()->approved()->create(['night_market_id' => $this->market->id]);
        $service = app(SocialMediaDataService::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $service->publicHighlights([]);
        $service->publicInsights([]);
        $singleRecordQueries = count(DB::getQueryLog());

        SocialMediaRecord::factory()->count(4)->approved()->create(['night_market_id' => $this->market->id]);
        DB::flushQueryLog();
        $service->publicHighlights([]);
        $service->publicInsights([]);
        $multipleRecordQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($singleRecordQueries, $multipleRecordQueries);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function recordPayload(array $overrides = []): array
    {
        return array_merge([
            'night_market_id' => $this->market->id,
            'food_id' => $this->food->id,
            'platform' => 'Instagram',
            'original_post_url' => 'https://www.instagram.com/p/public',
            'extracted_title' => 'Public post title',
            'content_summary' => 'A concise public post excerpt.',
            'external_image_url' => null,
            'posted_date' => now()->subDay()->toDateString(),
            'likes' => 10,
            'comments' => 2,
            'shares' => 1,
            'extraction_status' => SocialMediaRecord::EXTRACTION_MANUAL,
        ], $overrides);
    }
}

class FakeSocialHostnameResolver implements HostnameResolver
{
    /** @var array<string, list<string>> */
    public array $addresses = [];

    public function resolve(string $hostname): array
    {
        return $this->addresses[$hostname] ?? ['93.184.216.34'];
    }
}
