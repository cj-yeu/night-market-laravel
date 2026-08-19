<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\VisitPlan\SmartPlannerCreatePlanRequest;
use App\Http\Requests\VisitPlan\SmartPlannerRecommendationRequest;
use App\Services\SmartVisitPlannerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SmartVisitPlannerController extends Controller
{
    public function __construct(private readonly SmartVisitPlannerService $smartPlannerService) {}

    public function index(): View
    {
        return $this->plannerView();
    }

    public function recommend(SmartPlannerRecommendationRequest $request): View
    {
        $preferences = $request->validated();

        return $this->plannerView($preferences, $this->smartPlannerService->recommend($preferences));
    }

    public function store(SmartPlannerCreatePlanRequest $request): RedirectResponse
    {
        $visitPlan = $this->smartPlannerService->createPlanForClient($request->user(), $request->validated());

        return redirect()
            ->route('client.visit-plans.show', $visitPlan)
            ->with('status', 'Your recommended visit plan was created successfully.');
    }

    /** @param array<string, mixed>|null $preferences @param list<array<string, mixed>>|null $recommendations */
    private function plannerView(?array $preferences = null, ?array $recommendations = null): View
    {
        return view('client.visit-plans.smart-planner', [
            ...$this->smartPlannerService->preferenceOptions(),
            'preferences' => $preferences,
            'recommendations' => $recommendations,
        ]);
    }
}
