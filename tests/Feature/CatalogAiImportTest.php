<?php

namespace Tests\Feature;

use App\Contracts\HostnameResolver;
use App\Models\CatalogCategory;
use App\Models\CatalogImportProposal;
use App\Models\CatalogSocialMediaSourceLink;
use App\Models\Food;
use App\Models\NightMarket;
use App\Models\Stall;
use App\Models\User;
use App\Services\CatalogAiImportService;
use App\Services\CatalogDraftImageStorage;
use App\Services\CatalogImportProposalService;
use App\Services\CatalogSourceReader;
use App\Services\GeminiCatalogSourceService;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CatalogAiImportTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private NightMarket $market;

    private string $url;

    private string $text;

    public function createApplication()
    {
        $app = parent::createApplication();
        $c = config('database.connections.'.config('database.default'));
        if (! $app->environment('testing') || config('database.default') !== 'mysql' || $c['database'] !== 'night_market_laravel_testing'
            || $c['host'] !== '127.0.0.1' || (string) $c['port'] !== '3306' || ! empty($c['url']) || ! empty($c['unix_socket']) || ! empty($c['read']) || ! empty($c['write'])) {
            throw new \RuntimeException('Isolated testing MySQL required; no migrations allowed.');
        }
        if (DB::selectOne('SELECT DATABASE() AS db')->db !== 'night_market_laravel_testing') {
            throw new \RuntimeException('Actual database mismatch');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake([]);
        Mail::fake();
        Notification::fake();
        Storage::fake('catalog_drafts');
        Storage::fake('public');
        config(['services.gemini.api_key' => 'fake-not-a-credential', 'services.gemini.model' => 'gemini-3.5-flash',
            'services.catalog_ai.model' => 'gemini-2.5-flash', 'services.catalog_ai.free_tier_confirmed' => true,
            'services.youtube.data_api_key' => null, 'services.catalog_search.tavily_key' => null,
            'services.catalog_search.tavily_free_confirmed' => false]);
        $this->app->instance(HostnameResolver::class, new class implements HostnameResolver
        {
            public function resolve(string $hostname): array
            {
                return ['93.184.216.34'];
            }
        });
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->market = NightMarket::factory()->create(['name' => 'TEST Source Market '.Str::random(8), 'city' => 'Petaling Jaya']);
        $this->url = 'https://catalog.example.test/'.Str::uuid();
        $this->text = $this->market->name.' in Petaling Jaya Selangor. TEST Sweet Stall belongs to '.$this->market->name.'. TEST Sweet Stall sells TEST Cake Dessert RM8 per slice.';
        CatalogCategory::firstOrCreate(['category_type' => 'food', 'normalized_name' => 'dessert'], ['name' => 'Dessert', 'is_active' => true]);
    }

    private function payload(): array
    {
        return ['market' => null, 'stalls' => [['name' => 'TEST Sweet Stall', 'description' => null, 'evidence_text' => $this->text,
            'confidence' => null, 'foods' => [['name' => 'TEST Cake', 'category' => 'Dessert', 'description' => null, 'price_min' => 8,
                'price_max' => 8, 'price_display' => 'RM8', 'is_must_try' => false, 'evidence_text' => $this->text, 'confidence' => null]]]], 'warnings' => [], 'insufficient_data' => false];
    }

    private function response(array $payload): array
    {
        return ['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => json_encode($payload)]]]]]];
    }

    private function draft(): CatalogImportProposal
    {
        $response = $this->actingAs($this->admin)->post(route('admin.ai-import.start'), ['market_id' => $this->market->id, 'url' => $this->url]);
        $response->assertStatus(303)->assertSessionHasNoErrors();

        return CatalogImportProposal::where('created_by', $this->admin->id)->latest('id')->firstOrFail();
    }

    private function analyse(CatalogImportProposal $p): void
    {
        Http::fake([$this->url => Http::response('<article>'.$this->text.'</article>', 200, ['Content-Type' => 'text/html']),
            'generativelanguage.googleapis.com/*' => Http::response($this->response($this->payload()))]);
        $this->post(route('admin.ai-import.analyse', $p), ['source_ids' => [0]])->assertStatus(303)->assertSessionHasNoErrors();
        $p->refresh();
    }

    private function edit(CatalogImportProposal $p, bool $photo = true): array
    {
        $row = ['selected' => 1, 'name' => 'TEST Cake', 'category' => 'Dessert', 'price_min' => 8, 'price_max' => 8,
            'photo_confirmed' => $photo ? 1 : 0, 'currency' => 'MYR', 'unit' => 'slice'];
        if ($photo) {
            $row['image'] = UploadedFile::fake()->image('cake.jpg');
        }

        return ['revision' => app(CatalogAiImportService::class)->revision($p), 'stalls' => [['name' => 'TEST Sweet Stall',
            'selected' => 1, 'parent_confirmed' => 1, 'foods' => [$row]]]];
    }

    public function test_module_routes_and_legacy_redirects_keep_admin_authorization(): void
    {
        $url = route('admin.ai-import.index');
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => 'client']))->get($url)->assertForbidden();
        $this->actingAs($this->admin)->get($url)->assertOk()->assertSee('Search Sources')->assertDontSee('Automation Imports');
        $this->get(route('admin.social-media.automation.index'))->assertRedirect(route('admin.ai-import.history'));
        $this->get(route('admin.social-media.automation.create'))->assertRedirect($url);
        $p = $this->draft();
        $this->get(route('admin.social-media.automation.show', $p))->assertRedirect(route('admin.ai-import.show', $p));
        $this->get(route('admin.ai-import.history'))->assertOk()->assertSee('Open Draft');
        Http::assertNothingSent();
    }

    public function test_search_uses_only_retrieved_urls_and_get_selection_is_user_scoped(): void
    {
        config(['services.catalog_search.tavily_key' => 'fake-search', 'services.catalog_search.tavily_free_confirmed' => true]);
        Http::fake(['api.tavily.com/search' => Http::response(['answer' => 'Invented https://made-up.example/ignore',
            'results' => [['url' => $this->url, 'title' => 'Retrieved article', 'content' => 'This source concerns the selected market.']]])]);
        $post = $this->actingAs($this->admin)->post(route('admin.ai-import.search'), ['market_id' => $this->market->id]);
        $post->assertStatus(303);
        $location = $post->headers->get('Location');
        $this->get($location)->assertOk()->assertSee('Retrieved article')->assertDontSee('made-up.example');
        $this->get($location)->assertOk();
        Http::assertSentCount(1);
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post(route('admin.ai-import.start'), ['search_id' => $query['search_id'], 'source_ids' => [0]])->assertSessionHasErrors('source');
    }

    public function test_unconfirmed_search_access_never_enables_paid_requests(): void
    {
        config(['services.catalog_search.tavily_key' => 'fake-search', 'services.catalog_search.tavily_free_confirmed' => false]);
        $this->actingAs($this->admin)->post(route('admin.ai-import.search'), ['market_id' => $this->market->id])->assertSessionHasErrors('search');
        Http::assertNothingSent();
    }

    public function test_catalog_model_is_independent_and_provider_errors_are_safe_without_retry(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['status' => 'RESOURCE_EXHAUSTED', 'message' => 'PRIVATE_PROVIDER_DETAIL']], 429)]);
        try {
            app(GeminiCatalogSourceService::class)->search('TEST Market', 'Petaling Jaya');
            $this->fail('A quota error must not look like an empty successful search.');
        } catch (ValidationException $e) {
            $message = implode(' ', $e->errors()['source']);
            $this->assertStringContainsString('HTTP 429, RESOURCE_EXHAUSTED', $message);
            $this->assertStringNotContainsString('PRIVATE_PROVIDER_DETAIL', $message);
        }
        Http::assertSent(fn ($r) => str_contains($r->url(), '/gemini-2.5-flash:generateContent')
            && isset($r['tools'][0]['google_search']) && $r['generationConfig']['thinkingConfig']['thinkingBudget'] === 0);
        Http::assertSentCount(1);
        $this->assertSame('gemini-3.5-flash', config('services.gemini.model'));
    }

    public function test_missing_key_stops_before_any_request(): void
    {
        config(['services.gemini.api_key' => '']);
        $p = $this->draft();
        $this->post(route('admin.ai-import.analyse', $p), ['source_ids' => [0], 'text' => $this->text])->assertSessionHasErrors('source');
        Http::assertNothingSent();
    }

    public function test_missing_search_configuration_does_not_block_link_analysis_and_review(): void
    {
        config(['services.catalog_ai.model' => 'gemini-3.5-flash-lite']);
        $this->actingAs($this->admin)->get(route('admin.ai-import.index'))->assertOk()
            ->assertSee('Automatic search needs a search provider key.')->assertSee('Paste URL');
        $p = $this->draft();
        $this->analyse($p);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/gemini-3.5-flash-lite:generateContent')
            && ! isset($r['generationConfig']['thinkingConfig']) && isset($r['generationConfig']['responseJsonSchema'])
            && ! isset($r['tools']));
        $this->patch(route('admin.ai-import.update', $p), $this->edit($p))->assertStatus(303)->assertSessionHasNoErrors();
        $this->get(route('admin.ai-import.review', $p))->assertOk()->assertSee('TEST Cake');
        Http::assertSentCount(2);
    }

    public function test_tampered_search_kind_is_rejected_without_requests(): void
    {
        $this->actingAs($this->admin)->post(route('admin.ai-import.search'), ['market_id' => $this->market->id, 'search_kind' => 'paid'])
            ->assertSessionHasErrors('search_kind');
        Http::assertNothingSent();
    }

    public function test_unsupported_stall_name_keeps_supported_foods_in_incomplete_module_draft(): void
    {
        $p = $this->draft();
        $payload = $this->payload();
        $payload['stalls'][0]['name'] = 'UNSUPPORTED invented brand';
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->response($payload))]);
        $this->post(route('admin.ai-import.analyse', $p), ['source_ids' => [0], 'text' => $this->text])
            ->assertStatus(303)->assertSessionHasNoErrors();
        $data = app(CatalogAiImportService::class)->data($p->refresh());
        $this->assertSame('', $data['graph']['stalls'][0]['name']);
        $this->assertFalse($data['graph']['stalls'][0]['parent_confirmed']);
        $this->assertSame('TEST Cake', $data['graph']['stalls'][0]['foods'][0]['name']);
        $this->get(route('admin.ai-import.show', $p))->assertOk()->assertDontSee('UNSUPPORTED invented brand');
        $edit = $this->edit($p);
        $edit['stalls'][0]['name'] = '';
        $this->patch(route('admin.ai-import.update', $p), $edit)->assertStatus(303)->assertSessionHasNoErrors();
        $this->post(route('admin.ai-import.import', $p), ['revision' => app(CatalogAiImportService::class)->revision($p->refresh()), 'confirm' => 1])
            ->assertSessionHasErrors('draft');
    }

    public function test_not_found_reports_model_access_without_retries_or_raw_provider_details(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['status' => 'NOT_FOUND', 'message' => 'SENSITIVE_PROVIDER_MESSAGE']], 404)]);
        try {
            app(GeminiCatalogSourceService::class)->search('TEST Market', 'Petaling Jaya');
            $this->fail('A missing model must not appear as a successful empty search.');
        } catch (ValidationException $e) {
            $message = implode(' ', $e->errors()['source']);
            $this->assertStringContainsString('HTTP 404, NOT_FOUND', $message);
            $this->assertStringContainsString('generateContent access', $message);
            $this->assertStringNotContainsString('SENSITIVE_PROVIDER_MESSAGE', $message);
        }
        Http::assertSentCount(1);
    }

    public function test_transport_diagnostic_does_not_leak_exception_contents_or_disable_tls(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectException('SECRET_HEADER_DETAIL', new Request('POST', 'https://generativelanguage.googleapis.com'), null, ['errno' => 60]);
        });
        try {
            app(GeminiCatalogSourceService::class)->search('TEST Market', 'Petaling Jaya');
            $this->fail('TLS errors must stop the request.');
        } catch (ValidationException $e) {
            $message = implode(' ', $e->errors()['source']);
            $this->assertStringContainsString('cURL 60', $message);
            $this->assertStringContainsString('without disabling certificate verification', $message);
            $this->assertStringNotContainsString('SECRET_HEADER_DETAIL', $message);
        }
        $this->assertSame(1, $attempts);
    }

    public function test_private_drafts_survive_adapter_recreation_and_preview_requires_admin(): void
    {
        $p = $this->draft();
        $this->analyse($p);
        $this->patch(route('admin.ai-import.update', $p), $this->edit($p))->assertSessionHasNoErrors();
        $path = app(CatalogAiImportService::class)->data($p->fresh())['graph']['stalls'][0]['foods'][0]['image_path'];
        $root = Storage::disk('catalog_drafts')->path('');
        config(['filesystems.disks.catalog_drafts.root' => $root]);
        Storage::forgetDisk('catalog_drafts');
        $this->assertTrue(app(CatalogDraftImageStorage::class)->disk()->exists($path));
        $this->assertFalse(config('filesystems.disks.catalog_drafts.serve'));
        Storage::disk('public')->assertMissing($path);
        $url = route('admin.ai-import.image', [$p, 0, 0]);
        $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs(User::factory()->create(['role' => 'client']))->get($url)->assertForbidden();
        auth()->forgetGuards();
        $this->get($url)->assertRedirect(route('login'));
    }

    public function test_private_storage_rejects_a_root_inside_public_storage(): void
    {
        config(['filesystems.disks.catalog_drafts.root' => Storage::disk('public')->path('drafts')]);
        Storage::forgetDisk('catalog_drafts');
        $this->expectException(ValidationException::class);
        app(CatalogDraftImageStorage::class)->disk();
    }

    public function test_article_to_photo_review_import_and_duplicate_submission(): void
    {
        $p = $this->draft();
        $this->analyse($p);
        $this->get(route('admin.ai-import.show', $p))->assertOk()->assertSee('Article body read')->assertSee('TEST Cake')->assertSee('Confirmed photo');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/gemini-2.5-flash:generateContent')
            && $r['generationConfig']['responseJsonSchema']['properties']['market']['type'] === ['object', 'null']
            && $r['generationConfig']['responseJsonSchema']['properties']['stalls']['items']['properties']['foods']['items']['properties']['price_min']['type'] === ['number', 'null']);
        $this->patch(route('admin.ai-import.update', $p), $this->edit($p))->assertStatus(303)->assertSessionHasNoErrors();
        $p->refresh();
        $this->get(route('admin.ai-import.image', [$p, 0, 0]))->assertOk();
        $this->get(route('admin.ai-import.review', $p))->assertOk()->assertSee('Import Selected');
        $input = ['revision' => app(CatalogAiImportService::class)->revision($p), 'confirm' => 1];
        $response = $this->post(route('admin.ai-import.import', $p), $input);
        $response->assertStatus(303)->assertSessionHasNoErrors()->assertRedirect(route('admin.night-markets.show', $this->market));
        $food = Food::whereHas('stall', fn ($q) => $q->where('night_market_id', $this->market->id))->sole();
        $this->assertSame('inactive', $food->status);
        $this->assertSame('unknown', $food->stall->halal_status);
        $this->assertSame('8.00', $food->price_min);
        $this->assertSame($this->url, $food->source_url);
        Storage::disk('public')->assertExists($food->image_path);
        $this->post(route('admin.ai-import.import', $p), $input)->assertRedirect(route('admin.night-markets.show', $this->market));
        $this->assertSame(1, Food::where('stall_id', $food->stall_id)->count());
        $this->get(route('admin.ai-import.show', $p))->assertOk()->assertSee('Imported.');
        Http::assertSentCount(2);
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_incomplete_drafts_persist_but_selected_incomplete_food_cannot_import(): void
    {
        $p = $this->draft();
        $this->analyse($p);
        $input = $this->edit($p, false);
        $input['stalls'][0]['foods'][0]['price_min'] = null;
        $this->patch(route('admin.ai-import.update', $p), $input)->assertSessionHasNoErrors();
        $p->refresh();
        $this->get(route('admin.ai-import.review', $p))->assertOk()->assertSee('Confirmed photo')->assertSee('Valid price');
        $this->post(route('admin.ai-import.import', $p), ['revision' => app(CatalogAiImportService::class)->revision($p), 'confirm' => 1])->assertSessionHasErrors('draft');
        $this->assertSame('draft', $p->fresh()->status);
        $this->assertSame(0, $this->market->stalls()->count());
    }

    public function test_deselecting_incomplete_food_keeps_draft_and_imports_selected_stall(): void
    {
        $p = $this->draft();
        $this->analyse($p);
        $input = $this->edit($p, false);
        $input['stalls'][0]['foods'][0]['selected'] = 0;
        $this->patch(route('admin.ai-import.update', $p), $input)->assertSessionHasNoErrors();
        $p->refresh();
        $this->post(route('admin.ai-import.import', $p), ['revision' => app(CatalogAiImportService::class)->revision($p), 'confirm' => 1])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->market->stalls()->count());
        $this->assertSame(0, $this->market->stalls()->first()->foods()->count());
        $this->assertCount(1, app(CatalogAiImportService::class)->data($p->fresh())['graph']['stalls'][0]['foods']);
    }

    public function test_linking_existing_stall_preserves_fields_and_rejects_wrong_parent(): void
    {
        $existing = Stall::factory()->create(['night_market_id' => $this->market->id, 'name' => 'TEST Sweet Stall', 'description' => 'Keep this description']);
        $p = $this->draft();
        $this->analyse($p);
        $input = $this->edit($p);
        $input['stalls'][0]['matched_stall_id'] = $existing->id;
        $this->patch(route('admin.ai-import.update', $p), $input)->assertSessionHasNoErrors();
        $p->refresh();
        $this->post(route('admin.ai-import.import', $p), ['revision' => app(CatalogAiImportService::class)->revision($p), 'confirm' => 1])->assertSessionHasNoErrors();
        $this->assertSame('Keep this description', $existing->fresh()->description);
        $this->assertSame(1, $existing->foods()->count());
        $foreign = Stall::factory()->create();
        $this->post(route('admin.ai-import.start'), ['url' => $this->url.'/wrong', 'market_id' => $this->market->id, 'stall_id' => $foreign->id])->assertSessionHasErrors('market_id');
    }

    public function test_duplicate_stall_is_not_overwritten_and_edit_revision_is_checked(): void
    {
        $existing = Stall::factory()->create(['night_market_id' => $this->market->id, 'name' => 'TEST Sweet Stall']);
        $p = $this->draft();
        $this->analyse($p);
        $input = $this->edit($p);
        $this->patch(route('admin.ai-import.update', $p), $input)->assertSessionHasNoErrors();
        $this->patch(route('admin.ai-import.update', $p), $input)->assertSessionHasErrors('draft');
        $p->refresh();
        $this->post(route('admin.ai-import.import', $p), ['revision' => app(CatalogAiImportService::class)->revision($p), 'confirm' => 1])->assertSessionHasErrors();
        $this->assertSame(0, $existing->foods()->count());
        $this->assertSame('draft', $p->fresh()->status);
    }

    public function test_video_read_uses_actual_video_input_not_title_and_handles_unreadable(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => '00:18 TEST Sweet Stall menu visibly lists TEST Cake RM8 per slice.']]]]]])
            ->push(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => 'UNREADABLE']]]]]])]);
        $read = app(GeminiCatalogSourceService::class)->read('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
        $this->assertStringStartsWith('Video segment analysed', $read['mode']);
        $this->assertSame(['start' => 0, 'end' => 120], $read['video_range']);
        $this->assertSame([], $read['images']);
        Http::assertSent(fn ($r) => isset($r['contents'][0]['parts'][1]['file_data']['file_uri']));
        $this->expectException(ValidationException::class);
        app(GeminiCatalogSourceService::class)->read('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
    }

    public function test_repeat_analysis_keeps_edits_without_another_provider_call(): void
    {
        $p = $this->draft();
        $this->analyse($p);
        $input = $this->edit($p, false);
        $input['stalls'][0]['foods'][0]['name'] = 'Reviewed Cake';
        $this->patch(route('admin.ai-import.update', $p), $input)->assertSessionHasNoErrors();
        $this->post(route('admin.ai-import.analyse', $p), ['source_ids' => [0]])->assertSessionHasNoErrors();
        $this->assertSame('Reviewed Cake', app(CatalogAiImportService::class)->data($p->fresh())['graph']['stalls'][0]['foods'][0]['name']);
        Http::assertSentCount(2);
    }

    public function test_new_market_import_preserves_schedule_and_is_not_public(): void
    {
        $name = 'TEST New Market '.Str::random(8);
        $text = "$name, 15 Test Road, Petaling Jaya, Selangor. Saturday 18:00 to 23:00. TEST Sweet Stall sells TEST Cake Dessert RM8 per slice.";
        $payload = $this->payload();
        $payload['market'] = ['name' => $name, 'address' => '15 Test Road', 'city' => 'Petaling Jaya', 'state' => 'Selangor',
            'description' => null, 'evidence_text' => $text, 'confidence' => null,
            'operating_days' => [['day_of_week' => 'Saturday', 'opening_time' => '18:00', 'closing_time' => '23:00', 'evidence_text' => $text, 'confidence' => null]]];
        $payload['stalls'][0]['evidence_text'] = $text;
        $payload['stalls'][0]['foods'][0]['evidence_text'] = $text;
        Http::fake([$this->url => Http::response('<article>'.$text.'</article>', 200, ['Content-Type' => 'text/html']),
            'generativelanguage.googleapis.com/*' => Http::response($this->response($payload))]);
        $this->actingAs($this->admin)->post(route('admin.ai-import.start'), ['name' => $name, 'city' => 'Petaling Jaya', 'url' => $this->url])->assertSessionHasNoErrors();
        $p = CatalogImportProposal::where('created_by', $this->admin->id)->sole();
        $this->post(route('admin.ai-import.analyse', $p), ['source_ids' => [0]])->assertSessionHasNoErrors();
        $p->refresh();
        $this->get(route('admin.ai-import.show', $p))->assertOk()->assertSee('Operating days');
        $this->patch(route('admin.ai-import.update', $p), $this->edit($p))->assertSessionHasNoErrors();
        $p->refresh();
        $this->post(route('admin.ai-import.import', $p), ['revision' => app(CatalogAiImportService::class)->revision($p), 'confirm' => 1])->assertSessionHasNoErrors();
        $market = NightMarket::where('name', $name)->sole();
        $this->assertSame('inactive', $market->status);
        $this->assertSame('Saturday', $market->operatingDays->sole()->day_of_week);
        $this->assertSame('18:00', $market->operatingDays->sole()->opening_time->format('H:i'));
        $this->assertSame(1, $market->stalls->sole()->foods()->count());
    }

    public function test_candidate_image_requires_actual_source_membership_and_can_be_removed(): void
    {
        $p = $this->draft();
        $photo = UploadedFile::fake()->image('candidate.png')->get();
        $url = 'https://catalog.example.test/cake.png';
        Http::fake([$this->url => Http::response('<article>'.$this->text.'<img src="'.$url.'" alt="TEST Cake"></article>', 200, ['Content-Type' => 'text/html']),
            $url => Http::response($photo, 200, ['Content-Type' => 'image/png']),
            'generativelanguage.googleapis.com/*' => Http::response($this->response($this->payload()))]);
        $this->post(route('admin.ai-import.analyse', $p), ['source_ids' => [0]])->assertSessionHasNoErrors();
        $p->refresh();
        $this->get(route('admin.ai-import.candidate-image', [$p, 0, 0]))->assertOk()->assertHeader('Content-Type', 'image/png');
        $input = $this->edit($p, false);
        $input['stalls'][0]['foods'][0]['candidate_image'] = 'https://unlisted.example.test/fake.png';
        $input['stalls'][0]['foods'][0]['photo_confirmed'] = 1;
        $this->patch(route('admin.ai-import.update', $p), $input)->assertSessionHasErrors('image');
        $input['stalls'][0]['foods'][0]['candidate_image'] = $url;
        $this->patch(route('admin.ai-import.update', $p), $input)->assertSessionHasNoErrors();
        $p->refresh();
        $path = app(CatalogAiImportService::class)->data($p)['graph']['stalls'][0]['foods'][0]['image_path'];
        Storage::disk('catalog_drafts')->assertExists($path);
        $input = $this->edit($p, false);
        $input['stalls'][0]['foods'][0]['remove_image'] = 1;
        $this->patch(route('admin.ai-import.update', $p), $input)->assertSessionHasNoErrors();
        $this->get(route('admin.ai-import.image', [$p, 0, 0]))->assertNotFound();
        $this->assertArrayNotHasKey('image_path', app(CatalogAiImportService::class)->data($p->fresh())['graph']['stalls'][0]['foods'][0]);
    }

    public function test_unselected_sources_and_legacy_mutation_cannot_trigger_new_draft_analysis(): void
    {
        $p = $this->draft();
        $this->post(route('admin.ai-import.analyse', $p), [])->assertSessionHasErrors('source');
        $this->post(route('admin.social-media.automation.proposals.submit', $p))->assertSessionHasErrors('proposal');
        $this->assertNotNull(app(CatalogAiImportService::class)->data($p->fresh()));
        Http::assertNothingSent();
    }

    public function test_url_context_needs_success_for_the_selected_url_and_failure_has_no_retry(): void
    {
        Http::fake([$this->url => Http::response('', 403), 'generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => $this->text]]],
                'urlContextMetadata' => ['urlMetadata' => [['retrievedUrl' => $this->url, 'urlRetrievalStatus' => 'URL_RETRIEVAL_STATUS_SUCCESS']]]]]])
            ->push(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => $this->text]]],
                'urlContextMetadata' => ['urlMetadata' => [['retrievedUrl' => 'https://unrelated.example.test/', 'urlRetrievalStatus' => 'URL_RETRIEVAL_STATUS_SUCCESS']]]]]])]);
        $read = app(GeminiCatalogSourceService::class)->read($this->url);
        $this->assertSame('Article read with URL Context', $read['mode']);
        try {
            app(GeminiCatalogSourceService::class)->read($this->url);
            $this->fail('Unrelated URL must not count as read');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('retrieve the article body', $e->validator->errors()->first());
        }
        Http::assertSentCount(4);
    }

    public function test_private_network_image_is_rejected_before_http(): void
    {
        $this->app->instance(HostnameResolver::class, new class implements HostnameResolver
        {
            public function resolve(string $hostname): array
            {
                return ['127.0.0.1'];
            }
        });
        try {
            app(CatalogSourceReader::class)->fetch('https://private.example.test/photo.png', true);
            $this->fail('Private destination accepted');
        } catch (ValidationException) {
            Http::assertNothingSent();
        }
    }

    public function test_multiple_sources_link_to_their_own_food_and_do_not_overwrite_existing_stall(): void
    {
        $stall = Stall::factory()->create(['night_market_id' => $this->market->id, 'name' => 'TEST Sweet Stall']);
        $p = $this->draft();
        $otherUrl = $this->url.'/second';
        $otherText = str_replace('TEST Cake', 'TEST Pudding', $this->text);
        $other = $this->payload();
        $other['stalls'][0]['evidence_text'] = $otherText;
        $other['stalls'][0]['foods'][0]['name'] = 'TEST Pudding';
        $other['stalls'][0]['foods'][0]['evidence_text'] = $otherText;
        Http::fake([$this->url => Http::response('<article>'.$this->text.'</article>', 200, ['Content-Type' => 'text/html']),
            $otherUrl => Http::response('<article>'.$otherText.'</article>', 200, ['Content-Type' => 'text/html']),
            'generativelanguage.googleapis.com/*' => Http::sequence()->push($this->response($this->payload()))->push($this->response($other))]);
        $this->post(route('admin.ai-import.analyse', $p), ['source_ids' => [0]])->assertSessionHasNoErrors();
        $this->post(route('admin.ai-import.analyse', $p), ['url' => $otherUrl])->assertSessionHasNoErrors();
        $p->refresh();
        $edit = $this->edit($p);
        $edit['stalls'][0]['matched_stall_id'] = $stall->id;
        $edit['stalls'][1] = $edit['stalls'][0];
        $edit['stalls'][1]['foods'][0]['name'] = 'TEST Pudding';
        $edit['stalls'][1]['foods'][0]['image'] = UploadedFile::fake()->image('pudding.jpg');
        $this->patch(route('admin.ai-import.update', $p), $edit)->assertSessionHasNoErrors();
        $p->refresh();
        $this->post(route('admin.ai-import.import', $p), ['revision' => app(CatalogAiImportService::class)->revision($p), 'confirm' => 1])->assertSessionHasNoErrors();
        $this->assertSame(2, $stall->foods()->count());
        foreach (['TEST Cake' => $this->url, 'TEST Pudding' => $otherUrl] as $name => $url) {
            $food = $stall->foods()->where('name', $name)->sole();
            $links = CatalogSocialMediaSourceLink::where('food_id', $food->id)->with('socialMediaSource')->get();
            $this->assertCount(1, $links);
            $this->assertSame($url, $links->sole()->socialMediaSource->canonical_url);
        }
        Http::assertSentCount(4);
    }

    public function test_video_preview_metadata_is_separate_from_video_evidence(): void
    {
        config(['services.youtube.data_api_key' => 'fake-not-a-real-key']);
        $url = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
        Http::fake(['www.googleapis.com/youtube/v3/*' => Http::response(['items' => [['id' => 'dQw4w9WgXcQ', 'snippet' => [
            'title' => 'TEST Source Video', 'description' => 'Metadata only', 'channelTitle' => 'TEST Channel', 'publishedAt' => '2026-01-01T00:00:00Z',
            'thumbnails' => ['default' => ['url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/default.jpg']]]]]]),
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => '00:18 '.$this->text]]]]]])
                ->push($this->response($this->payload()))]);
        $this->actingAs($this->admin)->post(route('admin.ai-import.start'), ['market_id' => $this->market->id, 'url' => $url])->assertSessionHasNoErrors();
        $p = CatalogImportProposal::where('created_by', $this->admin->id)->sole();
        $this->post(route('admin.ai-import.analyse', $p), ['source_ids' => [0]])->assertSessionHasNoErrors();
        $data = app(CatalogAiImportService::class)->data($p->fresh());
        $this->assertSame('TEST Channel', $data['sources'][0]['publisher']);
        $this->assertStringStartsWith('Video segment analysed', $data['sources'][0]['status']);
        $this->assertSame([], $data['sources'][0]['images']);
        $this->assertArrayNotHasKey('image_path', $data['graph']['stalls'][0]['foods'][0]);
        Http::assertSentCount(3);
    }

    public function test_search_selection_analysis_image_edits_and_review_form_a_complete_isolated_flow(): void
    {
        config(['services.catalog_ai.model' => 'gemini-3.5-flash-lite', 'services.catalog_search.tavily_key' => 'fake-search',
            'services.catalog_search.tavily_free_confirmed' => true, 'services.youtube.data_api_key' => 'fake-video']);
        Http::fake(['api.tavily.com/search' => Http::response(['results' => [['url' => $this->url, 'title' => 'TEST selected article']]]),
            'www.googleapis.com/youtube/v3/search*' => Http::response(['items' => [['id' => ['videoId' => 'TESTvideo01'], 'snippet' => ['title' => 'TEST unselected video']]]]),
            $this->url => Http::response('<article>'.$this->text.'</article>', 200, ['Content-Type' => 'text/html']),
            'generativelanguage.googleapis.com/*' => Http::response($this->response($this->payload()))]);
        $counts = [NightMarket::count(), Stall::count(), Food::count()];
        $response = $this->actingAs($this->admin)->post(route('admin.ai-import.search'), ['market_id' => $this->market->id]);
        $response->assertStatus(303)->assertSessionHasNoErrors();
        $location = $response->headers->get('Location');
        $this->get($location)->assertOk()->assertSee('TEST selected article')->assertSee('TEST unselected video')->assertSee('Source type');
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->post(route('admin.ai-import.start'), ['search_id' => $query['search_id'], 'source_ids' => [0]])->assertStatus(303);
        $p = CatalogImportProposal::where('created_by', $this->admin->id)->sole();
        $this->post(route('admin.ai-import.analyse', $p), ['source_ids' => [0]])->assertStatus(303)->assertSessionHasNoErrors();
        $this->patch(route('admin.ai-import.update', $p), $this->edit($p->refresh()))->assertStatus(303)->assertSessionHasNoErrors();
        $first = app(CatalogAiImportService::class)->data($p->refresh())['graph']['stalls'][0]['foods'][0]['image_path'];
        $this->get(route('admin.ai-import.image', [$p, 0, 0]))->assertOk();
        $this->patch(route('admin.ai-import.update', $p), $this->edit($p))->assertSessionHasNoErrors();
        Storage::disk('catalog_drafts')->assertMissing($first);
        $remove = $this->edit($p->refresh(), false);
        $remove['stalls'][0]['foods'][0]['remove_image'] = 1;
        $this->patch(route('admin.ai-import.update', $p), $remove)->assertSessionHasNoErrors();
        $this->get(route('admin.ai-import.review', $p))->assertOk()->assertSee('Confirmed photo');
        $this->patch(route('admin.ai-import.update', $p), $this->edit($p->refresh()))->assertSessionHasNoErrors();
        $this->get(route('admin.ai-import.review', $p))->assertOk()->assertSee('TEST Cake')->assertSee('Import Selected');
        $this->get($location)->assertOk();
        $this->assertSame($counts, [NightMarket::count(), Stall::count(), Food::count()]);
        Http::assertSentCount(4);
    }

    public function test_video_segment_range_is_validated_cached_and_only_reanalysed_on_explicit_change(): void
    {
        $url = 'https://www.youtube.com/watch?v=TESTvideo01';
        $this->actingAs($this->admin)->post(route('admin.ai-import.start'), ['market_id' => $this->market->id, 'url' => $url])->assertSessionHasNoErrors();
        $p = CatalogImportProposal::where('created_by', $this->admin->id)->sole();
        $this->post(route('admin.ai-import.analyse', $p), ['source_ids' => [0], 'video_start_seconds' => 0, 'video_end_seconds' => 181])->assertSessionHasErrors('video_end_seconds');
        Http::assertNothingSent();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => '00:18 '.$this->text]]]]]])->push($this->response($this->payload()))
            ->push(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => '02:18 '.$this->text]]]]]])->push($this->response($this->payload()))]);
        $input = ['source_ids' => [0], 'video_start_seconds' => 0, 'video_end_seconds' => 120];
        $this->post(route('admin.ai-import.analyse', $p), $input)->assertStatus(303)->assertSessionHasNoErrors();
        $this->post(route('admin.ai-import.analyse', $p), $input)->assertStatus(303)->assertSessionHasNoErrors();
        Http::assertSentCount(2);
        $input['video_start_seconds'] = 120;
        $input['video_end_seconds'] = 240;
        $this->post(route('admin.ai-import.analyse', $p), $input)->assertStatus(429);
        Http::assertSentCount(2);
        $this->travel(61)->seconds();
        $this->post(route('admin.ai-import.analyse', $p), $input)->assertStatus(303)->assertSessionHasNoErrors();
        $this->travelBack();
        Http::assertSentCount(4);
        $data = app(CatalogAiImportService::class)->data($p->refresh());
        $this->assertSame(['start' => 120, 'end' => 240], $data['sources'][0]['video_range']);
        $this->assertCount(2, $data['graph']['stalls']);
        $this->assertSame([], $data['sources'][0]['images']);
        $this->get(route('admin.ai-import.show', $p))->assertOk()->assertSee('not the full video');
    }

    public function test_mixed_video_batch_and_unnamed_stall_identity_remain_unconfirmed(): void
    {
        $p = $this->draft();
        $this->post(route('admin.ai-import.analyse', $p), ['source_ids' => [0], 'url' => 'https://www.youtube.com/watch?v=TESTvideo01'])->assertSessionHasErrors('source');
        Http::assertNothingSent();
        $payload = $this->payload();
        $payload['stalls'][0]['name'] = 'Unnamed food stall';
        $text = 'Unnamed food stall. '.$this->text;
        $payload['stalls'][0]['evidence_text'] = $text;
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->response($payload))]);
        $this->post(route('admin.ai-import.analyse', $p), ['source_ids' => [0], 'text' => $text])->assertSessionHasNoErrors();
        $data = app(CatalogAiImportService::class)->data($p->refresh());
        $this->assertSame('', $data['graph']['stalls'][0]['name']);
        $this->assertFalse($data['graph']['stalls'][0]['parent_confirmed']);
    }

    public function test_reopening_a_legacy_draft_does_not_replace_its_data(): void
    {
        $p = app(CatalogImportProposalService::class)->createDraft($this->admin, [
            'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'target_type' => 'existing_market', 'matched_night_market_id' => $this->market->id,
        ]);
        $this->actingAs($this->admin)->post(route('admin.ai-import.start'), [
            'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'market_id' => $this->market->id,
        ])->assertRedirect(route('admin.ai-import.show', $p));
        $this->assertNull(app(CatalogAiImportService::class)->data($p->fresh()));
        $this->assertSame(1, CatalogImportProposal::where('created_by', $this->admin->id)->count());
        $this->get(route('admin.ai-import.show', $p))->assertOk()->assertSee('Catalog Import Draft');
        Http::assertNothingSent();
    }
}
