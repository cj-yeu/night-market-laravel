<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\VisitPlan\CreateVisitPlanRequest;
use App\Http\Requests\VisitPlan\StoreVisitPlanItemRequest;
use App\Http\Requests\VisitPlan\StoreVisitPlanRequest;
use App\Http\Requests\VisitPlan\UpdateVisitPlanRequest;
use App\Http\Requests\VisitPlan\VisitPlanIndexRequest;
use App\Services\VisitPlanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VisitPlanController extends Controller
{
    public function __construct(private readonly VisitPlanService $visitPlanService) {}

    public function index(VisitPlanIndexRequest $request): View
    {
        $filters = $request->validated();
        $planningTarget = $this->visitPlanService->planningTargetFromFilters($filters);

        return view('client.visit-plans.index', [
            'visitPlans' => $this->visitPlanService->plansForClient($request->user(), $filters),
            'planningTarget' => $planningTarget,
            'compatiblePlans' => $planningTarget
                ? $this->visitPlanService->compatiblePlansForTarget($request->user(), $planningTarget)
                : collect(),
            'filters' => $filters,
            'hasFilters' => filled($filters['search'] ?? null) || filled($filters['status'] ?? null),
            'targetQuery' => $planningTarget ? [
                'item_type' => $planningTarget['type'],
                'item_id' => $planningTarget['id'],
            ] : [],
        ]);
    }

    public function create(CreateVisitPlanRequest $request): View
    {
        return view('client.visit-plans.create', [
            'nightMarkets' => $this->visitPlanService->activeNightMarkets(),
            'selectedNightMarketId' => $request->validated('night_market_id'),
        ]);
    }

    public function store(StoreVisitPlanRequest $request): RedirectResponse
    {
        $visitPlan = $this->visitPlanService->createForClient($request->user(), $request->validated());

        return redirect()
            ->route('client.visit-plans.show', $visitPlan)
            ->with('status', 'Your visit plan was created successfully.');
    }

    public function show(Request $request, int $visitPlan): View
    {
        $visitPlan = $this->visitPlanService->planDetailsForClient($request->user(), $visitPlan);
        $canChangeItems = $visitPlan->visit_status !== 'Past';

        return view('client.visit-plans.show', [
            'visitPlan' => $visitPlan,
            'selectedStalls' => $visitPlan->items->where('item_type', 'stall')->values(),
            'selectedFoods' => $visitPlan->items->where('item_type', 'food')->values(),
            'canChangeItems' => $canChangeItems,
            'eligibleStalls' => $canChangeItems ? $this->visitPlanService->eligibleStallsForPlan($visitPlan) : collect(),
            'eligibleFoods' => $canChangeItems ? $this->visitPlanService->eligibleFoodsForPlan($visitPlan) : collect(),
        ]);
    }

    public function edit(Request $request, int $visitPlan): View
    {
        $visitPlan = $this->visitPlanService->planDetailsForClient($request->user(), $visitPlan);

        return view('client.visit-plans.edit', [
            'visitPlan' => $visitPlan,
            'nightMarkets' => $this->visitPlanService->editableNightMarketsForPlan($visitPlan),
        ]);
    }

    public function update(UpdateVisitPlanRequest $request, int $visitPlan): RedirectResponse
    {
        $visitPlan = $this->visitPlanService->updateForClient(
            $request->user(),
            $visitPlan,
            $request->validated(),
        );

        return redirect()
            ->route('client.visit-plans.show', $visitPlan)
            ->with('status', 'Your visit plan was updated successfully.');
    }

    public function destroy(Request $request, int $visitPlan): RedirectResponse
    {
        $this->visitPlanService->deleteForClient($request->user(), $visitPlan);

        return redirect()
            ->route('client.visit-plans.index')
            ->with('status', 'Your visit plan was deleted successfully.');
    }

    public function storeItem(StoreVisitPlanItemRequest $request, int $visitPlan): RedirectResponse
    {
        $this->visitPlanService->addItemForClient($request->user(), $visitPlan, $request->validated());

        return redirect()
            ->route('client.visit-plans.show', $visitPlan)
            ->with('status', 'The item was added to your visit plan.');
    }

    public function destroyItem(Request $request, int $visitPlan, int $visitPlanItem): RedirectResponse
    {
        $this->visitPlanService->removeItemForClient($request->user(), $visitPlan, $visitPlanItem);

        return redirect()
            ->route('client.visit-plans.show', $visitPlan)
            ->with('status', 'The item was removed from your visit plan.');
    }
}
