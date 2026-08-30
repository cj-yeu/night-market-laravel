<?php

namespace Tests\Feature;

use App\Models\CatalogImportProposal;
use App\Models\SocialMediaSource;
use App\Models\User;
use App\Services\SocialMediaMetadataService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class SocialMediaYouTubeMetadataTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        config([
            'services.youtube.data_api_key' => 'testing-youtube-key',
            'services.youtube.base_url' => 'https://www.googleapis.com/youtube/v3',
        ]);
    }

    public function test_draft_fetches_and_persists_official_youtube_metadata_with_the_minimal_request(): void
    {
        $description = '<strong>'.str_repeat('Useful market detail ', 300).'</strong>';
        Http::fake([
            'https://www.googleapis.com/youtube/v3/videos*' => Http::response($this->videoPayload('dQw4w9WgXcQ', [
                'title' => '<em>Night Market Walk</em>',
                'description' => $description,
                'thumbnails' => [
                    'high' => ['url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg'],
                    'maxres' => ['url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/maxresdefault.jpg'],
                ],
            ])),
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.social-media.automation.store'), [
                'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                'target_type' => CatalogImportProposal::TARGET_NEW_MARKET,
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Official YouTube metadata was retrieved. This proposal remains a draft; no catalog records were created.');

        $proposal = CatalogImportProposal::query()->latest('id')->firstOrFail();
        $source = $proposal->socialMediaSource->fresh();
        $this->assertSame(SocialMediaSource::METADATA_FETCHED, $source->metadata_status);
        $this->assertSame('youtube_data_api', $source->metadata_provider);
        $this->assertSame('Night Market Walk', $source->title);
        $this->assertSame(5000, mb_strlen($source->description_excerpt));
        $this->assertSame('Night Market Channel', $source->creator_name);
        $this->assertSame('https://i.ytimg.com/vi/dQw4w9WgXcQ/maxresdefault.jpg', $source->thumbnail_url);
        $this->assertSame('2026-08-30', $source->published_at?->toDateString());
        $this->assertNull($source->failure_code);
        $this->assertSame(CatalogImportProposal::STATUS_DRAFT, $proposal->fresh()->status);
        $this->assertDatabaseCount('catalog_import_proposals', 1);
        $this->actingAs($this->admin)
            ->get(route('admin.social-media.automation.show', $proposal))
            ->assertOk()
            ->assertSee('Night Market Walk')
            ->assertSee('Night Market Channel')
            ->assertSee('Metadata is current and will be refreshed after 24 hours if needed.')
            ->assertDontSee('testing-youtube-key');

        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'GET'
                && str_starts_with($request->url(), 'https://www.googleapis.com/youtube/v3/videos?')
                && $query === [
                    'id' => 'dQw4w9WgXcQ',
                    'part' => 'snippet',
                    'fields' => 'items(id,snippet(title,description,channelId,channelTitle,publishedAt,thumbnails))',
                    'key' => 'testing-youtube-key',
                ];
        });
    }

    public function test_missing_api_key_records_a_safe_failure_without_an_http_request(): void
    {
        config(['services.youtube.data_api_key' => null]);
        $proposal = $this->proposalFor($this->sourceFor($this->videoId('missing-key')));

        $this->actingAs($this->admin)
            ->post(route('admin.social-media.automation.sources.fetch-metadata', $proposal->socialMediaSource))
            ->assertRedirect()
            ->assertSessionHas('status', 'Official YouTube metadata is not configured. Ask an administrator to configure the YouTube Data API key. This proposal remains a draft; no catalog records were created.');

        $this->assertDatabaseHas('social_media_sources', [
            'id' => $proposal->social_media_source_id,
            'metadata_status' => SocialMediaSource::METADATA_FAILED,
            'failure_code' => SocialMediaMetadataService::FAILURE_YOUTUBE_CONFIG_MISSING,
        ]);
        $this->assertSame(CatalogImportProposal::STATUS_DRAFT, $proposal->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_empty_and_http_failure_responses_are_saved_as_safe_failure_codes(): void
    {
        $cases = [
            'empty' => [Http::response(['items' => []]), SocialMediaMetadataService::FAILURE_YOUTUBE_VIDEO_NOT_FOUND],
            'bad-request' => [Http::response([], 400), SocialMediaMetadataService::FAILURE_YOUTUBE_REQUEST_FAILED],
            'gone' => [Http::response([], 410), SocialMediaMetadataService::FAILURE_YOUTUBE_VIDEO_UNAVAILABLE],
            'quota' => [Http::response(['error' => ['errors' => [['reason' => 'quotaExceeded']]]], 403), SocialMediaMetadataService::FAILURE_YOUTUBE_QUOTA_EXCEEDED],
            'forbidden' => [Http::response(['error' => ['errors' => [['reason' => 'forbidden']]]], 403), SocialMediaMetadataService::FAILURE_YOUTUBE_API_FORBIDDEN],
            'rate' => [Http::response([], 429), SocialMediaMetadataService::FAILURE_YOUTUBE_RATE_LIMITED],
            'unavailable' => [Http::response([], 503), SocialMediaMetadataService::FAILURE_YOUTUBE_PROVIDER_UNAVAILABLE],
        ];
        $responses = [];

        foreach ($cases as $suffix => [$response]) {
            $responses[$this->videoId($suffix)] = $response;
        }

        Http::fake(function (Request $request) use ($responses) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $responses[$query['id'] ?? ''] ?? Http::response([], 500);
        });

        foreach ($cases as $suffix => [, $failureCode]) {
            $source = $this->sourceFor($this->videoId($suffix));
            $proposal = $this->proposalFor($source);

            $this->actingAs($this->admin)
                ->post(route('admin.social-media.automation.sources.fetch-metadata', $source))
                ->assertRedirect();

            $this->assertDatabaseHas('social_media_sources', [
                'id' => $source->id,
                'metadata_status' => SocialMediaSource::METADATA_FAILED,
                'failure_code' => $failureCode,
            ]);
            $this->assertSame(CatalogImportProposal::STATUS_DRAFT, $proposal->fresh()->status);
        }
    }

    public function test_timeout_and_invalid_responses_are_never_rendered_or_persisted_as_raw_provider_errors(): void
    {
        $cases = [
            'timeout' => fn () => throw new ConnectionException('provider timeout'),
            'malformed' => Http::response('not valid json', 200, ['Content-Type' => 'application/json']),
            'missing-snippet' => Http::response(['items' => [['id' => 'placeholder']]]),
            'mismatched-id' => Http::response($this->videoPayload('anotherVideo')),
        ];
        $responses = [];

        foreach ($cases as $suffix => $response) {
            $responses[$this->videoId($suffix)] = $response;
        }

        Http::fake(function (Request $request) use ($responses) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $response = $responses[$query['id'] ?? ''] ?? Http::response([], 500);

            return is_callable($response) ? $response() : $response;
        });

        foreach ($cases as $suffix => $response) {
            $source = $this->sourceFor($this->videoId($suffix));
            $this->proposalFor($source);

            $this->actingAs($this->admin)
                ->post(route('admin.social-media.automation.sources.fetch-metadata', $source))
                ->assertRedirect()
                ->assertSessionMissing('errors');

            $source->refresh();
            $this->assertSame(SocialMediaSource::METADATA_FAILED, $source->metadata_status);
            $this->assertContains($source->failure_code, [
                SocialMediaMetadataService::FAILURE_YOUTUBE_TIMEOUT,
                SocialMediaMetadataService::FAILURE_YOUTUBE_INVALID_RESPONSE,
            ]);
            $this->assertStringNotContainsString('provider timeout', $source->failure_code);
        }
    }

    public function test_manual_retry_uses_the_same_source_and_a_fresh_source_skips_another_request(): void
    {
        config(['services.youtube.data_api_key' => null]);
        $source = $this->sourceFor($this->videoId('manual-retry'));
        $proposal = $this->proposalFor($source);

        $this->actingAs($this->admin)
            ->post(route('admin.social-media.automation.sources.fetch-metadata', $source))
            ->assertRedirect();
        Http::assertNothingSent();

        config(['services.youtube.data_api_key' => 'testing-youtube-key']);
        Http::fake(['https://www.googleapis.com/youtube/v3/videos*' => Http::response($this->videoPayload($source->external_content_id))]);
        $this->actingAs($this->admin)
            ->post(route('admin.social-media.automation.sources.fetch-metadata', $source))
            ->assertRedirect();

        $this->assertDatabaseCount('social_media_sources', 1);
        $this->assertDatabaseCount('catalog_import_proposals', 1);
        $this->assertSame(SocialMediaSource::METADATA_FETCHED, $source->fresh()->metadata_status);
        Http::assertSentCount(1);

        Http::fake();
        $this->actingAs($this->admin)
            ->post(route('admin.social-media.automation.sources.fetch-metadata', $source))
            ->assertRedirect();
        Http::assertNothingSent();
        $this->assertSame(CatalogImportProposal::STATUS_DRAFT, $proposal->fresh()->status);
    }

    public function test_guests_and_clients_cannot_fetch_metadata_and_the_implementation_writes_no_custom_logs(): void
    {
        Log::spy();
        $source = $this->sourceFor($this->videoId('authorization'));
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);

        $this->post(route('admin.social-media.automation.sources.fetch-metadata', $source))
            ->assertRedirect(route('login'));
        $this->actingAs($client)
            ->post(route('admin.social-media.automation.sources.fetch-metadata', $source))
            ->assertForbidden();

        Http::assertNothingSent();
        Log::shouldNotHaveReceived('debug');
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }

    private function sourceFor(string $videoId): SocialMediaSource
    {
        $url = 'https://www.youtube.com/watch?v='.$videoId;

        return SocialMediaSource::query()->create([
            'platform' => SocialMediaSource::PLATFORM_YOUTUBE,
            'canonical_url' => $url,
            'url_fingerprint' => hash('sha256', $url),
            'external_content_id' => $videoId,
            'metadata_status' => SocialMediaSource::METADATA_PENDING,
        ]);
    }

    private function proposalFor(SocialMediaSource $source): CatalogImportProposal
    {
        return CatalogImportProposal::query()->create([
            'social_media_source_id' => $source->id,
            'target_type' => CatalogImportProposal::TARGET_NEW_MARKET,
            'status' => CatalogImportProposal::STATUS_DRAFT,
            'revision' => 1,
            'created_by' => $this->admin->id,
        ]);
    }

    private function videoId(string $seed): string
    {
        return substr(hash('sha256', $seed), 0, 11);
    }

    /** @param array<string, mixed> $snippet @return array<string, mixed> */
    private function videoPayload(string $videoId, array $snippet = []): array
    {
        return [
            'items' => [[
                'id' => $videoId,
                'snippet' => array_merge([
                    'title' => 'Night Market Video',
                    'description' => 'A public video description.',
                    'channelId' => 'UCpublicChannel',
                    'channelTitle' => 'Night Market Channel',
                    'publishedAt' => '2026-08-30T12:00:00Z',
                    'thumbnails' => [
                        'default' => ['url' => 'https://i.ytimg.com/vi/'.$videoId.'/default.jpg'],
                    ],
                ], $snippet),
            ]],
        ];
    }
}
