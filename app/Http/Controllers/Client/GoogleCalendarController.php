<?php

namespace App\Http\Controllers\Client;

use App\Exceptions\GoogleCalendarIntegrationException;
use App\Http\Controllers\Controller;
use App\Services\GoogleCalendarOAuthService;
use App\Services\GoogleCalendarService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GoogleCalendarController extends Controller
{
    public function __construct(
        private readonly GoogleCalendarOAuthService $oauthService,
        private readonly GoogleCalendarService $googleCalendarService,
    ) {}

    public function connect(Request $request, int $visitPlan): RedirectResponse
    {
        try {
            $plan = $this->googleCalendarService->assertCanStartConnection($request->user(), $visitPlan);

            return $this->oauthService->beginConnection($request, $request->user(), $plan);
        } catch (GoogleCalendarIntegrationException $exception) {
            $this->logFailure('oauth_start', $request, $visitPlan, $exception);

            return redirect()
                ->route('client.visit-plans.show', $visitPlan)
                ->with('error', $exception->getMessage());
        }
    }

    public function callback(Request $request): RedirectResponse
    {
        $visitPlanId = null;

        try {
            $intent = $this->oauthService->consumeIntent($request, $request->user());
            $visitPlanId = $intent['visit_plan_id'];
            $this->googleCalendarService->planForClient($request->user(), $visitPlanId);
        } catch (GoogleCalendarIntegrationException $exception) {
            $this->logFailure('oauth_callback', $request, $visitPlanId, $exception);

            return $this->callbackRedirect($visitPlanId)->with('error', $exception->getMessage());
        }

        if ($request->query('error') !== null) {
            $this->logSafeEvent('oauth_callback', $request, $visitPlanId, GoogleCalendarIntegrationException::REASON_OAUTH_DENIED);

            return redirect()
                ->route('client.visit-plans.show', $visitPlanId)
                ->with('error', 'Google Calendar access was not granted. Your visit plan was not changed.');
        }

        $code = $request->query('code');
        if (! is_string($code) || $code === '') {
            $exception = new GoogleCalendarIntegrationException(
                'Google Calendar did not return a valid authorization response. Please try again.',
                GoogleCalendarIntegrationException::REASON_TOKEN_RESPONSE_INVALID,
            );
            $this->logFailure('token_exchange', $request, $visitPlanId, $exception);

            return $this->callbackRedirect($visitPlanId)->with('error', $exception->getMessage());
        }

        try {
            // This transaction is complete before any event request. An event
            // failure must never discard a valid encrypted Calendar connection.
            $this->oauthService->exchangeAuthorizationCode($request->user(), $code);
        } catch (GoogleCalendarIntegrationException $exception) {
            $this->logFailure('token_exchange', $request, $visitPlanId, $exception);

            return $this->callbackRedirect($visitPlanId)->with('error', $exception->getMessage());
        }

        try {
            $this->googleCalendarService->syncForClient($request->user(), $visitPlanId);
        } catch (GoogleCalendarIntegrationException $exception) {
            $this->logFailure('event_insert', $request, $visitPlanId, $exception);

            return $this->callbackRedirect($visitPlanId)->with(
                'error',
                $exception->disconnect
                    ? $exception->getMessage()
                    : 'Google Calendar was connected, but the visit event could not be added. Please try Add to Google Calendar again.',
            );
        }

        return redirect()
            ->route('client.visit-plans.show', $visitPlanId)
            ->with('status', 'Your visit plan was added to Google Calendar.');
    }

    public function sync(Request $request, int $visitPlan): RedirectResponse
    {
        try {
            $this->googleCalendarService->syncForClient($request->user(), $visitPlan);

            return redirect()
                ->route('client.visit-plans.show', $visitPlan)
                ->with('status', 'Your Google Calendar event was updated.');
        } catch (GoogleCalendarIntegrationException $exception) {
            $this->logFailure('event_sync', $request, $visitPlan, $exception);

            return redirect()
                ->route('client.visit-plans.show', $visitPlan)
                ->with('error', $exception->getMessage());
        }
    }

    public function destroy(Request $request, int $visitPlan): RedirectResponse
    {
        try {
            $this->googleCalendarService->removeForClient($request->user(), $visitPlan);

            return redirect()
                ->route('client.visit-plans.show', $visitPlan)
                ->with('status', 'The Google Calendar event was removed. Your visit plan remains available here.');
        } catch (GoogleCalendarIntegrationException $exception) {
            $this->logFailure('event_remove', $request, $visitPlan, $exception);

            return redirect()
                ->route('client.visit-plans.show', $visitPlan)
                ->with('error', $exception->getMessage());
        }
    }

    private function callbackRedirect(?int $visitPlanId): RedirectResponse
    {
        return redirect()->route($visitPlanId === null ? 'client.visit-plans.index' : 'client.visit-plans.show', $visitPlanId);
    }

    private function logFailure(
        string $operation,
        Request $request,
        ?int $visitPlanId,
        GoogleCalendarIntegrationException $exception,
    ): void {
        $context = [
            'operation' => $operation,
            'user_id' => $request->user()?->id,
            'visit_plan_id' => $visitPlanId,
            'safe_reason_code' => $exception->reasonCode,
            'exception_class' => $exception::class,
            'google_http_status' => $exception->googleHttpStatus,
        ];

        Log::warning('Google Calendar integration failed.', array_filter($context, fn (mixed $value): bool => $value !== null));
    }

    private function logSafeEvent(string $operation, Request $request, int $visitPlanId, string $reasonCode): void
    {
        Log::notice('Google Calendar integration was not completed.', [
            'operation' => $operation,
            'user_id' => $request->user()?->id,
            'visit_plan_id' => $visitPlanId,
            'safe_reason_code' => $reasonCode,
        ]);
    }
}
