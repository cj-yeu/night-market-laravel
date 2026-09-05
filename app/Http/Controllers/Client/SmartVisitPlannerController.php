<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\VisitPlan\ParsePlannerPreferencesRequest;
use App\Http\Requests\VisitPlan\PlannerSnapshotRequest;
use App\Http\Requests\VisitPlan\SavePlannerSnapshotRequest;
use App\Http\Requests\VisitPlan\ShowPlannerResultRequest;
use App\Http\Requests\VisitPlan\SmartPlannerCreatePlanRequest;
use App\Http\Requests\VisitPlan\SmartPlannerRecommendationRequest;
use App\Http\Requests\VisitPlan\SmartPlannerTemplateRequest;
use App\Services\AiSmartPlannerService;
use App\Services\PlannerPreferenceParser;
use App\Services\SmartVisitPlannerService;
use App\Support\PlannerFoodInterests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SmartVisitPlannerController extends Controller
{
    public function __construct(private readonly SmartVisitPlannerService $smartPlannerService, private readonly AiSmartPlannerService $aiPlanner) {}

    public function index(SmartPlannerTemplateRequest $request): View
    {
        $preferences = $request->filled('template') ? null : $this->aiPlanner->preferencesFor($request->user(), $request->validated('recommendation'));

        return $this->plannerView($preferences ?? $this->smartPlannerService->templateDefaults(
            $request->validated('template'),
            $this->smartPlannerService->defaultVisitDate(),
        ), currentResultId: $this->aiPlanner->currentResultId($request->user()));
    }

    public function recommend(SmartPlannerRecommendationRequest $request): View|RedirectResponse
    {
        $preferences = $this->smartPlannerService->normaliseTemplatePreferences($request->validated());
        if ($request->filled('recommendation_mode')) {
            $preferences = $this->aiPlanner->preparePreferences($preferences);

            $result = $this->aiPlanner->recommend($request->user(), $preferences);
            if ($result['recommendations'] === []) {
                return redirect()->route('client.visit-plans.smart-planner.index', status: 303)
                    ->with('planner_notice', $result['requested_reason_message'] ?? 'No matching option. Review your preferences and try again.');
            }

            return redirect()->route('client.visit-plans.smart-planner.result',
                ['snapshot' => $result['recommendations'][0]['snapshot_id']], 303);
        }

        return $this->plannerView($preferences, $this->smartPlannerService->recommendDateAware($preferences), legacy: true);
    }

    public function result(ShowPlannerResultRequest $request): Response
    {
        $token = $request->validated('snapshot_id');
        try {
            $result = $this->aiPlanner->resultFor($request->user(), $token);
        } catch (ValidationException $exception) {
            return response()->view('client.visit-plans.smart-planner-expired', [
                'message' => $exception->validator->errors()->first(),
            ], 410)->header('Cache-Control', 'private, no-store');
        }

        return response()->view('client.visit-plans.smart-planner-result', [
            'plannerResult' => $result, 'recommendations' => $result['recommendations'],
            'preferences' => $this->aiPlanner->preferencesFor($request->user(), $token),
            'snapshotId' => $token,
        ])->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'same-origin');
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
    private function plannerView(?array $preferences = null, ?array $plannerResult = null, ?string $currentResultId = null, bool $legacy = false): View
    {
        $options = $this->smartPlannerService->preferenceOptions();

        return view($legacy ? 'client.visit-plans.smart-planner-legacy' : 'client.visit-plans.smart-planner', [
            ...$options,
            'interestOptions' => PlannerFoodInterests::options($options['categories']->pluck('category')->all()),
            'preferences' => $preferences,
            'plannerResult' => $plannerResult,
            'recommendations' => $plannerResult['recommendations'] ?? null,
            'currentResultId' => $currentResultId,
        ]);
    }
}
