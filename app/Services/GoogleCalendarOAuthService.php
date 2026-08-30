<?php

namespace App\Services;

use App\Exceptions\GoogleCalendarIntegrationException;
use App\Models\GoogleCalendarConnection;
use App\Models\User;
use App\Models\VisitPlan;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $config = $this->calendarConfig();

        $state = Str::random(64);
        $request->session()->put(self::SESSION_INTENT, [
            'state' => $state,
            'user_id' => $user->id,
            'visit_plan_id' => $visitPlan->id,
            'expires_at' => now()->addMinutes(self::INTENT_LIFETIME_MINUTES)->toIso8601String(),
        ]);

        return redirect()->away(self::AUTHORIZE_ENDPOINT.'?'.http_build_query([
            'client_id' => $config['client_id'],
            'redirect_uri' => $config['redirect'],
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
        // pull() deliberately invalidates the state before any token request, so a
        // callback URL cannot be replayed even if Google redirects a second time.
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
            throw new GoogleCalendarIntegrationException(
                'The Google Calendar connection session is invalid or expired. Please try again from your visit plan.',
                GoogleCalendarIntegrationException::REASON_OAUTH_STATE_INVALID,
            );
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
        $config = $this->calendarConfig();

        try {
            // Google requires application/x-www-form-urlencoded at this endpoint.
            // The redirect URI is deliberately taken from the same config value as
            // the authorization request above.
            $response = Http::acceptJson()->asForm()->timeout(10)->post(self::TOKEN_ENDPOINT, [
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'code' => $authorizationCode,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $config['redirect'],
            ]);
        } catch (Throwable) {
            throw new GoogleCalendarIntegrationException(
                'Google Calendar is temporarily unavailable. Please try again later.',
                GoogleCalendarIntegrationException::REASON_TOKEN_EXCHANGE_FAILED,
            );
        }

        if (! $response->successful()) {
            throw $this->tokenExchangeFailure($response);
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw $this->invalidTokenResponse();
        }

        $accessToken = $payload['access_token'] ?? null;
        $expiresIn = $payload['expires_in'] ?? null;
        $tokenType = $payload['token_type'] ?? null;

        if (! is_string($accessToken) || $accessToken === ''
            || ! is_numeric($expiresIn) || (int) $expiresIn <= 0
            || ! is_string($tokenType) || strcasecmp($tokenType, 'Bearer') !== 0) {
            throw $this->invalidTokenResponse();
        }

        $refreshToken = is_string($payload['refresh_token'] ?? null) && $payload['refresh_token'] !== ''
            ? $payload['refresh_token']
            : null;
        $scopes = $this->scopesFromPayload($payload) ?? [self::SCOPE_EVENTS_OWNED];

        try {
            return DB::transaction(function () use ($user, $accessToken, $refreshToken, $expiresIn, $scopes): GoogleCalendarConnection {
                $connection = GoogleCalendarConnection::query()
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->first();

                if (! $connection) {
                    $connection = new GoogleCalendarConnection;
                    $connection->user()->associate($user);
                }

                // Google only returns a refresh token on some consent responses. A
                // missing value is valid and must not break Laravel's encrypted cast.
                $connection->fill([
                    'access_token' => $accessToken,
                    'refresh_token' => $refreshToken ?? $connection->refresh_token,
                    'token_expires_at' => now()->addSeconds((int) $expiresIn),
                    'scopes' => $scopes,
                    'connected_at' => now(),
                ]);
                $connection->save();

                return $connection->refresh();
            }, 3);
        } catch (GoogleCalendarIntegrationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new GoogleCalendarIntegrationException(
                'Google Calendar could not complete the connection. Please try again.',
                GoogleCalendarIntegrationException::REASON_CONNECTION_PERSISTENCE_FAILED,
            );
        }
    }

    public function refreshAccessToken(GoogleCalendarConnection $connection): void
    {
        $config = $this->calendarConfig();
        $refreshToken = $connection->refresh_token;

        if (! is_string($refreshToken) || $refreshToken === '') {
            throw new GoogleCalendarIntegrationException(
                'Your Google Calendar connection needs to be reconnected.',
                GoogleCalendarIntegrationException::REASON_TOKEN_REFRESH_FAILED,
                true,
            );
        }

        try {
            $response = Http::acceptJson()->asForm()->timeout(10)->post(self::TOKEN_ENDPOINT, [
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ]);
        } catch (Throwable) {
            throw new GoogleCalendarIntegrationException(
                'Google Calendar is temporarily unavailable. Please try again later.',
                GoogleCalendarIntegrationException::REASON_TOKEN_REFRESH_FAILED,
            );
        }

        $payload = $response->json();
        $accessToken = is_array($payload) ? ($payload['access_token'] ?? null) : null;
        $expiresIn = is_array($payload) ? ($payload['expires_in'] ?? null) : null;

        if (! $response->successful() || ! is_string($accessToken) || $accessToken === ''
            || ! is_numeric($expiresIn) || (int) $expiresIn <= 0) {
            if (is_array($payload) && ($payload['error'] ?? null) === 'invalid_grant') {
                throw new GoogleCalendarIntegrationException(
                    'Your Google Calendar connection has expired. Please reconnect it before syncing this plan.',
                    GoogleCalendarIntegrationException::REASON_TOKEN_REFRESH_FAILED,
                    true,
                    $response->status(),
                );
            }

            throw new GoogleCalendarIntegrationException(
                'Google Calendar could not refresh the connection. Please try again later.',
                GoogleCalendarIntegrationException::REASON_TOKEN_REFRESH_FAILED,
                false,
                $response->status(),
            );
        }

        $connection->update([
            'access_token' => $accessToken,
            'refresh_token' => is_string($payload['refresh_token'] ?? null) && $payload['refresh_token'] !== ''
                ? $payload['refresh_token']
                : $refreshToken,
            'token_expires_at' => now()->addSeconds((int) $expiresIn),
            'scopes' => $this->scopesFromPayload($payload) ?? $connection->scopes,
        ]);
    }

    public function assertEligibleClient(User $user): void
    {
        if ($user->role !== User::ROLE_CLIENT || ! $user->is_active || ! $user->hasVerifiedEmail()) {
            throw new GoogleCalendarIntegrationException(
                'Google Calendar is available only to active, verified Client accounts.',
                GoogleCalendarIntegrationException::REASON_OAUTH_STATE_INVALID,
            );
        }
    }

    /** @return array{client_id: string, client_secret: string, redirect: string} */
    private function calendarConfig(): array
    {
        $config = [
            'client_id' => trim((string) config('services.google_calendar.client_id')),
            'client_secret' => trim((string) config('services.google_calendar.client_secret')),
            'redirect' => trim((string) config('services.google_calendar.redirect')),
        ];

        if (in_array('', $config, true)) {
            throw new GoogleCalendarIntegrationException(
                'Google Calendar is not configured yet. Please contact an administrator.',
                GoogleCalendarIntegrationException::REASON_CONFIG_MISSING,
            );
        }

        return $config;
    }

    private function tokenExchangeFailure(Response $response): GoogleCalendarIntegrationException
    {
        $error = $response->json('error');

        return match ($error) {
            'invalid_client' => new GoogleCalendarIntegrationException(
                'Google Calendar could not complete the connection. Please try again.',
                GoogleCalendarIntegrationException::REASON_TOKEN_EXCHANGE_INVALID_CLIENT,
                false,
                $response->status(),
            ),
            'redirect_uri_mismatch' => new GoogleCalendarIntegrationException(
                'Google Calendar could not complete the connection. Please try again.',
                GoogleCalendarIntegrationException::REASON_TOKEN_EXCHANGE_REDIRECT_MISMATCH,
                false,
                $response->status(),
            ),
            default => new GoogleCalendarIntegrationException(
                'Google Calendar could not complete the connection. Please try again.',
                GoogleCalendarIntegrationException::REASON_TOKEN_EXCHANGE_FAILED,
                false,
                $response->status(),
            ),
        };
    }

    private function invalidTokenResponse(): GoogleCalendarIntegrationException
    {
        return new GoogleCalendarIntegrationException(
            'Google Calendar could not complete the connection. Please try again.',
            GoogleCalendarIntegrationException::REASON_TOKEN_RESPONSE_INVALID,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>|null
     */
    private function scopesFromPayload(array $payload): ?array
    {
        if (! is_string($payload['scope'] ?? null)) {
            return null;
        }

        $scopes = preg_split('/\s+/', trim($payload['scope'])) ?: [];
        $scopes = array_values(array_filter($scopes, fn (string $scope): bool => $scope !== ''));

        return $scopes === [] ? null : $scopes;
    }
}
