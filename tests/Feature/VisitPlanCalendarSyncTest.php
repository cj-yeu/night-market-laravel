<?php

namespace Tests\Feature;

use App\Models\Food;
use App\Models\GoogleCalendarEvent;
use App\Models\MarketOperatingDay;
use App\Models\NightMarket;
use App\Models\Stall;
use App\Models\User;
use App\Models\VisitPlan;
use App\Services\GoogleCalendarOAuthService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VisitPlanCalendarSyncTest extends TestCase
{
    use DatabaseTransactions;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-04 10:00:00');
        $this->client = User::factory()->create(['role' => User::ROLE_CLIENT, 'is_active' => true]);
        $this->client->googleCalendarConnection()->create([
            'access_token' => 'test-calendar-access-token',
            'refresh_token' => 'test-calendar-refresh-token',
            'token_expires_at' => now()->addHour(),
            'scopes' => [GoogleCalendarOAuthService::SCOPE_EVENTS_OWNED],
            'connected_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_editing_a_synced_plan_patches_the_existing_google_event_then_item_changes_patch_it_again(): void
    {
        [$firstMarket, $firstDate] = $this->market('First Calendar Market', '18:00', '22:00', 4);
        [$secondMarket, $secondDate] = $this->market('Second Calendar Market', '19:00', '23:00', 5);
        $plan = VisitPlan::factory()->create([
            'user_id' => $this->client->id,
            'night_market_id' => $firstMarket->id,
            'title' => 'Original plan',
            'visit_date' => $firstDate->toDateString(),
            'notes' => 'Original notes',
        ]);
        $event = $this->client->googleCalendarEvents()->create([
            'visit_plan_id' => $plan->id,
            'google_event_id' => 'existing-calendar-event',
            'payload_hash' => 'stale-hash',
            'last_synced_at' => now()->subDay(),
        ]);
        $stall = Stall::factory()->create(['night_market_id' => $secondMarket->id, 'name' => 'Synced Stall']);
        $food = Food::factory()->create(['stall_id' => $stall->id, 'name' => 'Synced Food']);
        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
                'id' => $event->google_event_id,
                'htmlLink' => 'https://calendar.google.com/calendar/event?eid=existing',
            ]),
        ]);

        $this->actingAs($this->client)
            ->patch(route('client.visit-plans.update', $plan), [
                'title' => 'Updated calendar plan',
                'night_market_id' => $secondMarket->id,
                'visit_date' => $secondDate->toDateString(),
                'notes' => 'Updated notes',
            ])
            ->assertRedirect(route('client.visit-plans.show', $plan))
            ->assertSessionHas('status', 'Your visit plan was updated successfully. Your Google Calendar event was updated.');

        $this->assertSame($event->google_event_id, $event->fresh()->google_event_id);
        $this->assertDatabaseCount('google_calendar_events', 1);
        Http::assertSent(function (ClientRequest $request) use ($event): bool {
            $data = $request->data();

            return $request->method() === 'PATCH'
                && str_contains($request->url(), rawurlencode($event->google_event_id))
                && ($data['summary'] ?? null) === 'Updated calendar plan — Second Calendar Market'
                && str_contains((string) ($data['description'] ?? ''), 'Updated notes')
                && ($data['start']['dateTime'] ?? null) === '2026-09-09T19:00:00+08:00';
        });

        $this->actingAs($this->client)
            ->post(route('client.visit-plans.items.store', $plan), [
                'item_type' => 'stall',
                'stall_id' => $stall->id,
            ])
            ->assertSessionHas('status', 'The item was added to your visit plan. Your Google Calendar event was updated.');
        $this->actingAs($this->client)
            ->post(route('client.visit-plans.items.store', $plan), [
                'item_type' => 'food',
                'food_id' => $food->id,
            ])
            ->assertSessionHas('status', 'The item was added to your visit plan. Your Google Calendar event was updated.');
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'PATCH'
            && str_contains((string) ($request->data()['description'] ?? ''), 'Synced Stall')
            && str_contains((string) ($request->data()['description'] ?? ''), 'Synced Food'));

        $item = $plan->fresh()->items()->where('food_id', $food->id)->firstOrFail();
        $this->actingAs($this->client)
            ->delete(route('client.visit-plans.items.destroy', [$plan, $item]))
            ->assertSessionHas('status', 'The item was removed from your visit plan. Your Google Calendar event was updated.');
        $this->assertSame($event->google_event_id, $event->fresh()->google_event_id);
        $this->assertDatabaseCount('google_calendar_events', 1);
    }

    public function test_failed_automatic_calendar_refresh_keeps_local_edits_event_mapping_and_safe_failure_state(): void
    {
        [$market, $date] = $this->market('Failure Calendar Market', '18:00', '22:00', 4);
        $plan = VisitPlan::factory()->create([
            'user_id' => $this->client->id,
            'night_market_id' => $market->id,
            'visit_date' => $date->toDateString(),
        ]);
        $event = $this->client->googleCalendarEvents()->create([
            'visit_plan_id' => $plan->id,
            'google_event_id' => 'event-kept-after-failure',
            'payload_hash' => 'stale-hash',
            'last_synced_at' => now()->subDay(),
        ]);
        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response(['error' => ['message' => 'private detail']], 503),
        ]);

        $this->actingAs($this->client)
            ->patch(route('client.visit-plans.update', $plan), [
                'title' => 'Local edit survives',
                'night_market_id' => $market->id,
                'visit_date' => $date->toDateString(),
                'notes' => 'Saved locally',
            ])
            ->assertRedirect(route('client.visit-plans.show', $plan))
            ->assertSessionHas('warning', 'Your Visit Plan was updated, but Google Calendar could not be refreshed. Try again.');

        $this->assertDatabaseHas('visit_plans', ['id' => $plan->id, 'title' => 'Local edit survives', 'notes' => 'Saved locally']);
        $this->assertDatabaseHas('google_calendar_events', [
            'id' => $event->id,
            'google_event_id' => 'event-kept-after-failure',
            'sync_status' => GoogleCalendarEvent::SYNC_STATUS_FAILED,
            'last_sync_error_code' => 'event_insert_failed',
        ]);
        $this->assertDatabaseCount('google_calendar_events', 1);
    }

    /** @return array{0: NightMarket, 1: Carbon} */
    private function market(string $name, string $openingTime, string $closingTime, int $daysFromNow): array
    {
        $date = now()->addDays($daysFromNow);
        $market = NightMarket::factory()->create(['name' => $name, 'city' => 'Shah Alam', 'state' => 'Selangor']);
        MarketOperatingDay::factory()->create([
            'night_market_id' => $market->id,
            'day_of_week' => $date->englishDayOfWeek,
            'opening_time' => $openingTime,
            'closing_time' => $closingTime,
        ]);

        return [$market, $date];
    }
}
