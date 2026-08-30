<?php

namespace App\Services;

use App\Exceptions\GoogleCalendarIntegrationException;
use App\Models\GoogleCalendarConnection;
use App\Models\GoogleCalendarEvent;
use App\Models\MarketOperatingDay;
use App\Models\User;
use App\Models\VisitPlan;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class GoogleCalendarService
{
    private const CALENDAR_EVENTS_ENDPOINT = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';

    private const TIMEZONE = 'Asia/Kuala_Lumpur';

    public function __construct(private readonly GoogleCalendarOAuthService $oauthService) {}

    public function planForClient(User $user, int $visitPlanId): VisitPlan
    {
        return VisitPlan::query()
            ->where('user_id', $user->id)
            ->with('googleCalendarEvent')
            ->findOrFail($visitPlanId);
    }

    /** @return array{connected: bool, event: GoogleCalendarEvent|null, needs_sync: bool, can_create: bool} */
    public function integrationDetailsForClient(User $user, VisitPlan $visitPlan): array
    {
        $plan = $this->calendarPlanForClient($user, $visitPlan->id);
        $event = $plan->googleCalendarEvent;
        $payloadHash = $this->payloadHash($this->eventPayload($plan, $user));

        return [
            'connected' => $user->googleCalendarConnection()->exists(),
            'event' => $event,
            'needs_sync' => $event !== null && ! hash_equals((string) $event->payload_hash, $payloadHash),
            'can_create' => ! $this->isPastPlan($plan),
        ];
    }

    public function assertCanStartConnection(User $user, int $visitPlanId): VisitPlan
    {
        $this->oauthService->assertEligibleClient($user);
        $plan = $this->planForClient($user, $visitPlanId);

        if ($this->isPastPlan($plan) && $plan->googleCalendarEvent === null) {
            throw new GoogleCalendarIntegrationException('Past visit plans cannot be added to Google Calendar.');
        }

        return $plan;
    }

    public function syncForClient(User $user, int $visitPlanId): GoogleCalendarEvent
    {
        $this->oauthService->assertEligibleClient($user);

        try {
            return DB::transaction(function () use ($user, $visitPlanId): GoogleCalendarEvent {
                $plan = $this->calendarPlanForClient($user, $visitPlanId, true);
                $event = GoogleCalendarEvent::query()
                    ->where('user_id', $user->id)
                    ->where('visit_plan_id', $plan->id)
                    ->lockForUpdate()
                    ->first();

                if ($this->isPastPlan($plan) && $event === null) {
                    throw new GoogleCalendarIntegrationException('Past visit plans cannot be added to Google Calendar.');
                }

                $connection = GoogleCalendarConnection::query()
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->first();

                if (! $connection) {
                    throw new GoogleCalendarIntegrationException('Connect your Google Calendar before adding this visit plan.');
                }

                $payload = $this->eventPayload($plan, $user);
                $payloadHash = $this->payloadHash($payload);

                if ($event) {
                    $response = $this->updateRemoteEvent($connection, $event->google_event_id, $payload);
                    $event->update([
                        'google_event_url' => $this->eventUrl($response) ?? $event->google_event_url,
                        'payload_hash' => $payloadHash,
                        'last_synced_at' => now(),
                    ]);

                    return $event->refresh();
                }

                $eventId = $this->stableEventId($user, $plan);
                $response = $this->createRemoteEvent($connection, $eventId, $payload);

                return $user->googleCalendarEvents()->create([
                    'visit_plan_id' => $plan->id,
                    'google_event_id' => $eventId,
                    'google_event_url' => $this->eventUrl($response),
                    'payload_hash' => $payloadHash,
                    'last_synced_at' => now(),
                ]);
            }, 3);
        } catch (GoogleCalendarIntegrationException $exception) {
            $this->disconnectWhenRequired($user, $exception);

            throw $exception;
        }
    }

    public function removeForClient(User $user, int $visitPlanId): void
    {
        $this->oauthService->assertEligibleClient($user);

        try {
            DB::transaction(function () use ($user, $visitPlanId): void {
                $plan = $this->calendarPlanForClient($user, $visitPlanId, true);
                $event = GoogleCalendarEvent::query()
                    ->where('user_id', $user->id)
                    ->where('visit_plan_id', $plan->id)
                    ->lockForUpdate()
                    ->first();

                if (! $event) {
                    throw new GoogleCalendarIntegrationException('No Google Calendar event is connected to this visit plan.');
                }

                $connection = GoogleCalendarConnection::query()
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->first();

                if (! $connection) {
                    throw new GoogleCalendarIntegrationException('Reconnect your Google Calendar before removing this event.');
                }

                $this->deleteRemoteEvent($connection, $event->google_event_id);
                $event->delete();
            }, 3);
        } catch (GoogleCalendarIntegrationException $exception) {
            $this->disconnectWhenRequired($user, $exception);

            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function eventPayload(VisitPlan $plan, User $user): array
    {
        $market = $plan->nightMarket;
        $location = implode(', ', array_filter([
            $this->plainText($market?->address),
            $this->plainText($market?->city),
            $this->plainText($market?->state),
        ]));
        $selectedStalls = $plan->items
            ->where('item_type', 'stall')
            ->map(fn ($item) => $this->plainText($item->item_name))
            ->filter()
            ->values();
        $selectedFoods = $plan->items
            ->where('item_type', 'food')
            ->map(fn ($item) => $this->plainText($item->item_name))
            ->filter()
            ->values();
        $description = [
            'Visit Plan: '.$this->plainText($plan->title),
            '',
            'Notes:',
            $this->plainText($plan->notes) ?: 'No notes added.',
            '',
            'Selected Stalls:',
            $selectedStalls->isEmpty() ? '- None selected' : $selectedStalls->map(fn (string $name) => '- '.$name)->implode("\n"),
            '',
            'Selected Foods:',
            $selectedFoods->isEmpty() ? '- None selected' : $selectedFoods->map(fn (string $name) => '- '.$name)->implode("\n"),
            '',
            'View this plan: '.$this->httpsPlanUrl($plan),
        ];
        $payload = [
            'summary' => 'Night Market Visit — '.$this->plainText($market?->name ?: 'Night Market'),
            'description' => implode("\n", $description),
            'extendedProperties' => [
                'private' => [
                    'night_market_visit_plan_id' => (string) $plan->id,
                    'night_market_user_id' => (string) $user->id,
                ],
            ],
        ];

        if ($location !== '') {
            $payload['location'] = $location;
        }

        $operatingDay = $plan->nightMarket?->operatingDays
            ->firstWhere('day_of_week', $plan->visit_date->englishDayOfWeek);

        if ($operatingDay instanceof MarketOperatingDay
            && $operatingDay->opening_time !== null
            && $operatingDay->closing_time !== null) {
            $start = Carbon::parse($plan->visit_date->toDateString(), self::TIMEZONE)
                ->setTimeFromTimeString($operatingDay->opening_time->format('H:i:s'));
            $end = Carbon::parse($plan->visit_date->toDateString(), self::TIMEZONE)
                ->setTimeFromTimeString($operatingDay->closing_time->format('H:i:s'));

            if ($end->lte($start)) {
                $end->addDay();
            }

            $payload['start'] = [
                'dateTime' => $start->toRfc3339String(),
                'timeZone' => self::TIMEZONE,
            ];
            $payload['end'] = [
                'dateTime' => $end->toRfc3339String(),
                'timeZone' => self::TIMEZONE,
            ];
        } else {
            $payload['start'] = ['date' => $plan->visit_date->toDateString()];
            $payload['end'] = ['date' => $plan->visit_date->copy()->addDay()->toDateString()];
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function createRemoteEvent(GoogleCalendarConnection $connection, string $eventId, array $payload): array
    {
        $payload['id'] = $eventId;
        $response = $this->calendarRequest($connection, 'POST', self::CALENDAR_EVENTS_ENDPOINT.'?sendUpdates=none', $payload);

        if ($response->status() === 409) {
            return $this->updateRemoteEvent($connection, $eventId, $payload);
        }

        return $this->successfulPayload($response);
    }

    /** @return array<string, mixed> */
    private function updateRemoteEvent(GoogleCalendarConnection $connection, string $eventId, array $payload): array
    {
        return $this->successfulPayload($this->calendarRequest(
            $connection,
            'PATCH',
            self::CALENDAR_EVENTS_ENDPOINT.'/'.rawurlencode($eventId).'?sendUpdates=none',
            $payload,
        ));
    }

    private function deleteRemoteEvent(GoogleCalendarConnection $connection, string $eventId): void
    {
        $response = $this->calendarRequest(
            $connection,
            'DELETE',
            self::CALENDAR_EVENTS_ENDPOINT.'/'.rawurlencode($eventId).'?sendUpdates=none',
        );

        if (! $response->successful()) {
            throw $this->safeApiFailure($response);
        }
    }

    private function calendarRequest(
        GoogleCalendarConnection $connection,
        string $method,
        string $url,
        ?array $payload = null,
    ): Response {
        if ($connection->token_expires_at === null || $connection->token_expires_at->lte(now()->addSeconds(30))) {
            $this->oauthService->refreshAccessToken($connection);
            $connection->refresh();
        }

        $response = $this->sendCalendarRequest($connection, $method, $url, $payload);

        if ($response->status() !== 401) {
            return $response;
        }

        $this->oauthService->refreshAccessToken($connection);

        $retry = $this->sendCalendarRequest($connection->refresh(), $method, $url, $payload);
        if ($retry->status() === 401) {
            throw new GoogleCalendarIntegrationException('Your Google Calendar connection has expired. Please reconnect it before syncing this plan.', true);
        }

        return $retry;
    }

    private function sendCalendarRequest(
        GoogleCalendarConnection $connection,
        string $method,
        string $url,
        ?array $payload,
    ): Response {
        try {
            return Http::acceptJson()
                ->withToken((string) $connection->access_token)
                ->timeout(10)
                ->send($method, $url, $payload === null ? [] : ['json' => $payload]);
        } catch (ConnectionException) {
            throw new GoogleCalendarIntegrationException('Google Calendar is temporarily unavailable. Please try again later.');
        } catch (Throwable) {
            throw new GoogleCalendarIntegrationException('Google Calendar could not complete this action. Please try again later.');
        }
    }

    /** @return array<string, mixed> */
    private function successfulPayload(Response $response): array
    {
        if (! $response->successful()) {
            throw $this->safeApiFailure($response);
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    private function safeApiFailure(Response $response): GoogleCalendarIntegrationException
    {
        return match (true) {
            $response->status() === 429 => new GoogleCalendarIntegrationException('Google Calendar is rate limiting requests. Please try again shortly.'),
            $response->status() >= 500 => new GoogleCalendarIntegrationException('Google Calendar is temporarily unavailable. Please try again later.'),
            default => new GoogleCalendarIntegrationException('Google Calendar could not complete this action. Please reconnect and try again.'),
        };
    }

    private function calendarPlanForClient(User $user, int $visitPlanId, bool $lock = false): VisitPlan
    {
        $query = VisitPlan::query()
            ->where('user_id', $user->id)
            ->with([
                'googleCalendarEvent',
                'nightMarket:id,name,address,city,state',
                'nightMarket.operatingDays:id,night_market_id,day_of_week,opening_time,closing_time',
                'items:id,visit_plan_id,item_type,item_name,sort_order',
            ]);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->findOrFail($visitPlanId);
    }

    private function stableEventId(User $user, VisitPlan $plan): string
    {
        return 'nm'.substr(hash('sha256', 'night-market-calendar|'.$user->id.'|'.$plan->id), 0, 48);
    }

    /** @param array<string, mixed> $payload */
    private function payloadHash(array $payload): string
    {
        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $response */
    private function eventUrl(array $response): ?string
    {
        $url = $response['htmlLink'] ?? null;

        return is_string($url) && str_starts_with($url, 'https://') ? $url : null;
    }

    private function httpsPlanUrl(VisitPlan $plan): string
    {
        return Str::replaceStart('http://', 'https://', route('client.visit-plans.show', $plan));
    }

    private function isPastPlan(VisitPlan $plan): bool
    {
        return $plan->visit_date->lt(now()->startOfDay());
    }

    private function plainText(?string $value): string
    {
        $value = strip_tags((string) $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $value) ?? '';

        return trim($value);
    }

    private function disconnectWhenRequired(User $user, GoogleCalendarIntegrationException $exception): void
    {
        if ($exception->disconnect) {
            GoogleCalendarConnection::query()->where('user_id', $user->id)->delete();
        }
    }
}
