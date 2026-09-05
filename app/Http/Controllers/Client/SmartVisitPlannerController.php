<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\VisitPlan\ParsePlannerPreferencesRequest;
use App\Http\Requests\VisitPlan\PlannerSnapshotRequest;
use App\Http\Requests\VisitPlan\SavePlannerSnapshotRequest;
use App\Http\Requests\VisitPlan\SmartPlannerCreatePlanRequest;
use App\Http\Requests\VisitPlan\SmartPlannerRecommendationRequest;
use App\Http\Requests\VisitPlan\SmartPlannerTemplateRequest;
use App\Services\AiSmartPlannerService;
use App\Services\PlannerPreferenceParser;
use App\Services\SmartVisitPlannerService;
use App\Support\PlannerFoodInterests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SmartVisitPlannerController extends Controller
{
    public function __construct(private readonly SmartVisitPlannerService $smartPlannerService, private readonly AiSmartPlannerService $aiPlanner) {}

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
        if ($request->filled('recommendation_mode')) {
            $preferences = $this->aiPlanner->preparePreferences($preferences);

            return $this->plannerView($preferences, $this->aiPlanner->recommend($request->user(), $preferences));
        }

        return $this->plannerView($preferences, $this->smartPlannerService->recommendDateAware($preferences));
    }

    public function parse(ParsePlannerPreferencesRequest $request, PlannerPreferenceParser $parser): JsonResponse
    {
        $options = $this->smartPlannerService->preferenceOptions();

        return response()->json($parser->parse($request->user()->id, $request->validated('text'),
            $options['cities']->pluck('city')->all(), array_keys(PlannerFoodInterests::options($options['categories']->pluck('category')->all()))));
    }

    public function invalidate(PlannerSnapshotRequest $request): JsonResponse
    {
        $this->aiPlanner->invalidate($request->user(), $request->validated('snapshot_id'));

        return response()->json(['invalidated' => true]);
    }

    public function saveSnapshot(SavePlannerSnapshotRequest $request): RedirectResponse
    {
        $plan = $this->aiPlanner->save($request->user(), $request->validated());

        return redirect()->route('client.visit-plans.show', $plan)->with('status', 'Your visit plan was saved. You can edit it or add it to Google Calendar.');
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
        $options = $this->smartPlannerService->preferenceOptions();

        return view('client.visit-plans.smart-planner', [
            ...$options,
            'interestOptions' => PlannerFoodInterests::options($options['categories']->pluck('category')->all()),
            'preferences' => $preferences,
            'plannerResult' => $plannerResult,
            'recommendations' => $plannerResult['recommendations'] ?? null,
        ]);
    }
}
