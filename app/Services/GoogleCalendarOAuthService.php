<?php

namespace App\Services;

use App\Exceptions\GoogleCalendarIntegrationException;
use App\Models\GoogleCalendarConnection;
use App\Models\User;
use App\Models\VisitPlan;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class GoogleCalendarOAuthService
{
    public const SESSION_INTENT = 'google_calendar_oauth_intent';

    public const SCOPE_EVENTS_OWNED = 'https://www.googleapis.com/auth/calendar.events.owned';

    private const AUTHORIZE_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    private const INTENT_LIFETIME_MINUTES = 10;

    public function beginConnection(Request $request, User $user, VisitPlan $visitPlan): RedirectResponse
    {
        $this->assertEligibleClient($user);
        $this->assertConfigured();

        $state = Str::random(64);
        $request->session()->put(self::SESSION_INTENT, [
            'state' => $state,
            'user_id' => $user->id,
            'visit_plan_id' => $visitPlan->id,
            'expires_at' => now()->addMinutes(self::INTENT_LIFETIME_MINUTES)->toIso8601String(),
        ]);

        return redirect()->away(self::AUTHORIZE_ENDPOINT.'?'.http_build_query([
            'client_id' => config('services.google_calendar.client_id'),
            'redirect_uri' => config('services.google_calendar.redirect'),
            'response_type' => 'code',
            'scope' => self::SCOPE_EVENTS_OWNED,
            'access_type' => 'offline',
            'include_granted_scopes' => 'true',
            'prompt' => 'consent',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986));
    }

    /** @return array{user_id: int, visit_plan_id: int} */
    public function consumeIntent(Request $request, User $user): array
    {
        $intent = $request->session()->pull(self::SESSION_INTENT);
        $state = $request->query('state');

        if (! is_array($intent)
            || ! is_string($state)
            || ! is_string($intent['state'] ?? null)
            || ! hash_equals($intent['state'], $state)
            || ! is_numeric($intent['user_id'] ?? null)
            || ! is_numeric($intent['visit_plan_id'] ?? null)
            || ! is_string($intent['expires_at'] ?? null)
            || Carbon::parse($intent['expires_at'])->lte(now())
            || (int) $intent['user_id'] !== $user->id) {
            throw new GoogleCalendarIntegrationException('The Google Calendar connection session is invalid or expired. Please try again from your visit plan.');
        }

        $this->assertEligibleClient($user);

        return [
            'user_id' => (int) $intent['user_id'],
            'visit_plan_id' => (int) $intent['visit_plan_id'],
        ];
    }

    public function exchangeAuthorizationCode(User $user, string $authorizationCode): GoogleCalendarConnection
    {
        $this->assertEligibleClient($user);
        $this->assertConfigured();

        try {
            $response = Http::asForm()->timeout(10)->post(self::TOKEN_ENDPOINT, [
                'code' => $authorizationCode,
                'client_id' => config('services.google_calendar.client_id'),
                'client_secret' => config('services.google_calendar.client_secret'),
                'redirect_uri' => config('services.google_calendar.redirect'),
                'grant_type' => 'authorization_code',
            ]);
        } catch (Throwable) {
            throw new GoogleCalendarIntegrationException('Google Calendar is temporarily unavailable. Please try again later.');
        }

        $payload = $response->json();
        $accessToken = is_array($payload) ? ($payload['access_token'] ?? null) : null;
        $refreshToken = is_array($payload) ? ($payload['refresh_token'] ?? null) : null;

        if (! $response->successful() || ! is_string($accessToken) || $accessToken === '') {
            throw new GoogleCalendarIntegrationException('Google Calendar could not complete the connection. Please try again.');
        }

        $existingConnection = $user->googleCalendarConnection()->first();
        $refreshToken = is_string($refreshToken) && $refreshToken !== ''
            ? $refreshToken
            : $existingConnection?->refresh_token;

        if (! is_string($refreshToken) || $refreshToken === '') {
            throw new GoogleCalendarIntegrationException('Google did not provide offline Calendar access. Please reconnect and approve Calendar access again.');
        }

        $expiresIn = is_array($payload) && is_numeric($payload['expires_in'] ?? null)
            ? max(0, (int) $payload['expires_in'])
            : 0;
        $scopes = is_array($payload) && is_string($payload['scope'] ?? null)
            ? array_values(array_filter(explode(' ', $payload['scope'])))
            : [self::SCOPE_EVENTS_OWNED];

        return $user->googleCalendarConnection()->updateOrCreate([], [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_expires_at' => now()->addSeconds($expiresIn),
            'scopes' => $scopes,
            'connected_at' => now(),
        ]);
    }

    public function refreshAccessToken(GoogleCalendarConnection $connection): void
    {
        $this->assertConfigured();
        $refreshToken = $connection->refresh_token;

        if (! is_string($refreshToken) || $refreshToken === '') {
            throw new GoogleCalendarIntegrationException('Your Google Calendar connection needs to be reconnected.', true);
        }

        try {
            $response = Http::asForm()->timeout(10)->post(self::TOKEN_ENDPOINT, [
                'client_id' => config('services.google_calendar.client_id'),
                'client_secret' => config('services.google_calendar.client_secret'),
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ]);
        } catch (Throwable) {
            throw new GoogleCalendarIntegrationException('Google Calendar is temporarily unavailable. Please try again later.');
        }

        $payload = $response->json();
        $accessToken = is_array($payload) ? ($payload['access_token'] ?? null) : null;

        if (! $response->successful() || ! is_string($accessToken) || $accessToken === '') {
            if (is_array($payload) && ($payload['error'] ?? null) === 'invalid_grant') {
                throw new GoogleCalendarIntegrationException('Your Google Calendar connection has expired. Please reconnect it before syncing this plan.', true);
            }

            throw new GoogleCalendarIntegrationException('Google Calendar could not refresh the connection. Please try again later.');
        }

        $expiresIn = is_numeric($payload['expires_in'] ?? null) ? max(0, (int) $payload['expires_in']) : 0;
        $connection->update([
            'access_token' => $accessToken,
            'refresh_token' => is_string($payload['refresh_token'] ?? null) && $payload['refresh_token'] !== ''
                ? $payload['refresh_token']
                : $refreshToken,
            'token_expires_at' => now()->addSeconds($expiresIn),
            'scopes' => is_string($payload['scope'] ?? null)
                ? array_values(array_filter(explode(' ', $payload['scope'])))
                : $connection->scopes,
        ]);
    }

    public function assertEligibleClient(User $user): void
    {
        if ($user->role !== User::ROLE_CLIENT || ! $user->is_active || ! $user->hasVerifiedEmail()) {
            throw new GoogleCalendarIntegrationException('Google Calendar is available only to active, verified Client accounts.');
        }
    }

    private function assertConfigured(): void
    {
        foreach (['client_id', 'client_secret', 'redirect'] as $key) {
            if (! filled(config('services.google_calendar.'.$key))) {
                throw new GoogleCalendarIntegrationException('Google Calendar is not configured yet. Please contact an administrator.');
            }
        }
    }
}
