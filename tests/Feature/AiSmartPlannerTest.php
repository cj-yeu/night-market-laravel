<?php

namespace Tests\Feature;

use App\Models\Food;
use App\Models\NightMarket;
use App\Models\Stall;
use App\Models\User;
use App\Services\AiSmartPlannerService;
use App\Services\PlannerCandidateService;
use App\Services\PlannerPreferenceParser;
use App\Services\PlannerRequestGuard;
use App\Support\PlannerFoodInterests;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AiSmartPlannerTest extends TestCase
{
    use DatabaseTransactions;

    private User $client;

    private NightMarket $market;

    private Food $food;

    private array $preferences;

    public function createApplication()
    {
        $app = parent::createApplication();
        $c = config('database.connections.'.config('database.default'));
        if (! $app->environment('testing') || config('database.default') !== 'mysql'
            || $c['database'] !== 'night_market_laravel_testing' || $c['host'] !== '127.0.0.1'
            || (string) $c['port'] !== '3306' || ! empty($c['url']) || ! empty($c['unix_socket']) || ! empty($c['read']) || ! empty($c['write'])) {
            throw new \RuntimeException('Isolated local testing database required. No schema commands are allowed.');
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
        config(['services.openai.planner_enabled' => true, 'services.openai.api_key' => 'fake-not-a-credential',
            'cache.stores.file.driver' => 'array']);
        $this->travelTo(now('Asia/Kuala_Lumpur')->setDate(2026, 10, 5)->setTime(10, 0));
        $this->client = User::factory()->create(['role' => 'client', 'is_active' => true]);
        $this->market = NightMarket::factory()->create(['city' => 'Petaling Jaya']);
        $this->market->operatingDays()->create(['day_of_week' => 'Monday', 'opening_time' => '17:00', 'closing_time' => '22:00']);
        $stall = Stall::factory()->create(['night_market_id' => $this->market->id, 'halal_status' => Stall::HALAL_CERTIFIED]);
        $this->food = Food::factory()->create(['stall_id' => $stall->id, 'category' => 'Dessert', 'price_min' => 8, 'price_max' => 10]);
        $this->preferences = ['visit_date' => '2026-10-05', 'city' => 'Petaling Jaya', 'night_market_id' => $this->market->id,
            'budget_min' => 0, 'budget_max' => 30, 'halal_preference' => Stall::HALAL_CERTIFIED,
            'must_try' => false, 'max_markets' => 1, 'categories' => [], 'recommendation_mode' => 'ai'];
    }

    private function fakeSelection(?array $plans = null): void
    {
        $this->fakeOutput(['plans' => $plans ?? [['market_id' => $this->market->id, 'foods' => [['food_id' => $this->food->id, 'reason' => 'known_price']]]]]);
    }

    private function fakeOutput(array $data): void
    {
        Http::fake(['api.openai.com/v1/responses' => Http::response(['status' => 'completed', 'output' => [[
            'type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($data)]],
        ]]])]);
    }

    private function generate(): array
    {
        return app(AiSmartPlannerService::class)->recommend($this->client, $this->preferences);
    }

    private function saveData(array $result): array
    {
        return ['snapshot_id' => $result['recommendations'][0]['snapshot_id'], 'night_market_id' => $this->market->id,
            'title' => 'A verified test itinerary', 'food_ids' => [$this->food->id]];
    }

    private function assertValidation(callable $operation, string $field): void
    {
        try {
            $operation();
            $this->fail('Expected validation failure.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    public function test_ai_selects_only_catalog_data_and_request_is_minimal_and_not_stored(): void
    {
        $this->fakeSelection();
        $this->preferences['preference_notes'] = 'PRIVATE_NOTE_NOT_FOR_PROVIDER';
        $result = $this->generate();
        $this->assertSame('ai', $result['source']);
        $this->assertSame('RM8.00–RM10.00', $result['recommendations'][0]['estimated_price_label']);
        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $this->assertSame('gpt-5.6-sol', $request['model']);
            $this->assertFalse($request['store']);
            $this->assertSame('none', $request['reasoning']['effort']);
            $this->assertTrue($request['text']['format']['strict']);
            $this->assertStringNotContainsString($this->client->email, $request['input']);
            $this->assertStringNotContainsString('PRIVATE_NOTE_NOT_FOR_PROVIDER', $request['input']);
            $this->assertStringNotContainsString($this->food->description, $request['input']);

            return true;
        });
    }

    public function test_forged_duplicate_cross_market_and_false_claims_fall_back(): void
    {
        $other = Food::factory()->create(['price_min' => 1, 'price_max' => 2]);
        foreach ([
            [['food_id' => 999999999, 'reason' => 'known_price']],
            [['food_id' => $this->food->id, 'reason' => 'known_price'], ['food_id' => $this->food->id, 'reason' => 'known_price']],
            [['food_id' => $other->id, 'reason' => 'known_price']],
            [['food_id' => $this->food->id, 'reason' => 'family_signal']],
        ] as $items) {
            $this->fakeSelection([['market_id' => $this->market->id, 'foods' => $items]]);
            $result = $this->generate();
            $this->assertSame('basic', $result['source']);
            $this->assertSame([$this->food->id], collect($result['recommendations'][0]['foods'])->pluck('food.id')->all());
        }
    }

    public function test_over_budget_and_unknown_price_are_not_accepted_as_affordable(): void
    {
        $second = Food::factory()->create(['stall_id' => $this->food->stall_id, 'price_min' => 25, 'price_max' => 25]);
        $unknown = Food::factory()->create(['stall_id' => $this->food->stall_id, 'price_display' => 'RM1']);
        $this->fakeSelection([['market_id' => $this->market->id, 'foods' => [
            ['food_id' => $this->food->id, 'reason' => 'known_price'], ['food_id' => $second->id, 'reason' => 'known_price'],
        ]]]);
        $result = $this->generate();
        $this->assertSame('basic', $result['source']);
        $candidates = app(PlannerCandidateService::class)->candidates($this->preferences, $this->preferences['visit_date']);
        $this->assertFalse($candidates->has($unknown->id));
        $this->assertLessThanOrEqual(3000, app(PlannerCandidateService::class)->cost(collect($result['recommendations'][0]['foods'])->pluck('food'))['max_cents']);
        Http::assertSent(fn ($request) => ! collect(json_decode($request['input'], true)['candidates'])->contains('food_id', $unknown->id));
    }

    public function test_halal_and_parent_chain_cannot_be_bypassed(): void
    {
        $claimed = Stall::factory()->create(['night_market_id' => $this->market->id, 'halal_status' => Stall::HALAL_MUSLIM_OWNED_OR_CLAIMED]);
        $food = Food::factory()->create(['stall_id' => $claimed->id, 'price_min' => 1, 'price_max' => 2]);
        $this->fakeSelection([['market_id' => $this->market->id, 'foods' => [['food_id' => $food->id, 'reason' => 'preference_match']]]]);
        $this->assertSame('basic', $this->generate()['source']);
        $this->food->stall->update(['status' => 'inactive']);
        $this->assertCount(0, app(PlannerCandidateService::class)->candidates($this->preferences, $this->preferences['visit_date']));
        $this->market->update(['state' => 'Johor']);
        $this->assertCount(0, app(PlannerCandidateService::class)->candidates([...$this->preferences, 'halal_preference' => 'any'], $this->preferences['visit_date']));
    }

    public function test_invalid_output_timeout_and_provider_errors_use_basic_without_retries(): void
    {
        foreach ([401, 403, 429, 500] as $status) {
            Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'RAW_PROVIDER_PRIVATE']], $status)]);
            $result = $this->generate();
            $this->assertSame('basic', $result['source']);
            $this->assertStringNotContainsString('RAW_PROVIDER_PRIVATE', $result['source_notice']);
            Http::assertSentCount(1);
        }
        app(PlannerRequestGuard::class)->cache()->forget('planner-rate:'.$this->client->id);
        Http::fake(['api.openai.com/*' => Http::failedConnection()]);
        $this->assertSame('basic', $this->generate()['source']);
        $this->fakeOutput(['plans' => 'not-an-array']);
        $this->assertSame('basic', $this->generate()['source']);
    }

    public function test_cache_reuses_selection_but_catalog_version_invalidates_it(): void
    {
        $this->fakeSelection();
        $this->generate();
        $this->generate();
        Http::assertSentCount(1);
        $this->food->update(['price_max' => 11]);
        $this->generate();
        Http::assertSentCount(2);
    }

    public function test_snapshot_saves_an_ordinary_owned_editable_plan_without_calendar_or_mail(): void
    {
        $this->fakeSelection();
        $result = $this->generate();
        $response = $this->actingAs($this->client)->post(route('client.visit-plans.smart-planner.save'), $this->saveData($result));
        $response->assertSessionHasNoErrors();
        $plan = $this->client->visitPlans()->latest('id')->firstOrFail();
        $response->assertRedirect(route('client.visit-plans.show', $plan));
        $this->assertSame([$this->food->id], $plan->items->pluck('food_id')->all());
        $this->get(route('client.visit-plans.edit', $plan))->assertOk();
        $this->assertNull($plan->googleCalendarEvent);
        Mail::assertNothingSent();
        Notification::assertNothingSent();
        Http::assertSentCount(1);
    }

    public function test_snapshot_is_user_bound_expires_and_is_invalidated_by_new_preferences(): void
    {
        $this->fakeSelection();
        $data = $this->saveData($this->generate());
        $other = User::factory()->create(['role' => 'client']);
        $this->assertValidation(fn () => app(AiSmartPlannerService::class)->save($other, $data), 'snapshot_id');
        $this->generate();
        $this->assertValidation(fn () => app(AiSmartPlannerService::class)->save($this->client, $data), 'snapshot_id');
        $data = $this->saveData($this->generate());
        app(AiSmartPlannerService::class)->invalidate($this->client, $data['snapshot_id']);
        $this->assertValidation(fn () => app(AiSmartPlannerService::class)->save($this->client, $data), 'snapshot_id');
    }

    public function test_save_rechecks_prices_and_rejects_arbitrary_replacement_ids(): void
    {
        $this->fakeSelection();
        $data = $this->saveData($this->generate());
        $this->assertValidation(fn () => app(AiSmartPlannerService::class)->save($this->client, [...$data, 'food_ids' => [999999999]]), 'food_ids');
        $this->food->update(['price_max' => 100]);
        $this->assertValidation(fn () => app(AiSmartPlannerService::class)->save($this->client, $data), 'snapshot_id');
        $this->assertSame(0, $this->client->visitPlans()->count());
    }

    public function test_duplicate_save_never_creates_a_second_plan(): void
    {
        $this->fakeSelection();
        $data = $this->saveData($this->generate());
        $plan = app(AiSmartPlannerService::class)->save($this->client, $data);
        try {
            $repeated = app(AiSmartPlannerService::class)->save($this->client, $data);
            $this->assertSame($plan->id, $repeated->id);
        } catch (ValidationException $e) {
            // DatabaseTransactions defers afterCommit; an unresolved receipt must
            // fail closed instead of creating another plan.
            $this->assertArrayHasKey('snapshot_id', $e->errors());
        }
        $this->assertSame(1, $this->client->visitPlans()->count());
        app(PlannerRequestGuard::class)->cache()->put('planner-snapshot:'.$this->client->id.':'.$data['snapshot_id'].':receipt', $plan->id, 86400);
        $this->assertSame($plan->id, app(AiSmartPlannerService::class)->save($this->client, $data)->id);
    }

    public function test_alternative_date_requires_explicit_confirmation(): void
    {
        $this->fakeSelection();
        $this->preferences['visit_date'] = '2026-10-06';
        $result = $this->generate();
        $this->assertTrue($result['uses_fallback']);
        $this->assertSame('2026-10-12', $result['recommendation_date']);
        $data = $this->saveData($result);
        $this->assertValidation(fn () => app(AiSmartPlannerService::class)->save($this->client, $data), 'confirmed_fallback_date');
        $plan = app(AiSmartPlannerService::class)->save($this->client, [...$data, 'confirmed_fallback_date' => true]);
        $this->assertSame('2026-10-12', $plan->visit_date->toDateString());
    }

    public function test_parse_returns_suggestions_not_mutations_and_resolves_relative_dates_locally(): void
    {
        $this->fakeOutput(['date_kind' => 'tomorrow', 'date' => null, 'city' => 'Petaling Jaya', 'budget_max' => 30,
            'halal_preference' => Stall::HALAL_CERTIFIED, 'interests' => ['desserts'], 'unsupported' => true]);
        $this->actingAs($this->client)->postJson(route('client.visit-plans.smart-planner.parse'), ['text' => '明晚，RM30，甜品，适合过敏的人'])
            ->assertOk()->assertJsonPath('preferences.visit_date', '2026-10-06')->assertJsonPath('preferences.budget_max', 30)
            ->assertJsonPath('preferences.halal_preference', Stall::HALAL_CERTIFIED)
            ->assertJsonPath('notice', 'Some requests are unsupported or ambiguous. We cannot verify allergies, spice levels, facilities, live opening or unlisted locations. Review the supported suggestions below; no preference has been changed.');
        $this->assertSame(0, $this->client->visitPlans()->count());
        Http::assertSentCount(1);
    }

    public function test_parser_rejects_unknown_city_and_does_not_echo_provider_text(): void
    {
        $this->fakeOutput(['date_kind' => 'absolute', 'date' => '2020-01-01', 'city' => 'Invented City', 'budget_max' => 30,
            'halal_preference' => 'made-up-certification', 'interests' => ['anything'], 'unsupported' => false]);
        $result = app(PlannerPreferenceParser::class)->parse($this->client->id, 'A request', ['Petaling Jaya'], ['desserts']);
        $this->assertNull($result['preferences']);
        $this->assertStringNotContainsString('Invented City', $result['notice']);
    }

    public function test_interest_mapping_keeps_legacy_aliases_and_unknown_categories(): void
    {
        $available = ['Beverage', 'Dessert', 'Main / Salad', 'Main Dish / Rice', 'Dim Sum / Bao'];
        $resolved = PlannerFoodInterests::resolve(['drinks'], [], $available);
        $this->assertSame(['Beverage'], $resolved);
        $this->assertSame(['Main / Salad'], PlannerFoodInterests::resolve([], ['Main / Salad'], $available));
        $this->assertSame(['Main Dish / Rice'], PlannerFoodInterests::resolve(['meals'], [], $available));
        $this->assertValidation(fn () => PlannerFoodInterests::resolve(['unknown'], [], $available), 'interests');
    }

    public function test_new_ui_and_routes_keep_client_auth_and_explicit_actions(): void
    {
        $url = route('client.visit-plans.smart-planner.index');
        $this->get($url)->assertRedirect(route('login'));
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->postJson(route('client.visit-plans.smart-planner.parse'), ['text' => 'Tomorrow'])->assertForbidden();
        $this->actingAs($this->client)->get($url)->assertOk()
            ->assertSee('Your next night out, planned.')->assertSee('Understand my request')->assertSee('Apply selected suggestions')
            ->assertSee('Food budget')->assertSee('Clear selections')->assertSee('More preferences')->assertSee('Muslim-owned/claimed is not Halal certification.')
            ->assertSee('data-parse-url', false)->assertSee('smart-planner.js');
        Http::assertNothingSent();
    }

    public function test_rate_and_concurrent_request_protection_fail_closed(): void
    {
        $guard = app(PlannerRequestGuard::class);
        for ($i = 0; $i < 4; $i++) {
            $guard->charge($this->client->id);
        }
        $this->assertValidation(fn () => $guard->charge($this->client->id), 'planner');
        $lock = $guard->cache()->lock('planner-user:'.$this->client->id, 120);
        $this->assertTrue($lock->get());
        $this->assertValidation(fn () => $guard->run($this->client->id, fn () => 'must not execute'), 'planner');
        $lock->release();
        Http::assertNothingSent();
    }

    public function test_new_recommendation_page_renders_server_snapshot_and_replacements(): void
    {
        $this->fakeSelection();
        $this->food->update(['name' => 'Planner fixture dessert']);
        Food::factory()->create(['stall_id' => $this->food->stall_id, 'name' => 'Planner fixture drink', 'category' => 'Beverage / Drink', 'price_min' => 4, 'price_max' => 6]);
        $response = $this->actingAs($this->client)->post(route('client.visit-plans.smart-planner.recommend'), $this->preferences);
        $response->assertOk()->assertSee('AI-selected')->assertSee('Save Visit Plan')->assertSee('Replace')->assertSee('Remove')
            ->assertSee('Planner fixture dessert')->assertSee('Planner fixture drink')->assertDontSee('Score ');
        Http::assertSentCount(1);
        // Optional local visual artifact, never a production route or auth bypass.
        // The transaction rolls back all synthetic fixture records after the test.
        if (getenv('NIGHTBITE_PLANNER_PREVIEW') === '1') {
            $html = $response->getContent();
            $html = preg_replace('#(?<=")https?://(?:localhost|127\.0\.0\.1)(?::\d+)?/#', '/', $html);
            $html = preg_replace('/name="_token" value="[^"]*"/', 'name="_token" value="preview-only"', $html);
            $html = preg_replace('/<meta name="csrf-token" content="[^"]*">/', '', $html);
            $html = str_replace('</body>', '<div style="position:fixed;bottom:0;background:#222;color:white;padding:4px 12px;z-index:9999">Local Blade + HTTP-fake test fixtures · NOT a logged-in browser acceptance</div></body>', $html);
            file_put_contents(sys_get_temp_dir().'/nightbite-planner-preview.html', $html);
        }
    }

    public function test_expired_snapshot_and_duplicate_food_ids_are_rejected(): void
    {
        $this->fakeSelection();
        $data = $this->saveData($this->generate());
        $this->actingAs($this->client)->post(route('client.visit-plans.smart-planner.save'), [...$data, 'food_ids' => [$this->food->id, $this->food->id]])
            ->assertSessionHasErrors('food_ids.0');
        $key = 'planner-snapshot:'.$this->client->id.':'.$data['snapshot_id'];
        $cache = app(PlannerRequestGuard::class)->cache();
        $snapshot = $cache->get($key);
        $snapshot['expires_at'] = time() - 1;
        $cache->put($key, $snapshot, 1200);
        $this->assertValidation(fn () => app(AiSmartPlannerService::class)->save($this->client, $data), 'snapshot_id');
    }

    public function test_valid_replacement_is_saved_and_browser_budget_cannot_override_snapshot(): void
    {
        $other = Food::factory()->create(['stall_id' => $this->food->stall_id, 'price_min' => 25, 'price_max' => 25]);
        $this->fakeSelection();
        $data = $this->saveData($this->generate());
        $this->assertValidation(fn () => app(AiSmartPlannerService::class)->save($this->client,
            [...$data, 'food_ids' => [$this->food->id, $other->id], 'budget_max' => 1000]), 'food_ids');
        $plan = app(AiSmartPlannerService::class)->save($this->client, [...$data, 'food_ids' => [$other->id]]);
        $this->assertSame([$other->id], $plan->items->pluck('food_id')->all());
        Http::assertSentCount(1);
    }

    public function test_disabled_ai_uses_basic_and_never_sends_a_request(): void
    {
        config(['services.openai.planner_enabled' => false]);
        $result = $this->generate();
        $this->assertSame('basic', $result['source']);
        $this->assertNotEmpty($result['recommendations']);
        Http::assertNothingSent();
    }

    public function test_quick_template_cannot_accept_more_than_three_foods_from_ai(): void
    {
        $foods = Food::factory()->count(3)->create(['stall_id' => $this->food->stall_id, 'price_min' => 1, 'price_max' => 1]);
        $this->preferences['template'] = 'quick_visit';
        $this->fakeSelection([['market_id' => $this->market->id, 'foods' => $foods->prepend($this->food)->map(fn ($food) => ['food_id' => $food->id, 'reason' => 'known_price'])->all()]]);
        $result = $this->generate();
        $this->assertSame('basic', $result['source']);
        $this->assertLessThanOrEqual(3, count($result['recommendations'][0]['foods']));
    }

    public function test_catalog_change_during_provider_call_is_not_trusted(): void
    {
        Http::fake(['api.openai.com/*' => function () {
            $this->food->update(['price_max' => 100]);

            return Http::response(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [[
                'type' => 'output_text', 'text' => json_encode(['plans' => [['market_id' => $this->market->id,
                    'foods' => [['food_id' => $this->food->id, 'reason' => 'known_price']]]]]),
            ]]]]]);
        }]);
        $result = $this->generate();
        $this->assertSame('basic', $result['source']);
        $this->assertSame([], $result['recommendations']);
    }

    public function test_interest_selection_matches_legacy_categories_and_keeps_explicit_choices_separate(): void
    {
        $drink = Food::factory()->create(['stall_id' => $this->food->stall_id, 'category' => ' Beverage  / Drink ', 'price_min' => 4, 'price_max' => 6]);
        $this->fakeSelection([['market_id' => $this->market->id, 'foods' => [['food_id' => $drink->id, 'reason' => 'known_price']]]]);
        $response = $this->actingAs($this->client)->post(route('client.visit-plans.smart-planner.recommend'), [...$this->preferences, 'interests' => ['drinks']]);
        $response->assertOk()->assertViewHas('preferences', fn ($preferences) => $preferences['categories'] === ['Beverage'] && $preferences['explicit_categories'] === [])
            ->assertViewHas('plannerResult', fn ($result) => $result['source'] === 'ai' && $result['recommendations'][0]['foods'][0]['food']->id === $drink->id);
        $this->assertSame(' Beverage  / Drink ', $drink->fresh()->category);
    }
}
