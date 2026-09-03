<?php

namespace Tests\Feature;

use App\Exceptions\GoogleCalendarIntegrationException;
use App\Models\GoogleCalendarConnection;
use App\Models\GoogleCalendarEvent;
use App\Models\MarketOperatingDay;
use App\Models\NightMarket;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\VisitPlan;
use App\Models\VisitPlanItem;
use App\Services\GoogleCalendarOAuthService;
use App\Services\GoogleCalendarService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ReflectionMethod;
use Tests\TestCase;

class GoogleCalendarVisitPlanTest extends TestCase
{
    use DatabaseTransactions;

    private User $client;

    private int $marketSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-30 10:00:00');
        config()->set('services.google_calendar', [
            'client_id' => 'calendar-test-client-id',
            'client_secret' => 'calendar-test-client-secret',
            'redirect' => 'https://night-market-laravel.test/integrations/google-calendar/callback',
        ]);
        $this->client = User::factory()->create([
            'role' => User::ROLE_CLIENT,
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_calendar_routes_require_an_active_verified_client_and_protect_other_users_plans(): void
    {
        [$plan] = $this->planFor($this->client);
        $unverified = User::factory()->unverified()->create(['role' => User::ROLE_CLIENT]);
        $inactive = User::factory()->create(['role' => User::ROLE_CLIENT, 'is_active' => false]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $otherClient = User::factory()->create(['role' => User::ROLE_CLIENT]);

        $this->get(route('client.visit-plans.google-calendar.connect', $plan))
            ->assertRedirect(route('login'));
        $this->get(route('client.google-calendar.callback'))
            ->assertRedirect(route('login'));
        $this->actingAs($unverified)
            ->get(route('client.visit-plans.google-calendar.connect', $plan))
            ->assertRedirect(route('verification.notice'));
        $this->actingAs($inactive)
            ->post(route('client.visit-plans.google-calendar.sync', $plan))
            ->assertRedirect(route('login'));
        $this->assertGuest();
        $this->actingAs($admin)
            ->get(route('client.visit-plans.google-calendar.connect', $plan))
            ->assertForbidden();
        $this->actingAs($admin)
            ->post(route('client.visit-plans.google-calendar.sync', $plan))
            ->assertForbidden();
        $this->actingAs($otherClient)
            ->post(route('client.visit-plans.google-calendar.sync', $plan))
            ->assertNotFound();
    }

    public function test_calendar_oauth_uses_a_one_time_state_and_only_the_events_owned_scope(): void
    {
        [$plan] = $this->planFor($this->client);
        $response = $this->actingAs($this->client)
            ->get(route('client.visit-plans.google-calendar.connect', $plan))
            ->assertRedirect();

        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->assertSame(GoogleCalendarOAuthService::SCOPE_EVENTS_OWNED, $query['scope']);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('true', $query['include_granted_scopes']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertArrayHasKey('state', $query);
        $this->assertSame('calendar-test-client-id', $query['client_id']);
        $this->assertSame(config('services.google_calendar.redirect'), $query['redirect_uri']);
        $response->assertSessionHas(GoogleCalendarOAuthService::SESSION_INTENT, function (array $intent) use ($plan, $query): bool {
            return $intent['user_id'] === $this->client->id
                && $intent['visit_plan_id'] === $plan->id
                && hash_equals($intent['state'], $query['state'])
                && isset($intent['expires_at']);
        });
        $this->assertSame('/auth/google/callback', parse_url(route('auth.google.callback'), PHP_URL_PATH));
        $this->assertSame('/integrations/google-calendar/callback', parse_url(route('client.google-calendar.callback'), PHP_URL_PATH));
    }

    public function test_password_and_google_linked_clients_can_connect_calendar_independently(): void
    {
        [$passwordPlan] = $this->planFor($this->client);
        $googleLinkedClient = User::factory()->create([
            'role' => User::ROLE_CLIENT,
            'password' => null,
        ]);
        $googleLinkedClient->socialAccounts()->create([
            'provider' => SocialAccount::PROVIDER_GOOGLE,
            'provider_user_id' => 'calendar-linked-google-client',
            'provider_email' => $googleLinkedClient->email,
        ]);
        [$googlePlan] = $this->planFor($googleLinkedClient, visitDate: now()->addDays(4));

        $this->actingAs($this->client)
            ->get(route('client.visit-plans.google-calendar.connect', $passwordPlan))
            ->assertRedirect();
        $this->actingAs($googleLinkedClient)
            ->get(route('client.visit-plans.google-calendar.connect', $googlePlan))
            ->assertRedirect();
    }

    public function test_oauth_callback_creates_an_encrypted_connection_and_a_single_primary_calendar_event(): void
    {
        [$plan] = $this->planFor($this->client, '18:00', '22:00', '<b>Family</b> market notes');
        VisitPlanItem::factory()->create([
            'visit_plan_id' => $plan->id,
            'item_type' => 'stall',
            'item_name' => 'Satay Stall',
        ]);
        VisitPlanItem::factory()->create([
            'visit_plan_id' => $plan->id,
            'item_type' => 'food',
            'item_name' => 'Grilled Fish',
        ]);
        $state = $this->startOauth($plan);
        $this->fakeOAuthAndCalendar();

        $this->actingAs($this->client)
            ->get(route('client.google-calendar.callback', ['state' => $state, 'code' => 'sensitive-auth-code']))
            ->assertRedirect(route('client.visit-plans.show', $plan))
            ->assertSessionHas('status', 'Your visit plan was added to Google Calendar.');

        $event = GoogleCalendarEvent::where('visit_plan_id', $plan->id)->firstOrFail();
        $this->assertSame($this->client->id, $event->user_id);
        $this->assertSame(1, GoogleCalendarEvent::where('visit_plan_id', $plan->id)->count());
        $this->assertNotSame('calendar-access-token', DB::table('google_calendar_connections')->value('access_token'));
        $this->assertSame('calendar-access-token', $this->client->googleCalendarConnection()->firstOrFail()->access_token);
        Http::assertSent(function (ClientRequest $request): bool {
            $data = $request->data();

            return $request->method() === 'POST'
                && str_starts_with($request->url(), 'https://www.googleapis.com/calendar/v3/calendars/primary/events')
                && ($data['summary'] ?? null) === 'Night Market Visit — Calendar Test Market'
                && ($data['location'] ?? null) === '12 Jalan Test, Shah Alam, Selangor'
                && str_contains((string) ($data['description'] ?? ''), 'Selected Stalls:')
                && str_contains((string) ($data['description'] ?? ''), 'https://')
                && ! str_contains((string) ($data['description'] ?? ''), '<b>')
                && ($data['start']['timeZone'] ?? null) === 'Asia/Kuala_Lumpur'
                && ($data['end']['timeZone'] ?? null) === 'Asia/Kuala_Lumpur';
        });
        Http::assertSent(function (ClientRequest $request): bool {
            $data = $request->data();

            return $request->method() === 'POST'
                && $request->url() === 'https://oauth2.googleapis.com/token'
                && str_starts_with(implode(',', (array) $request->header('Content-Type')), 'application/x-www-form-urlencoded')
                && ($data['grant_type'] ?? null) === 'authorization_code'
                && ($data['redirect_uri'] ?? null) === config('services.google_calendar.redirect')
                && ($data['client_id'] ?? null) === config('services.google_calendar.client_id');
        });
    }

    public function test_production_shaped_token_response_persists_a_nullable_encrypted_refresh_token(): void
    {
        [$plan] = $this->planFor($this->client);
        $state = $this->startOauth($plan);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'production-shaped-access-token',
                'expires_in' => 3599,
                'scope' => GoogleCalendarOAuthService::SCOPE_EVENTS_OWNED,
                'token_type' => 'Bearer',
            ], 200),
            'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
                'id' => 'production-shaped-event',
                'htmlLink' => 'https://calendar.google.com/calendar/event?eid=production-shaped',
            ], 201),
        ]);

        $this->actingAs($this->client)
            ->get(route('client.google-calendar.callback', ['state' => $state, 'code' => 'authorization-code-not-logged']))
            ->assertRedirect(route('client.visit-plans.show', $plan))
            ->assertSessionHas('status', 'Your visit plan was added to Google Calendar.');

        $connection = GoogleCalendarConnection::query()->where('user_id', $this->client->id)->firstOrFail();
        $this->assertSame('production-shaped-access-token', $connection->access_token);
        $this->assertNull($connection->refresh_token);
        $this->assertNotNull($connection->token_expires_at);
        $this->assertNotNull($connection->connected_at);
        $this->assertSame([GoogleCalendarOAuthService::SCOPE_EVENTS_OWNED], $connection->scopes);
        $this->assertNull(DB::table('google_calendar_connections')->where('id', $connection->id)->value('refresh_token'));
    }

    public function test_event_insert_failure_keeps_the_connection_and_a_later_sync_retries_without_oauth(): void
    {
        [$plan] = $this->planFor($this->client);
        $state = $this->startOauth($plan);
        $this->fakeOAuthAndCalendar(Http::sequence()
            ->push(['error' => ['message' => 'sensitive API detail']], 503)
            ->push([
                'id' => 'retry-event',
                'htmlLink' => 'https://calendar.google.com/calendar/event?eid=retry',
            ], 201));

        $this->actingAs($this->client)
            ->get(route('client.google-calendar.callback', ['state' => $state, 'code' => 'authorization-code-not-logged']))
            ->assertRedirect(route('client.visit-plans.show', $plan))
            ->assertSessionHas('error', 'Google Calendar was connected, but the visit event could not be added. Please try Add to Google Calendar again.');

        $this->assertDatabaseHas('google_calendar_connections', ['user_id' => $this->client->id]);
        $this->assertDatabaseCount('google_calendar_events', 0);

        $this->actingAs($this->client)
            ->post(route('client.visit-plans.google-calendar.sync', $plan))
            ->assertRedirect(route('client.visit-plans.show', $plan))
            ->assertSessionHas('status', 'Your Google Calendar event was updated.');

        $this->assertDatabaseHas('google_calendar_events', ['visit_plan_id' => $plan->id]);
        $this->assertCount(1, Http::recorded(fn (ClientRequest $request): bool => $request->url() === 'https://oauth2.googleapis.com/token'));
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'POST'
            && str_starts_with($request->url(), 'https://www.googleapis.com/calendar/v3/calendars/primary/events'));
    }

    public function test_token_exchange_failures_have_safe_reason_codes_and_never_log_sensitive_values(): void
    {
        [$plan] = $this->planFor($this->client);
        $state = $this->startOauth($plan);
        Log::spy();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'error' => 'invalid_client',
                'error_description' => 'sensitive client secret detail',
            ], 401),
        ]);

        $this->actingAs($this->client)
            ->get(route('client.google-calendar.callback', ['state' => $state, 'code' => 'authorization-code-not-logged']))
            ->assertRedirect(route('client.visit-plans.show', $plan))
            ->assertSessionHas('error', 'Google Calendar could not complete the connection. Please try again.');

        Log::shouldHaveReceived('warning')->atLeast()->once()->withArgs(function (string $message, array $context): bool {
            $serialized = json_encode([$message, $context]);

            return $message === 'Google Calendar integration failed.'
                && ($context['safe_reason_code'] ?? null) === GoogleCalendarIntegrationException::REASON_TOKEN_EXCHANGE_INVALID_CLIENT
                && ($context['google_http_status'] ?? null) === 401
                && ! str_contains((string) $serialized, 'authorization-code-not-logged')
                && ! str_contains((string) $serialized, 'sensitive client secret detail')
                && ! str_contains((string) $serialized, 'calendar-test-client-secret');
        });
        $this->assertDatabaseCount('google_calendar_connections', 0);
    }

    public function test_redirect_uri_mismatch_and_calendar_api_forbidden_fail_safely(): void
    {
        [$plan] = $this->planFor($this->client);
        $state = $this->startOauth($plan);
        Log::spy();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['error' => 'redirect_uri_mismatch'], 400),
        ]);

        $this->actingAs($this->client)
            ->get(route('client.google-calendar.callback', ['state' => $state, 'code' => 'authorization-code-not-logged']))
            ->assertSessionHas('error', 'Google Calendar could not complete the connection. Please try again.');
        Log::shouldHaveReceived('warning')->atLeast()->once()->withArgs(fn (string $message, array $context): bool => $message === 'Google Calendar integration failed.'
            && ($context['safe_reason_code'] ?? null) === GoogleCalendarIntegrationException::REASON_TOKEN_EXCHANGE_REDIRECT_MISMATCH);

        [$apiPlan] = $this->planFor($this->client, visitDate: now()->addDays(4));
        $this->establishConnection($this->client);
        Log::spy();
        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::sequence()
                ->push([
                    'error' => ['errors' => [['reason' => 'accessNotConfigured']]],
                ], 403)
                ->push([
                    'error' => ['errors' => [['reason' => 'insufficientPermissions']]],
                ], 403),
        ]);
        $this->actingAs($this->client)
            ->post(route('client.visit-plans.google-calendar.sync', $apiPlan))
            ->assertSessionHas('error', 'Google Calendar is currently unavailable. Please try again later.');
        Log::shouldHaveReceived('warning')->atLeast()->once()->withArgs(fn (string $message, array $context): bool => $message === 'Google Calendar integration failed.'
            && ($context['safe_reason_code'] ?? null) === GoogleCalendarIntegrationException::REASON_EVENT_INSERT_API_DISABLED
            && ($context['google_http_status'] ?? null) === 403);

        $this->actingAs($this->client)
            ->post(route('client.visit-plans.google-calendar.sync', $apiPlan))
            ->assertSessionHas('error', 'Google Calendar did not authorize event changes. Please reconnect and approve Calendar access again.');
        Log::shouldHaveReceived('warning')->atLeast()->once()->withArgs(fn (string $message, array $context): bool => $message === 'Google Calendar integration failed.'
            && ($context['safe_reason_code'] ?? null) === GoogleCalendarIntegrationException::REASON_EVENT_INSERT_FORBIDDEN
            && ($context['google_http_status'] ?? null) === 403);
    }

    public function test_state_mismatch_expiry_replay_and_consent_denial_are_safe(): void
    {
        [$plan] = $this->planFor($this->client);
        $state = $this->startOauth($plan);

        $this->actingAs($this->client)
            ->get(route('client.google-calendar.callback', ['state' => 'wrong-state', 'code' => 'sensitive-code']))
            ->assertRedirect(route('client.visit-plans.index'))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'invalid or expired') && ! str_contains($message, 'sensitive-code'));
        Http::assertNothingSent();

        $this->actingAs($this->client)
            ->get(route('client.google-calendar.callback', ['state' => $state, 'code' => 'sensitive-code']))
            ->assertRedirect(route('client.visit-plans.index'))
            ->assertSessionHas('error', 'The Google Calendar connection session is invalid or expired. Please try again from your visit plan.');

        $expiredState = 'expired-state';
        $this->actingAs($this->client)
            ->withSession([GoogleCalendarOAuthService::SESSION_INTENT => [
                'state' => $expiredState,
                'user_id' => $this->client->id,
                'visit_plan_id' => $plan->id,
                'expires_at' => now()->subMinute()->toIso8601String(),
            ]])
            ->get(route('client.google-calendar.callback', ['state' => $expiredState, 'code' => 'sensitive-code']))
            ->assertRedirect(route('client.visit-plans.index'));

        $validState = $this->startOauth($plan);
        $this->actingAs($this->client)
            ->get(route('client.google-calendar.callback', ['state' => $validState, 'error' => 'access_denied']))
            ->assertRedirect(route('client.visit-plans.show', $plan))
            ->assertSessionHas('error', 'Google Calendar access was not granted. Your visit plan was not changed.');
        $this->assertDatabaseCount('google_calendar_connections', 0);
        $this->assertDatabaseCount('google_calendar_events', 0);
    }

    public function test_overnight_schedule_uses_the_following_day_and_missing_times_fall_back_to_all_day(): void
    {
        [$plan] = $this->planFor($this->client, '18:00', '01:00');
        $this->establishConnection($this->client);
        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response(['id' => 'event-overnight'], 201),
        ]);

        $this->actingAs($this->client)
            ->post(route('client.visit-plans.google-calendar.sync', $plan))
            ->assertRedirect(route('client.visit-plans.show', $plan));
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'POST'
            && ($request->data()['end']['dateTime'] ?? null) === '2026-09-03T01:00:00+08:00');

        $allDayPlan = new VisitPlan([
            'user_id' => $this->client->id,
            'night_market_id' => 902,
            'title' => 'All day fallback',
            'visit_date' => '2026-09-02',
        ]);
        $allDayPlan->forceFill(['id' => 901]);
        $allDayPlan->exists = true;
        $market = new NightMarket([
            'name' => 'Fallback Market',
            'address' => 'Jalan Fallback',
            'city' => 'Klang',
            'state' => 'Selangor',
        ]);
        $market->forceFill(['id' => 902]);
        $operatingDay = new MarketOperatingDay(['day_of_week' => 'Wednesday']);
        $market->setRelation('operatingDays', collect([$operatingDay]));
        $allDayPlan->setRelation('nightMarket', $market);
        $allDayPlan->setRelation('items', collect());
        $method = new ReflectionMethod(GoogleCalendarService::class, 'eventPayload');
        $method->setAccessible(true);
        /** @var array<string, mixed> $payload */
        $payload = $method->invoke(app(GoogleCalendarService::class), $allDayPlan, $this->client);

        $this->assertSame(['date' => '2026-09-02'], $payload['start']);
        $this->assertSame(['date' => '2026-09-03'], $payload['end']);
    }

    public function test_duplicate_sync_updates_the_existing_event_without_creating_another_mapping(): void
    {
        [$plan] = $this->planFor($this->client);
        $this->establishConnection($this->client);
        Http::fake(function (ClientRequest $request) {
            if ($request->method() === 'POST') {
                return Http::response([], 409);
            }

            return Http::response([
                'id' => 'calendar-event-id',
                'htmlLink' => 'https://calendar.google.com/calendar/event?eid=one',
            ], 200);
        });

        $this->actingAs($this->client)->post(route('client.visit-plans.google-calendar.sync', $plan));
        $this->actingAs($this->client)->post(route('client.visit-plans.google-calendar.sync', $plan));

        $this->assertDatabaseCount('google_calendar_events', 1);
        Http::assertSentCount(3);
    }

    public function test_existing_event_is_updated_and_can_be_removed_without_touching_the_visit_plan(): void
    {
        [$plan] = $this->planFor($this->client);
        $this->establishConnection($this->client);
        $event = $this->client->googleCalendarEvents()->create([
            'visit_plan_id' => $plan->id,
            'google_event_id' => 'nm0123456789abcdef',
            'google_event_url' => 'https://calendar.google.com/calendar/event?eid=existing',
            'payload_hash' => 'old-hash',
            'last_synced_at' => now()->subDay(),
        ]);
        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
                'id' => $event->google_event_id,
                'htmlLink' => 'https://calendar.google.com/calendar/event?eid=updated',
            ]),
        ]);

        $this->actingAs($this->client)
            ->post(route('client.visit-plans.google-calendar.sync', $plan))
            ->assertSessionHas('status', 'Your Google Calendar event was updated.');
        $this->assertDatabaseHas('google_calendar_events', [
            'id' => $event->id,
            'google_event_url' => 'https://calendar.google.com/calendar/event?eid=updated',
        ]);
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'PATCH');

        $this->actingAs($this->client)
            ->delete(route('client.visit-plans.google-calendar.destroy', $plan))
            ->assertSessionHas('status', 'The Google Calendar event was removed. Your visit plan remains available here.');
        $this->assertDatabaseMissing('google_calendar_events', ['id' => $event->id]);
        $this->assertDatabaseHas('visit_plans', ['id' => $plan->id]);
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'DELETE');
    }

    public function test_expired_tokens_are_refreshed_once_and_revoked_tokens_disconnect_safely(): void
    {
        [$plan] = $this->planFor($this->client);
        $this->establishConnection($this->client, now()->subMinute());
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::sequence()
                ->push([
                    'access_token' => 'refreshed-calendar-token',
                    'expires_in' => 3600,
                ])
                ->push(['error' => 'invalid_grant'], 400),
            'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response(['id' => 'refreshed-event'], 201),
        ]);

        $this->actingAs($this->client)
            ->post(route('client.visit-plans.google-calendar.sync', $plan))
            ->assertSessionHas('status', 'Your Google Calendar event was updated.');
        $this->assertSame('refreshed-calendar-token', $this->client->googleCalendarConnection()->firstOrFail()->access_token);

        [$revokedPlan] = $this->planFor($this->client, visitDate: now()->addDays(7));
        $connection = $this->client->googleCalendarConnection()->firstOrFail();
        $connection->update(['token_expires_at' => now()->subMinute()]);

        $response = $this->actingAs($this->client)
            ->post(route('client.visit-plans.google-calendar.sync', $revokedPlan));
        $response
            ->assertRedirect(route('client.visit-plans.show', $revokedPlan))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'expired') && ! str_contains($message, 'calendar-refresh-token'));
        $this->assertDatabaseCount('google_calendar_connections', 0);
    }

    public function test_api_failures_and_past_plans_leave_local_visit_plans_safe(): void
    {
        [$pastPlan] = $this->planFor($this->client, visitDate: now()->subDay());
        $this->actingAs($this->client)
            ->post(route('client.visit-plans.google-calendar.sync', $pastPlan))
            ->assertSessionHas('error', 'Past visit plans cannot be added to Google Calendar.');

        [$plan] = $this->planFor($this->client, visitDate: now()->addDays(8));
        $this->establishConnection($this->client);
        foreach ([
            Http::response(['error' => ['message' => 'not for users']], 403),
            Http::response(['error' => ['message' => 'outage']], 503),
            Http::failedConnection('sensitive transport detail'),
        ] as $failure) {
            Http::fake([
                'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => $failure,
            ]);

            $this->actingAs($this->client)
                ->post(route('client.visit-plans.google-calendar.sync', $plan))
                ->assertRedirect(route('client.visit-plans.show', $plan))
                ->assertSessionHas('error', function (string $message): bool {
                    return ! str_contains($message, 'sensitive transport detail')
                        && ! str_contains($message, 'not for users')
                        && ! str_contains($message, 'outage');
                });
            $this->assertDatabaseCount('google_calendar_events', 0);
        }

        $this->assertDatabaseHas('visit_plans', ['id' => $pastPlan->id]);
        $this->assertDatabaseHas('visit_plans', ['id' => $plan->id]);
    }

    /** @return array{0: VisitPlan, 1: NightMarket} */
    private function planFor(
        User $user,
        string $openingTime = '18:00',
        string $closingTime = '22:00',
        ?string $notes = null,
        ?Carbon $visitDate = null,
    ): array {
        $visitDate ??= now()->addDays(3);
        $this->marketSequence++;
        $market = NightMarket::factory()->create([
            'name' => 'Calendar Test Market'.($this->marketSequence === 1 ? '' : ' '.$this->marketSequence),
            'address' => '12 Jalan Test',
            'city' => 'Shah Alam',
            'state' => 'Selangor',
        ]);
        MarketOperatingDay::factory()->create([
            'night_market_id' => $market->id,
            'day_of_week' => $visitDate->englishDayOfWeek,
            'opening_time' => $openingTime,
            'closing_time' => $closingTime,
        ]);

        return [
            VisitPlan::factory()->create([
                'user_id' => $user->id,
                'night_market_id' => $market->id,
                'title' => 'Calendar dinner plan',
                'visit_date' => $visitDate->toDateString(),
                'notes' => $notes,
            ]),
            $market,
        ];
    }

    private function startOauth(VisitPlan $plan): string
    {
        $response = $this->actingAs($this->client)
            ->get(route('client.visit-plans.google-calendar.connect', $plan))
            ->assertRedirect();
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

        return $query['state'];
    }

    private function establishConnection(User $user, ?Carbon $expiresAt = null): void
    {
        $user->googleCalendarConnection()->create([
            'access_token' => 'calendar-access-token',
            'refresh_token' => 'calendar-refresh-token',
            'token_expires_at' => $expiresAt ?? now()->addHour(),
            'scopes' => [GoogleCalendarOAuthService::SCOPE_EVENTS_OWNED],
            'connected_at' => now(),
        ]);
    }

    private function fakeOAuthAndCalendar(mixed $calendarResponse = null): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'calendar-access-token',
                'refresh_token' => 'calendar-refresh-token',
                'expires_in' => 3600,
                'scope' => GoogleCalendarOAuthService::SCOPE_EVENTS_OWNED,
                'token_type' => 'Bearer',
            ]),
            'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => $calendarResponse ?? Http::response([
                'id' => 'calendar-event-id',
                'htmlLink' => 'https://calendar.google.com/calendar/event?eid=calendar-event',
            ], 201),
        ]);
    }
}
