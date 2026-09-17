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

    public function test_review_form_creates_only_a_new_inactive_market_after_saving_edited_schedule(): void
    {
        $name = 'TEST Review Market '.Str::random(8);
        $this->actingAs($this->admin)->post(route('admin.ai-import.start'), [
            'import_mode' => 'new_market', 'name' => $name, 'city' => 'Shah Alam', 'url' => $this->url,
        ])->assertSessionHasNoErrors();
        $proposal = CatalogImportProposal::where('created_by', $this->admin->id)->sole();
        $input = [
            'revision' => app(CatalogAiImportService::class)->revision($proposal->fresh()),
            'action' => 'import', 'confirm' => 1,
            'market' => ['selected' => 1, 'name' => $name, 'address' => '15 Test Road, Shah Alam', 'city' => 'Shah Alam', 'matched_night_market_id' => ''],
            'operating_days' => [['selected' => 1, 'day_of_week' => 'Saturday', 'opening_time' => '16:30', 'closing_time' => '22:00', 'evidence_text' => 'Admin reviewed source text.']],
        ];
        $this->get(route('admin.ai-import.review', $proposal))->assertOk();
        $this->post(route('admin.ai-import.complete', $proposal), $input)
            ->assertRedirect(route('admin.ai-import.success', $proposal))->assertSessionHasNoErrors();
        $receipt = app(CatalogAiImportService::class)->data($proposal->fresh())['import_result'];
        $this->assertSame(['markets' => 1, 'stalls' => 0, 'foods' => 0, 'linked' => 0], $receipt['counts']);
        $market = NightMarket::findOrFail($receipt['market_id']);
        $this->assertSame('inactive', $market->status);
        $this->assertSame('Saturday', $market->operatingDays()->sole()->day_of_week);
        $this->post(route('admin.ai-import.complete', $proposal), $input)
            ->assertRedirect(route('admin.ai-import.success', $proposal));
        $this->assertSame(1, NightMarket::where('name', $name)->count());
        Http::assertNothingSent();
    }

    public function test_article_batch_continues_after_first_source_fails_and_keeps_individual_statuses(): void
    {
        $name = 'TEST Batch Market '.Str::random(8);
        $urls = ['https://article.example.test/failed', 'https://article.example.test/second', 'https://article.example.test/third'];
        config(['services.catalog_search.tavily_key' => 'fake-search', 'services.catalog_search.tavily_free_confirmed' => true]);
        Http::fake([
            'api.tavily.com/search' => Http::response(['results' => collect($urls)->map(fn ($url, $i) => ['url' => $url, 'title' => $name.' source '.($i + 1)])->all()]),
            $urls[0] => Http::response('Unavailable', 403),
            $urls[1] => Http::response('<article>'.$name.' in Shah Alam. First supported source.</article>', 200, ['Content-Type' => 'text/html']),
            $urls[2] => Http::response('<article>'.$name.' in Shah Alam. Second supported source.</article>', 200, ['Content-Type' => 'text/html']),
            'generativelanguage.googleapis.com/*' => Http::response($this->response(['market' => null, 'stalls' => [], 'warnings' => [], 'insufficient_data' => false])),
        ]);
        $search = $this->actingAs($this->admin)->post(route('admin.ai-import.search'), [
            'import_mode' => 'new_market', 'name' => $name, 'city' => 'Shah Alam', 'search_kind' => 'articles',
        ]);
        parse_str(parse_url($search->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->post(route('admin.ai-import.prepare'), ['search_id' => $query['search_id'], 'source_ids' => [0, 1, 2]])
            ->assertStatus(303)->assertSessionHasErrors('source');
        $proposal = CatalogImportProposal::where('created_by', $this->admin->id)->sole();
        $sources = app(CatalogAiImportService::class)->data($proposal->fresh())['sources'];
        $this->assertStringStartsWith('Analysis unavailable', $sources[0]['status']);
        $this->assertSame('Article body read', $sources[1]['status']);
        $this->assertSame('Article body read', $sources[2]['status']);
        foreach ($urls as $url) {
            Http::assertSent(fn ($request) => $request->url() === $url);
        }
        Http::assertSentCount(7);
    }

    public function test_twelve_hour_source_schedule_is_retained_when_market_identity_is_elsewhere_in_article(): void
    {
        $name = 'TEST Schedule Market '.Str::random(8);
        $text = $name.' is in Shah Alam, Selangor. It operates every Saturday from 4:30 PM to 10:00 PM.';
        $payload = ['market' => ['name' => $name, 'address' => null, 'city' => 'Shah Alam', 'state' => 'Selangor',
            'description' => null, 'evidence_text' => $name.' is in Shah Alam, Selangor.', 'confidence' => null,
            'operating_days' => [['day_of_week' => 'Saturday', 'opening_time' => '16:30', 'closing_time' => '22:00',
                'evidence_text' => 'It operates every Saturday from 4:30 PM to 10:00 PM.', 'confidence' => null]]],
            'stalls' => [], 'warnings' => [], 'insufficient_data' => false];
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->response($payload))]);
        $this->actingAs($this->admin)->post(route('admin.ai-import.start'), [
            'import_mode' => 'new_market', 'name' => $name, 'city' => 'Shah Alam', 'url' => $this->url,
        ])->assertSessionHasNoErrors();
        $proposal = CatalogImportProposal::where('created_by', $this->admin->id)->sole();
        $this->post(route('admin.ai-import.analyse', $proposal), ['source_ids' => [0], 'text' => $text])
            ->assertSessionHasNoErrors();
        $day = app(CatalogAiImportService::class)->data($proposal->fresh())['graph']['operating_days'][0];
        $this->assertSame(['Saturday', '16:30', '22:00'], [$day['day_of_week'], $day['opening_time'], $day['closing_time']]);
    }

    public function test_malay_day_without_times_is_retained_as_time_unknown(): void
    {
        $name = 'TEST Khamis Market '.Str::random(8);
        $text = $name.' in Rawang, Selangor. '.$name.' beroperasi setiap Khamis.';
        $payload = ['market' => ['name' => $name, 'address' => null, 'city' => 'Rawang', 'state' => 'Selangor',
            'description' => null, 'evidence_text' => $name.' in Rawang, Selangor.', 'confidence' => null,
            'operating_days' => [['day_of_week' => 'Khamis', 'opening_time' => null, 'closing_time' => null,
                'evidence_text' => $name.' beroperasi setiap Khamis.', 'confidence' => null]]],
            'stalls' => [], 'warnings' => [], 'insufficient_data' => false];
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->response($payload))]);
        $this->actingAs($this->admin)->post(route('admin.ai-import.start'), [
            'import_mode' => 'new_market', 'name' => $name, 'city' => 'Rawang', 'url' => $this->url,
        ])->assertSessionHasNoErrors();
        $proposal = CatalogImportProposal::where('created_by', $this->admin->id)->sole();
        $this->post(route('admin.ai-import.analyse', $proposal), ['source_ids' => [0], 'text' => $text])->assertSessionHasNoErrors();
        $day = app(CatalogAiImportService::class)->data($proposal->fresh())['graph']['operating_days'][0];
        $this->assertSame('Thursday', $day['day_of_week']);
        $this->assertNull($day['opening_time']);
        $this->assertNull($day['closing_time']);
    }

    public function test_conflicting_source_schedules_are_flagged_for_admin_review(): void
    {
        $name = 'TEST Conflict Market '.Str::random(8);
        $first = $name.' in Shah Alam, Selangor opens Monday 17:00 to 23:00.';
        $second = $name.' in Shah Alam, Selangor opens Saturday 16:30 to 22:00.';
        $payload = fn (string $text, string $day, string $open, string $close) => ['market' => [
            'name' => $name, 'address' => null, 'city' => 'Shah Alam', 'state' => 'Selangor', 'description' => null,
            'evidence_text' => $text, 'confidence' => null, 'operating_days' => [['day_of_week' => $day,
                'opening_time' => $open, 'closing_time' => $close, 'evidence_text' => $text, 'confidence' => null]],
        ], 'stalls' => [], 'warnings' => [], 'insufficient_data' => false];
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push($this->response($payload($first, 'Monday', '17:00', '23:00')))
            ->push($this->response($payload($second, 'Saturday', '16:30', '22:00')))]);
        $this->actingAs($this->admin)->post(route('admin.ai-import.start'), [
            'import_mode' => 'new_market', 'name' => $name, 'city' => 'Shah Alam', 'url' => $this->url,
        ])->assertSessionHasNoErrors();
        $proposal = CatalogImportProposal::where('created_by', $this->admin->id)->sole();
        $this->post(route('admin.ai-import.analyse', $proposal), ['source_ids' => [0], 'text' => $first])->assertSessionHasNoErrors();
        $this->post(route('admin.ai-import.analyse', $proposal), [
            'url' => 'https://second.example.test/conflict', 'text' => $second,
        ])->assertSessionHasNoErrors();
        $this->assertArrayHasKey('schedule', app(CatalogAiImportService::class)->data($proposal->fresh())['market_conflicts']);
        $this->get(route('admin.ai-import.review', $proposal))->assertOk()->assertSee('Source conflict:');
    }

    public function test_history_marks_duplicate_drafts_for_the_same_market_on_the_current_page(): void
    {
        $name = 'TEST Duplicate Draft '.Str::random(8);
        $this->actingAs($this->admin);
        foreach (['one', 'two'] as $suffix) {
            $this->post(route('admin.ai-import.start'), [
                'import_mode' => 'new_market', 'name' => $name, 'city' => 'Shah Alam',
                'url' => 'https://'.$suffix.'.example.test/'.Str::uuid(), 'start_separate_draft' => 1,
            ])->assertSessionHasNoErrors();
        }
        $this->get(route('admin.ai-import.history', ['status' => 'attention']))->assertOk()
            ->assertSee('2 unfinished drafts appear to reference this Market.');
        Http::assertNothingSent();
    }

    public function test_inactive_market_and_stall_are_selectable_and_preserved_as_exact_targets(): void
    {
        $market = NightMarket::factory()->inactive()->create(['state' => 'Selangor']);
        $stall = Stall::factory()->inactive()->create(['night_market_id' => $market->id]);

        $this->actingAs($this->admin)->get(route('admin.ai-import.index', [
            'module' => 'foods', 'market_id' => $market->id, 'stall_id' => $stall->id,
        ]))->assertOk()->assertSee($market->name)->assertSee($stall->name)
            ->assertSee('value="existing_market" required checked', false)
            ->assertSee('Inactive');

        $this->post(route('admin.ai-import.start'), [
            'module' => 'foods', 'market_id' => $market->id, 'stall_id' => $stall->id, 'url' => $this->url,
        ])->assertSessionHasNoErrors();
        $proposal = CatalogImportProposal::where('created_by', $this->admin->id)->latest('id')->firstOrFail();
        $data = app(CatalogAiImportService::class)->data($proposal);
        $this->assertSame($market->id, $data['context']['market_id']);
        $this->assertSame($stall->id, $data['context']['stall_id']);
    }

    public function test_malformed_child_is_isolated_and_corrupt_snapshot_has_recovery_page(): void
    {
        $proposal = $this->draft();
        $data = app(CatalogAiImportService::class)->data($proposal);
        $data['sources'][0]['url'] = ['invalid'];
        $data['sources'][0]['images'] = [['url' => ['invalid']]];
        $data['graph']['stalls'] = [['name' => 'Valid Stall', 'foods' => [null]]];
        $proposal->forceFill(['review_metadata_snapshot' => ['ai_import' => $data]])->save();

        $this->get(route('admin.ai-import.show', $proposal))->assertOk()->assertSee('Needs Repair')
            ->assertSee('Valid Stall')->assertSee('Source URL needs repair');
        $this->post(route('admin.ai-import.remove-invalid', $proposal), [
            'repair_item' => 'food:0:0', 'revision' => app(CatalogAiImportService::class)->revision($proposal),
        ])
            ->assertRedirect(route('admin.ai-import.show', $proposal));
        $this->assertSame([], app(CatalogAiImportService::class)->data($proposal->fresh())['graph']['stalls'][0]['foods']);

        $proposal->forceFill(['review_metadata_snapshot' => ['ai_import' => 'invalid internal structure']])->save();
        $this->get(route('admin.ai-import.show', $proposal))->assertStatus(422)->assertSee('Repair Draft #'.$proposal->id);
        $this->post(route('admin.ai-import.reset-extracted', $proposal))->assertRedirect(route('admin.ai-import.show', $proposal));
        $this->get(route('admin.ai-import.show', $proposal))->assertOk();
    }

    public function test_last_good_version_opens_when_current_snapshot_is_unreadable_and_can_be_restored(): void
    {
        $proposal = $this->draft();
        $this->actingAs($this->admin)->patch(route('admin.ai-import.rename', $proposal), [
            'draft_name' => 'TEST last good draft',
        ])->assertSessionHasNoErrors();

        $proposal->refresh();
        $snapshot = $proposal->review_metadata_snapshot;
        $this->assertIsArray($snapshot['ai_import_last_good']);
        $snapshot['ai_import'] = 'invalid saved structure';
        $proposal->forceFill(['review_metadata_snapshot' => $snapshot])->save();

        $this->get(route('admin.ai-import.show', $proposal))->assertOk()->assertSee($this->market->name)
            ->assertSee('showing the last good version')->assertSee('Restore this last good version');
        $this->post(route('admin.ai-import.restore', $proposal))->assertRedirect(route('admin.ai-import.show', $proposal));
        $this->assertIsArray($proposal->fresh()->review_metadata_snapshot['ai_import']);
    }

    public function test_pdf_analysis_does_not_require_url_context_metadata_after_the_document_is_fetched(): void
    {
        $pdfUrl = 'https://catalog.example.test/source.pdf';
        Http::fake([
            $pdfUrl => Http::response('%PDF-1.7 fake test document', 200, ['Content-Type' => 'application/pdf']),
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [[
                'finishReason' => 'STOP',
                'content' => ['parts' => [['text' => 'This PDF identifies a TEST market and its Thursday operating day with source evidence.']]],
            ]]]),
        ]);

        $result = app(GeminiCatalogSourceService::class)->read($pdfUrl);

        $this->assertSame('PDF text extracted and analysed', $result['mode']);
        $this->assertStringContainsString('Thursday', $result['text']);
        Http::assertSentCount(2);
    }

    public function test_source_owned_by_another_draft_requires_explicit_copy(): void
    {
        $this->actingAs($this->admin)->post(route('admin.ai-import.start'), [
            'import_mode' => 'new_market', 'name' => 'TEST First '.Str::random(6), 'city' => 'Shah Alam', 'url' => $this->url,
        ])->assertSessionHasNoErrors();
        $first = CatalogImportProposal::where('created_by', $this->admin->id)->sole();
        config(['services.catalog_search.tavily_key' => 'fake-search', 'services.catalog_search.tavily_free_confirmed' => true]);
        Http::fake(['api.tavily.com/search' => Http::response(['results' => [[
            'url' => $this->url, 'title' => 'TEST Second Market Shah Alam source',
            'content' => 'TEST Second Market in Shah Alam, Selangor.',
        ]]])]);
        $search = $this->post(route('admin.ai-import.search'), [
            'import_mode' => 'new_market', 'name' => 'TEST Second Market', 'city' => 'Shah Alam', 'search_kind' => 'articles',
        ]);
        parse_str(parse_url($search->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->post(route('admin.ai-import.start'), ['search_id' => $query['search_id'], 'source_ids' => [0]])
            ->assertSessionHasErrors('source');
        $this->assertSame(1, CatalogImportProposal::where('created_by', $this->admin->id)->count());

        $this->post(route('admin.ai-import.start'), [
            'search_id' => $query['search_id'], 'source_ids' => [0], 'copy_source_ids' => [0], 'start_separate_draft' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertSame(2, CatalogImportProposal::where('created_by', $this->admin->id)->count());
        $this->assertDatabaseHas('catalog_import_proposals', ['id' => $first->id, 'status' => 'draft']);
    }

    public function test_explicit_import_mode_and_exact_existing_target_are_required_before_search_or_prepare(): void
    {
        $count = CatalogImportProposal::count();
        $this->actingAs($this->admin)->post(route('admin.ai-import.search'), ['name' => 'TEST Market', 'city' => 'Petaling Jaya'])
            ->assertSessionHasErrors('import_mode');
        $this->post(route('admin.ai-import.prepare'), ['import_mode' => 'existing_market', 'url' => $this->url])
            ->assertSessionHasErrors('market_id');
        $this->post(route('admin.ai-import.prepare'), ['import_mode' => 'new_market', 'market_id' => $this->market->id, 'url' => $this->url])
            ->assertSessionHasErrors('market_id');
        $this->travel(61)->seconds();
        $this->post(route('admin.ai-import.prepare'), ['import_mode' => 'new_market', 'name' => 'TEST Market', 'city' => 'Petaling Jaya'])
            ->assertSessionHasErrors('source');
        $this->assertSame($count, CatalogImportProposal::count());
        Http::assertNothingSent();
    }

    public function test_single_video_is_selected_search_creates_no_draft_and_prepare_preserves_new_market_without_ai_market_payload(): void
    {
        config(['services.youtube.data_api_key' => 'fake-video']);
        $name = 'TEST Intended Market '.Str::random(8);
        $video = Str::random(11);
        $this->text = str_replace($this->market->name, $name, $this->text);
        Http::fake(['www.googleapis.com/youtube/v3/search*' => Http::response(['items' => [['id' => ['videoId' => $video], 'snippet' => ['title' => 'TEST video evidence']]]]),
            'generativelanguage.googleapis.com/*' => Http::sequence()->push(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => '00:10 '.$this->text]]]]]])->push($this->response($this->payload()))]);
        $count = CatalogImportProposal::count();
        $response = $this->actingAs($this->admin)->post(route('admin.ai-import.search'), ['import_mode' => 'new_market', 'name' => $name, 'city' => 'Petaling Jaya', 'search_kind' => 'videos'])->assertStatus(303);
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('Analyse &amp; Prepare Import', false)
            ->assertSee('value="0" checked', false)->assertSee('Selected')->assertSee($name);
        $this->get($response->headers->get('Location'))->assertOk();
        $this->assertSame($count, CatalogImportProposal::count());
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        config(['services.youtube.data_api_key' => null]); // Preview metadata is unnecessary for this content test.
        $input = ['search_id' => $query['search_id'], 'source_ids' => [0]];
        $response = $this->post(route('admin.ai-import.prepare'), $input)->assertStatus(303)->assertSessionHasNoErrors();
        $p = CatalogImportProposal::where('created_by', $this->admin->id)->latest('id')->firstOrFail();
        $response->assertRedirect(route('admin.ai-import.review', $p));
        $data = app(CatalogAiImportService::class)->data($p);
        $this->assertSame($name, $data['graph']['market']['name']);
        $this->assertSame('Petaling Jaya', $data['graph']['market']['city']);
        $this->assertEmpty($data['graph']['market']['address'] ?? null);
        $this->assertSame([], $data['graph']['operating_days']);
        $this->assertTrue($data['graph']['stalls'][0]['selected']);
        $this->assertTrue($data['graph']['stalls'][0]['foods'][0]['selected']);
        $this->assertFalse($data['graph']['stalls'][0]['parent_confirmed']);
        $this->get(route('admin.ai-import.review', $p))->assertOk()->assertSee('Create Selected Catalog Records')
            ->assertSee('name="stalls[0][foods][0][price_min]"', false)->assertSee('Save and continue later');
        $this->post(route('admin.ai-import.prepare'), $input)->assertRedirect(route('admin.ai-import.review', $p))->assertSessionHasNoErrors();
        $this->assertSame($count + 1, CatalogImportProposal::count());
        Http::assertSentCount(3); // One search, one video read, one structured extraction. No GET/repeat calls.
        $edit = [...$this->edit($p->refresh()), 'action' => 'import', 'confirm' => 1];
        $this->post(route('admin.ai-import.complete', $p), $edit)->assertRedirect(route('admin.ai-import.success', $p))->assertSessionHasNoErrors();
        $receipt = app(CatalogAiImportService::class)->data($p->refresh())['import_result'];
        $this->assertSame(['markets' => 1, 'stalls' => 1, 'foods' => 1, 'linked' => 0], $receipt['counts']);
        $market = NightMarket::findOrFail($receipt['market_id']);
        $this->assertSame($name, $market->name);
        $this->assertSame('inactive', $market->status);
        $this->assertFalse($market->operatingDays()->exists());
        $stall = $market->stalls()->firstOrFail();
        $food = $stall->foods()->firstOrFail();
        $this->assertSame('inactive', $stall->status);
        $this->assertSame('inactive', $food->status);
        Storage::disk('public')->assertExists($food->image_path);
        $this->post(route('admin.ai-import.complete', $p), $edit)->assertRedirect(route('admin.ai-import.success', $p));
        $this->assertSame(1, NightMarket::where('name', $name)->count());
        $this->assertSame(1, $stall->foods()->count());
        $this->get(route('admin.ai-import.success', $p))->assertOk()->assertSee('Created 1 Night Markets, 1 Stalls and 1 Foods.');
        Http::assertSentCount(3);
    }

    public function test_prepare_existing_market_review_edits_import_in_one_step_with_exact_skipped_summary(): void
    {
        $payload = $this->payload();
        $payload['stalls'][0]['foods'][] = [...$payload['stalls'][0]['foods'][0], 'name' => 'TEST Skip Cake'];
        $text = $this->text.' TEST Skip Cake is also sold by TEST Sweet Stall.';
        $payload['stalls'][0]['foods'][1]['evidence_text'] = $text;
        Http::fake([$this->url => Http::response('<article>'.$text.'</article>', 200, ['Content-Type' => 'text/html']),
            'generativelanguage.googleapis.com/*' => Http::response($this->response($payload))]);
        $this->actingAs($this->admin)->post(route('admin.ai-import.prepare'), ['import_mode' => 'existing_market', 'market_id' => $this->market->id, 'url' => $this->url])
            ->assertStatus(303)->assertSessionHasNoErrors();
        $p = CatalogImportProposal::where('created_by', $this->admin->id)->latest('id')->firstOrFail();
        $this->get(route('admin.ai-import.review', $p))->assertOk()->assertSee('Add Selected Records');
        $original = $this->market->fresh()->getAttributes();
        $input = [...$this->edit($p), 'action' => 'import', 'confirm' => 1];
        $this->post(route('admin.ai-import.complete', $p), $input)->assertRedirect(route('admin.ai-import.success', $p))->assertSessionHasNoErrors();
        $receipt = app(CatalogAiImportService::class)->data($p->refresh())['import_result'];
        $this->assertSame(['markets' => 0, 'stalls' => 1, 'foods' => 1, 'linked' => 0], $receipt['counts']);
        $this->assertSame($original, $this->market->fresh()->getAttributes());
        $this->assertSame([['type' => 'Food', 'name' => 'TEST Skip Cake', 'reason' => 'Not selected']], $receipt['skipped']);
        $this->get(route('admin.ai-import.success', $p))->assertOk()->assertSee('TEST Skip Cake')->assertSee('Not selected');
        $this->post(route('admin.ai-import.complete', $p), $input)->assertRedirect(route('admin.ai-import.success', $p));
        $this->assertSame(1, $this->market->stalls()->where('name', 'TEST Sweet Stall')->count());
        Http::assertSentCount(2);
    }

    public function test_inactive_food_without_photo_imports_and_confirmation_is_still_required(): void
    {
        $p = $this->draft();
        $this->analyse($p);
        $input = [...$this->edit($p, false), 'action' => 'import', 'confirm' => 1];
        $input['stalls'][0]['foods'][0]['description'] = 'TEST edited evidence';
        $this->post(route('admin.ai-import.complete', $p), $input)->assertSessionHasNoErrors();
        $food = $this->market->stalls()->firstOrFail()->foods()->firstOrFail();
        $this->assertSame('TEST edited evidence', $food->description);
        $this->assertNull($food->image_path);
        $this->assertSame(Food::STATUS_INACTIVE, $food->status);
        $p = $this->draft();
        $this->analyse($p);
        $input = [...$this->edit($p), 'action' => 'import'];
        $this->post(route('admin.ai-import.complete', $p), $input)->assertSessionHasErrors('confirm');
        $this->assertSame('draft', $p->refresh()->status);
        $this->post(route('admin.ai-import.complete', $p), [...$this->edit($p, false), 'action' => 'save'])
            ->assertRedirect(route('admin.ai-import.review', $p))->assertSessionHasNoErrors();
        $this->assertSame(0, $this->market->stalls()->count());
    }

    public function test_deselecting_new_market_does_not_silently_create_it_for_selected_children(): void
    {
        $name = 'TEST Deselected Market '.Str::random(8);
        $this->text = str_replace($this->market->name, $name, $this->text);
        $this->actingAs($this->admin)->post(route('admin.ai-import.start'), ['name' => $name, 'city' => 'Petaling Jaya', 'url' => $this->url])->assertSessionHasNoErrors();
        $p = CatalogImportProposal::where('created_by', $this->admin->id)->latest('id')->firstOrFail();
        $this->analyse($p);
        $this->post(route('admin.ai-import.complete', $p), [...$this->edit($p), 'market' => ['selected' => 0], 'action' => 'import', 'confirm' => 1])
            ->assertSessionHasErrors('draft');
        $this->assertSame(0, NightMarket::where('name', $name)->count());
        $this->assertSame('draft', $p->refresh()->status);
    }

    public function test_analysis_failure_opens_recoverable_review_without_false_success_and_does_not_create_catalog_records(): void
    {
        Http::fake([$this->url => Http::response('', 403)]);
        $input = ['import_mode' => 'new_market', 'name' => 'TEST Recoverable '.Str::random(8), 'city' => 'Petaling Jaya', 'url' => $this->url];
        $this->actingAs($this->admin)->post(route('admin.ai-import.prepare'), $input)->assertStatus(303)->assertSessionHasErrors('source');
        $p = CatalogImportProposal::where('created_by', $this->admin->id)->latest('id')->firstOrFail();
        $this->get(route('admin.ai-import.review', $p))->assertOk()->assertSee('Analysis unavailable')->assertSee('TEST Recoverable');
        $this->assertSame(0, NightMarket::where('name', $input['name'])->count());
        $this->post(route('admin.ai-import.prepare'), $input)->assertSessionHasErrors('source');
        $this->assertSame(1, CatalogImportProposal::where('created_by', $this->admin->id)->count());
        Http::assertSentCount(2); // Explicit second submission, no automatic retry.
    }

    public function test_new_routes_keep_guest_client_denial_and_only_unused_drafts_can_be_deleted(): void
    {
        $p = $this->draft();
        $this->app['auth']->forgetGuards();
        $this->post(route('admin.ai-import.prepare'), [])->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => 'client']));
        $this->post(route('admin.ai-import.complete', $p), [])->assertForbidden();
        $this->get(route('admin.ai-import.success', $p))->assertForbidden();
        $this->delete(route('admin.ai-import.destroy-empty', $p))->assertForbidden();
        $this->actingAs($this->admin);
        $this->delete(route('admin.ai-import.destroy-empty', $p))->assertRedirect(route('admin.ai-import.history', ['status' => 'active']));
        $this->assertModelMissing($p);
        $this->assertDatabaseHas('social_media_sources', ['id' => $p->social_media_source_id]);
        $p = $this->draft();
        $this->analyse($p);
        $this->delete(route('admin.ai-import.destroy-empty', $p))->assertSessionHasErrors('draft');
        $this->assertModelExists($p);
        $this->get(route('admin.ai-import.history', ['status' => 'active']))->assertOk()->assertSee($this->market->name);
        $this->get(route('admin.ai-import.history', ['status' => 'imported']))->assertOk()->assertDontSee($this->market->name);
        $this->get(route('admin.ai-import.history', ['status' => 'bad']))->assertSessionHasErrors('status');
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
        $this->get(route('admin.ai-import.history'))->assertOk()->assertSee('Continue Review');
        Http::assertNothingSent();
    }

    public function test_search_uses_only_retrieved_urls_and_get_selection_is_user_scoped(): void
    {
        config(['services.catalog_search.tavily_key' => 'fake-search', 'services.catalog_search.tavily_free_confirmed' => true]);
        Http::fake(['api.tavily.com/search' => Http::response(['answer' => 'Invented https://made-up.example/ignore',
            'results' => [['url' => $this->url, 'title' => 'Retrieved article', 'content' => 'This source concerns the selected market.']]])]);
        $post = $this->actingAs($this->admin)->post(route('admin.ai-import.search'), ['import_mode' => 'existing_market', 'market_id' => $this->market->id]);
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
        $this->actingAs($this->admin)->post(route('admin.ai-import.search'), ['import_mode' => 'existing_market', 'market_id' => $this->market->id])->assertSessionHasErrors('search');
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
        $this->actingAs($this->admin)->post(route('admin.ai-import.search'), ['import_mode' => 'existing_market', 'market_id' => $this->market->id, 'search_kind' => 'paid'])
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
        $this->get(route('admin.ai-import.review', $p))->assertOk()->assertSee('Add Stalls & Foods to Existing Market');
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

    public function test_inactive_food_may_import_without_price_or_photo(): void
    {
        $p = $this->draft();
        $this->analyse($p);
        $input = $this->edit($p, false);
        $input['stalls'][0]['foods'][0]['price_min'] = null;
        $input['stalls'][0]['foods'][0]['price_max'] = null;
        $this->patch(route('admin.ai-import.update', $p), $input)->assertSessionHasNoErrors();
        $p->refresh();
        $this->get(route('admin.ai-import.review', $p))->assertOk()->assertSee('No photo yet')->assertDontSee('Confirmed photo');
        $this->post(route('admin.ai-import.import', $p), ['revision' => app(CatalogAiImportService::class)->revision($p), 'confirm' => 1])->assertSessionHasNoErrors();
        $food = $this->market->stalls()->firstOrFail()->foods()->firstOrFail();
        $this->assertNull($food->price_min);
        $this->assertSame('Dessert', $food->category);
        $this->assertNull($food->image_path);
    }

    public function test_deselecting_incomplete_food_keeps_draft_and_imports_selected_stall(): void
    {
        $p = $this->draft();
        $this->analyse($p);
        $input = $this->edit($p, false);
        $input['stalls'][0]['foods'][0]['selected'] = 0;
        $input['stalls'][0]['foods'][0]['category'] = null;
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

    public function test_new_market_can_be_imported_inactive_while_address_and_schedule_are_incomplete(): void
    {
        $name = 'TEST Incomplete Market '.Str::random(8);
        $text = "$name is a night market in Petaling Jaya, Selangor.";
        $payload = ['market' => ['name' => $name, 'address' => null, 'city' => 'Petaling Jaya', 'state' => 'Selangor',
            'description' => null, 'evidence_text' => $text, 'confidence' => null, 'operating_days' => []],
            'stalls' => [], 'warnings' => [], 'insufficient_data' => false];
        Http::fake([$this->url => Http::response('<article>'.$text.'</article>', 200, ['Content-Type' => 'text/html']),
            'generativelanguage.googleapis.com/*' => Http::response($this->response($payload))]);

        $this->actingAs($this->admin)->post(route('admin.ai-import.start'), [
            'name' => $name, 'city' => 'Petaling Jaya', 'url' => $this->url,
        ])->assertSessionHasNoErrors();
        $proposal = CatalogImportProposal::where('created_by', $this->admin->id)->sole();
        $this->post(route('admin.ai-import.analyse', $proposal), ['source_ids' => [0]])->assertSessionHasNoErrors();
        $proposal->refresh();
        $this->patch(route('admin.ai-import.update', $proposal), [
            'revision' => app(CatalogAiImportService::class)->revision($proposal),
            'market' => ['selected' => 1, 'name' => $name, 'address' => '', 'city' => 'Petaling Jaya'],
        ])->assertSessionHasNoErrors();
        $proposal->refresh();
        $this->post(route('admin.ai-import.import', $proposal), [
            'revision' => app(CatalogAiImportService::class)->revision($proposal), 'confirm' => 1,
        ])->assertSessionHasNoErrors();

        $market = NightMarket::where('name', $name)->sole();
        $this->assertSame(NightMarket::STATUS_INACTIVE, $market->status);
        $this->assertSame('', $market->address);
        $this->assertCount(0, $market->operatingDays);
    }

    public function test_later_article_analysis_enriches_market_after_video_items_were_extracted(): void
    {
        $name = 'TEST Enriched Market '.Str::random(8);
        $firstText = "$name in Petaling Jaya, Selangor. TEST Video Stall sells TEST Cake Dessert RM8.";
        $firstPayload = ['market' => ['name' => $name, 'address' => null, 'city' => 'Petaling Jaya', 'state' => 'Selangor',
            'description' => null, 'evidence_text' => $firstText, 'confidence' => null, 'operating_days' => []],
            'stalls' => [['name' => 'TEST Video Stall', 'description' => null, 'evidence_text' => $firstText, 'confidence' => null,
                'foods' => [['name' => 'TEST Cake', 'category' => 'Dessert', 'description' => null, 'price_display' => 'RM8',
                    'price_min' => 8, 'price_max' => 8, 'is_must_try' => false, 'evidence_text' => $firstText, 'confidence' => null]]]],
            'warnings' => [], 'insufficient_data' => false];
        $secondText = "$name, 15 Test Road, Petaling Jaya, Selangor opens Saturday 18:00 to 23:00.";
        $secondPayload = ['market' => ['name' => $name, 'address' => '15 Test Road', 'city' => 'Petaling Jaya', 'state' => 'Selangor',
            'description' => null, 'evidence_text' => $secondText, 'confidence' => null,
            'operating_days' => [['day_of_week' => 'Saturday', 'opening_time' => '18:00', 'closing_time' => '23:00',
                'evidence_text' => $secondText, 'confidence' => null]]],
            'stalls' => [], 'warnings' => [], 'insufficient_data' => false];
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push($this->response($firstPayload))->push($this->response($secondPayload))]);

        $this->actingAs($this->admin)->post(route('admin.ai-import.start'), [
            'name' => $name, 'city' => 'Petaling Jaya', 'url' => $this->url,
        ])->assertSessionHasNoErrors();
        $proposal = CatalogImportProposal::where('created_by', $this->admin->id)->sole();
        $this->post(route('admin.ai-import.analyse', $proposal), ['source_ids' => [0], 'text' => $firstText])->assertSessionHasNoErrors();
        $this->post(route('admin.ai-import.analyse', $proposal), [
            'url' => 'https://second.example.test/market', 'text' => $secondText,
        ])->assertSessionHasNoErrors();

        $graph = app(CatalogAiImportService::class)->data($proposal->fresh())['graph'];
        $this->assertCount(1, $graph['stalls']);
        $this->assertSame('15 Test Road', $graph['market']['address']);
        $this->assertSame('Saturday', $graph['operating_days'][0]['day_of_week']);
    }

    public function test_new_market_video_without_market_payload_imports_full_chain_and_repeated_post_is_idempotent(): void
    {
        $name = 'TEST Video Market '.Str::random(8);
        $text = '00:15 TEST Sweet Stall sells TEST Cake Dessert RM8 per slice.';
        $payload = $this->payload();
        $payload['market'] = null;
        $payload['stalls'][0]['evidence_text'] = $text;
        $payload['stalls'][0]['foods'][0]['evidence_text'] = $text;
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => $text]]]]]])
            ->push($this->response($payload))]);
        $this->actingAs($this->admin)->post(route('admin.ai-import.start'), [
            'name' => $name, 'city' => 'Petaling Jaya', 'url' => 'https://www.youtube.com/watch?v=TESTvideo01',
        ])->assertSessionHasNoErrors();
        $p = CatalogImportProposal::where('created_by', $this->admin->id)->sole();
        $this->post(route('admin.ai-import.analyse', $p), ['source_ids' => [0]])->assertSessionHasNoErrors();
        $p->refresh();
        $graph = app(CatalogAiImportService::class)->data($p)['graph'];
        $this->assertSame($name, $graph['market']['name']);
        $this->assertTrue($graph['market']['selected']);
        $this->assertNull($graph['market']['address'] ?? null);
        $this->assertNull($graph['market']['evidence_text'] ?? null);
        $this->assertFalse($graph['stalls'][0]['parent_confirmed']);
        $this->get(route('admin.ai-import.show', $p))->assertOk()->assertSee($name)->assertSee('not an AI verification');
        $this->patch(route('admin.ai-import.update', $p), $this->edit($p))->assertSessionHasNoErrors();
        $this->get(route('admin.ai-import.review', $p))->assertOk()->assertSee('Create a new inactive Market')->assertSee('Operating schedule not yet provided');
        $input = ['revision' => app(CatalogAiImportService::class)->revision($p->fresh()), 'confirm' => 1];
        $this->post(route('admin.ai-import.import', $p), $input)->assertSessionHasNoErrors();
        $market = NightMarket::where('name', $name)->sole();
        $stall = $market->stalls->sole();
        $food = $stall->foods->sole();
        $this->assertSame('inactive', $market->status);
        $this->assertSame('inactive', $stall->status);
        $this->assertSame('inactive', $food->status);
        $this->assertSame('unknown', $stall->halal_status);
        $this->assertSame('8.00', $food->price_max);
        Storage::disk('public')->assertExists($food->image_path);
        $this->assertSame(3, CatalogSocialMediaSourceLink::where('catalog_import_proposal_id', $p->id)->count());
        $this->get(route('night-markets.show', $market))->assertNotFound();
        $this->post(route('admin.ai-import.import', $p), $input)->assertRedirect(route('admin.night-markets.show', $market));
        $this->assertSame(1, NightMarket::where('name', $name)->count());
        $this->assertSame(1, $stall->foods()->count());
        Http::assertSentCount(2);
    }

    public function test_empty_and_failed_searches_are_not_reused_but_success_is_cached(): void
    {
        config(['services.catalog_search.tavily_key' => 'fake-search', 'services.catalog_search.tavily_free_confirmed' => true]);
        Http::fake(['api.tavily.com/search' => Http::sequence()->push(['results' => []])
            ->push(['detail' => 'PRIVATE_API_ERROR'], 401)
            ->push(['results' => [['url' => $this->url, 'title' => 'Recovered article']]])]);
        $input = ['import_mode' => 'existing_market', 'market_id' => $this->market->id, 'search_kind' => 'articles'];
        $this->actingAs($this->admin);
        $first = $this->post(route('admin.ai-import.search'), $input)->assertStatus(303);
        $this->get($first->headers->get('Location'))->assertSee('No retrieved sources');
        $second = $this->post(route('admin.ai-import.search'), $input)->assertStatus(303);
        $this->get($second->headers->get('Location'))->assertSee('HTTP 401')->assertDontSee('PRIVATE_API_ERROR');
        $third = $this->post(route('admin.ai-import.search'), $input)->assertStatus(303);
        $this->get($third->headers->get('Location'))->assertSee('Recovered article');
        $fourth = $this->post(route('admin.ai-import.search'), $input)->assertStatus(303);
        $this->assertSame($third->headers->get('Location'), $fourth->headers->get('Location'));
        Http::assertSentCount(3);
    }

    public function test_later_source_preserves_admin_cleared_market_fields_and_schedule(): void
    {
        $name = 'TEST Reviewed Market '.Str::random(8);
        $text = "$name, 15 Test Road, Petaling Jaya, Selangor. Saturday 18:00 to 23:00.";
        $payload = ['market' => ['name' => $name, 'city' => 'Petaling Jaya', 'state' => 'Selangor', 'address' => '15 Test Road',
            'evidence_text' => $text, 'operating_days' => [['day_of_week' => 'Saturday', 'opening_time' => '18:00', 'closing_time' => '23:00', 'evidence_text' => $text]]],
            'stalls' => [], 'warnings' => [], 'insufficient_data' => false];
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->response($payload))]);
        $this->actingAs($this->admin)->post(route('admin.ai-import.start'), ['name' => $name, 'city' => 'Petaling Jaya', 'url' => $this->url])->assertSessionHasNoErrors();
        $p = CatalogImportProposal::where('created_by', $this->admin->id)->sole();
        $this->post(route('admin.ai-import.analyse', $p), ['source_ids' => [0], 'text' => $text])->assertSessionHasNoErrors();
        $this->patch(route('admin.ai-import.update', $p), ['revision' => app(CatalogAiImportService::class)->revision($p->fresh()),
            'market' => ['address' => '', 'selected' => false], 'operating_days' => []])->assertSessionHasNoErrors();
        $this->post(route('admin.ai-import.analyse', $p), ['url' => 'https://second.example.test/market', 'text' => $text])->assertSessionHasNoErrors();
        $data = app(CatalogAiImportService::class)->data($p->fresh());
        $this->assertNull($data['graph']['market']['address']);
        $this->assertFalse($data['graph']['market']['selected']);
        $this->assertSame([], $data['graph']['operating_days']);
        $this->assertCount(2, $data['market_sources']);
    }

    public function test_incomplete_new_market_does_not_duplicate_existing_identity(): void
    {
        $this->actingAs($this->admin)->post(route('admin.ai-import.start'), ['name' => $this->market->name, 'city' => $this->market->city, 'url' => $this->url])->assertSessionHasNoErrors();
        $p = CatalogImportProposal::where('created_by', $this->admin->id)->sole();
        $this->post(route('admin.ai-import.import', $p), ['revision' => app(CatalogAiImportService::class)->revision($p), 'confirm' => 1])->assertSessionHasErrors();
        $this->assertSame(1, NightMarket::where('name', $this->market->name)->count());
        $this->assertSame('draft', $p->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_another_city_source_cannot_supply_new_market_evidence(): void
    {
        $name = 'TEST Location Market '.Str::random(8);
        $text = "$name in Shah Alam, Selangor, 55 Other Road.";
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->response([
            'market' => ['name' => $name, 'city' => 'Shah Alam', 'state' => 'Selangor', 'address' => '55 Other Road', 'evidence_text' => $text],
            'stalls' => [], 'warnings' => [], 'insufficient_data' => false,
        ]))]);
        $this->actingAs($this->admin)->post(route('admin.ai-import.start'), ['name' => $name, 'city' => 'Petaling Jaya', 'url' => $this->url])->assertSessionHasNoErrors();
        $p = CatalogImportProposal::where('created_by', $this->admin->id)->sole();
        $this->post(route('admin.ai-import.analyse', $p), ['source_ids' => [0], 'text' => $text])->assertSessionHasErrors('source');
        $market = app(CatalogAiImportService::class)->data($p->fresh())['graph']['market'];
        $this->assertSame('Petaling Jaya', $market['city']);
        $this->assertArrayNotHasKey('address', $market);
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
        $response = $this->actingAs($this->admin)->post(route('admin.ai-import.search'), ['import_mode' => 'existing_market', 'market_id' => $this->market->id]);
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
        $this->get(route('admin.ai-import.review', $p))->assertOk()->assertSee('TEST Cake')->assertSee('Add Stalls & Foods to Existing Market');
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
