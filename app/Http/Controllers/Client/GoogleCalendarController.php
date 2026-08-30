<?php

namespace App\Http\Controllers\Client;

use App\Exceptions\GoogleCalendarIntegrationException;
use App\Http\Controllers\Controller;
use App\Services\GoogleCalendarOAuthService;
use App\Services\GoogleCalendarService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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

            if ($request->query('error') !== null) {
                return redirect()
                    ->route('client.visit-plans.show', $visitPlanId)
                    ->with('error', 'Google Calendar access was not granted. Your visit plan was not changed.');
            }

            $code = $request->query('code');
            if (! is_string($code) || $code === '') {
                throw new GoogleCalendarIntegrationException('Google Calendar did not return a valid authorization response. Please try again.');
            }

            $this->oauthService->exchangeAuthorizationCode($request->user(), $code);
            $this->googleCalendarService->syncForClient($request->user(), $visitPlanId);

            return redirect()
                ->route('client.visit-plans.show', $visitPlanId)
                ->with('status', 'Your visit plan was added to Google Calendar.');
        } catch (GoogleCalendarIntegrationException $exception) {
            return redirect()
                ->route($visitPlanId === null ? 'client.visit-plans.index' : 'client.visit-plans.show', $visitPlanId)
                ->with('error', $exception->getMessage());
        }
    }

    public function sync(Request $request, int $visitPlan): RedirectResponse
    {
        try {
            $this->googleCalendarService->syncForClient($request->user(), $visitPlan);

            return redirect()
                ->route('client.visit-plans.show', $visitPlan)
                ->with('status', 'Your Google Calendar event was updated.');
        } catch (GoogleCalendarIntegrationException $exception) {
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
            return redirect()
                ->route('client.visit-plans.show', $visitPlan)
                ->with('error', $exception->getMessage());
        }
    }
}
