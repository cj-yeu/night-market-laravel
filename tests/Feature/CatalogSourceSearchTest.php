<?php

namespace Tests\Feature;

use App\Contracts\HostnameResolver;
use App\Services\CatalogSourceReader;
use App\Services\CatalogSourceSearchService;
use App\Services\GeminiCatalogSourceService;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CatalogSourceSearchTest extends TestCase
{
    // No database trait, migrations, records or real provider requests.
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake([]);
        config(['services.catalog_search.tavily_key' => 'fake-search', 'services.catalog_search.tavily_free_confirmed' => true,
            'services.youtube.data_api_key' => 'fake-video', 'services.gemini.api_key' => 'fake-analysis',
            'services.catalog_ai.model' => 'gemini-3.5-flash-lite', 'services.catalog_ai.free_tier_confirmed' => true]);
    }

    private function video(): array
    {
        return ['id' => ['videoId' => 'TESTvideo01'], 'snippet' => ['title' => 'TEST source &amp; food', 'channelTitle' => 'TEST channel',
            'description' => 'Search preview only', 'publishedAt' => '2026-09-01T12:00:00Z',
            'thumbnails' => ['medium' => ['url' => 'https://i.ytimg.com/vi/TESTvideo01/mqdefault.jpg']]]];
    }

    public function test_search_combines_actual_provider_sources_without_gemini_or_generated_answer_urls(): void
    {
        config(['services.gemini.api_key' => null, 'services.catalog_ai.free_tier_confirmed' => false]);
        Http::fake(['api.tavily.com/search' => Http::response(['answer' => 'https://invented.test/', 'results' => [
            ['url' => 'https://article.example.test/ss2', 'title' => 'TEST article', 'content' => 'Preview only'],
            ['url' => 'http://insecure.test/', 'title' => 'Skip'], ['url' => 'https://user:pass@secret.test/', 'title' => 'Skip'],
            ['url' => 'https://www.youtube.com/watch?v=TESTvideo01', 'title' => 'Exclude from articles'],
        ]]), 'www.googleapis.com/youtube/v3/search*' => Http::response(['items' => [$this->video()]])]);
        $r = app(CatalogSourceSearchService::class)->search('Pasar Malam SS2', 'Petaling Jaya');
        $this->assertCount(2, $r['sources']);
        $this->assertSame('https://article.example.test/ss2', $r['sources'][0]['url']);
        $this->assertSame('https://www.youtube.com/watch?v=TESTvideo01', $r['sources'][1]['url']);
        $this->assertSame('TEST source & food', $r['sources'][1]['title']);
        $this->assertSame('Not analysed', $r['sources'][1]['status']);
        $this->assertSame([], $r['notices']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.tavily.com/search' && $r['search_depth'] === 'basic'
            && $r['auto_parameters'] === false && $r['include_answer'] === false && $r['include_raw_content'] === false
            && str_contains($r['query'], 'SS2 Petaling Jaya Selangor') && $r['max_results'] === 4);
        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://www.googleapis.com/youtube/v3/search?')
            && ! str_contains($r->url(), 'fake-video') && $r->hasHeader('x-goog-api-key', 'fake-video') && $r['type'] === 'video');
    }

    public function test_video_only_never_contacts_article_or_analysis_provider_and_rejects_bad_ids(): void
    {
        Http::fake(['www.googleapis.com/youtube/v3/search*' => Http::response(['items' => [$this->video(), ['id' => ['videoId' => '../bad']]]])]);
        $r = app(CatalogSourceSearchService::class)->search('SS2', 'Petaling Jaya', 'videos');
        $this->assertCount(1, $r['sources']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['maxResults'] === 8);
    }

    public function test_quota_failure_keeps_other_results_without_secret_leak_or_retry(): void
    {
        Http::fake(['api.tavily.com/search' => Http::response(['detail' => 'PRIVATE_PROVIDER_SECRET'], 432),
            'www.googleapis.com/youtube/v3/search*' => Http::response(['items' => [$this->video()]])]);
        $r = app(CatalogSourceSearchService::class)->search('SS2', 'Petaling Jaya');
        $this->assertCount(1, $r['sources']);
        $this->assertStringContainsString('HTTP 432', $r['notices'][0]);
        $this->assertStringNotContainsString('PRIVATE_PROVIDER_SECRET', json_encode($r));
        Http::assertSentCount(2);
    }

    public function test_missing_article_access_is_explicit_and_does_not_block_video_search(): void
    {
        config(['services.catalog_search.tavily_free_confirmed' => false]);
        Http::fake(['www.googleapis.com/youtube/v3/search*' => Http::response(['items' => [$this->video()]])]);
        $r = app(CatalogSourceSearchService::class)->search('SS2', 'Petaling Jaya');
        $this->assertCount(1, $r['sources']);
        $this->assertStringContainsString('Article search is not configured', $r['notices'][0]);
        Http::assertSentCount(1);
    }

    public function test_invalid_provider_payload_has_explicit_failure_not_fabricated_sources(): void
    {
        Http::fake(['api.tavily.com/search' => Http::response(['results' => 'not-an-array'])]);
        $r = app(CatalogSourceSearchService::class)->search('SS2', 'Petaling Jaya', 'articles');
        $this->assertSame([], $r['sources']);
        $this->assertStringContainsString('invalid response', $r['notices'][0]);
        Http::assertSentCount(1);
    }

    public function test_flash_lite_paid_grounding_is_blocked_before_request(): void
    {
        try {
            app(GeminiCatalogSourceService::class)->search('SS2', 'Petaling Jaya');
            $this->fail('Paid Gemini grounding must not be sent.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('not enabled', $e->errors()['source'][0]);
        }
        Http::assertNothingSent();
    }

    public function test_flash_lite_video_sends_content_not_metadata_and_does_not_retry_timeout(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::failedConnection()]);
        try {
            app(GeminiCatalogSourceService::class)->read('https://www.youtube.com/watch?v=TESTvideo01');
            $this->fail('No successful analysis on a connection failure.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('No HTTP response or automatic retry', $e->errors()['source'][0]);
        }
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/gemini-3.5-flash-lite:generateContent')
            && $r['contents'][0]['parts'][1]['file_data']['file_uri'] === 'https://www.youtube.com/watch?v=TESTvideo01'
            && ! isset($r['generationConfig']['thinkingConfig']) && ! isset($r['tools']));
    }

    public function test_article_redirects_are_bounded_and_images_resolve_against_final_body_url(): void
    {
        $resolver = new class implements HostnameResolver
        {
            public function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        };
        Http::fake(['https://article.test/ss2' => Http::response('', 301, ['Location' => '/ss2/']),
            'https://article.test/ss2/' => Http::response('<article>Actual SS2 body with supported food descriptions and menu evidence.<img src="food.jpg" alt="Food candidate"><img src=""></article>', 200, ['Content-Type' => 'text/html'])]);
        $r = (new CatalogSourceReader($resolver))->article('https://article.test/ss2');
        $this->assertSame('Article body read', $r['mode']);
        $this->assertSame('https://article.test/ss2/food.jpg', $r['images'][0]['url']);
        $this->assertCount(1, $r['images']);
        Http::assertSentCount(2);
    }

    public function test_redirect_to_private_host_is_rejected_before_contacting_it(): void
    {
        $resolver = new class implements HostnameResolver
        {
            public function resolve(string $host): array
            {
                return $host === 'private.test' ? ['127.0.0.1'] : ['93.184.216.34'];
            }
        };
        Http::fake(['https://article.test/ss2' => Http::response('', 302, ['Location' => 'https://private.test/secret'])]);
        try {
            (new CatalogSourceReader($resolver))->article('https://article.test/ss2');
            $this->fail('A redirect must not bypass the public IP gate.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('unsafe redirect', $e->errors()['source'][0]);
        }
        Http::assertSentCount(1);
    }

    public function test_redirect_loop_stops_after_three_hops(): void
    {
        $resolver = new class implements HostnameResolver
        {
            public function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        };
        Http::fake(['https://article.test/loop' => Http::response('', 301, ['Location' => '/loop'])]);
        try {
            (new CatalogSourceReader($resolver))->article('https://article.test/loop');
            $this->fail('Redirects cannot loop indefinitely.');
        } catch (ValidationException) {
            Http::assertSentCount(4);
        }
    }
}
