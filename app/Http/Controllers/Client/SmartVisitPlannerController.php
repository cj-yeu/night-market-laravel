<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\VisitPlan\SmartPlannerCreatePlanRequest;
use App\Http\Requests\VisitPlan\SmartPlannerRecommendationRequest;
use App\Http\Requests\VisitPlan\SmartPlannerTemplateRequest;
use App\Services\SmartVisitPlannerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SmartVisitPlannerController extends Controller
{
    public function __construct(private readonly SmartVisitPlannerService $smartPlannerService) {}

    public function index(SmartPlannerTemplateRequest $request): View
    {
        return $this->plannerView($this->smartPlannerService->templateDefaults(
            $request->validated('template'),
            $this->smartPlannerService->defaultVisitDate(),
        ));
    }

    public function recommend(SmartPlannerRecommendationRequest $request): View
    {
        $preferences = $this->smartPlannerService->normaliseTemplatePreferences($request->validated());

        return $this->plannerView($preferences, $this->smartPlannerService->recommendDateAware($preferences));
    }

    public function store(SmartPlannerCreatePlanRequest $request): RedirectResponse
    {
        $visitPlan = $this->smartPlannerService->createPlanForClient($request->user(), $request->validated());

        return redirect()
            ->route('client.visit-plans.show', $visitPlan)
            ->with('status', 'Your recommended visit plan was created successfully.');
    }

    /** @param array<string, mixed>|null $preferences @param array<string, mixed>|null $plannerResult */
    private function plannerView(?array $preferences = null, ?array $plannerResult = null): View
    {
        return view('client.visit-plans.smart-planner', [
            ...$this->smartPlannerService->preferenceOptions(),
            'preferences' => $preferences,
            'plannerResult' => $plannerResult,
            'recommendations' => $plannerResult['recommendations'] ?? null,
        ]);
    }
}
