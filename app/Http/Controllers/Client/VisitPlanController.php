<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\VisitPlan\StoreVisitPlanItemRequest;
use App\Http\Requests\VisitPlan\StoreVisitPlanRequest;
use App\Http\Requests\VisitPlan\UpdateVisitPlanRequest;
use App\Services\VisitPlanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VisitPlanController extends Controller
{
    public function __construct(private readonly VisitPlanService $visitPlanService) {}

    public function index(Request $request): View
    {
        return view('client.visit-plans.index', [
            'visitPlans' => $this->visitPlanService->plansForClient($request->user()),
        ]);
    }

    public function create(): View
    {
        return view('client.visit-plans.create', [
            'nightMarkets' => $this->visitPlanService->activeNightMarkets(),
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

        return view('client.visit-plans.show', [
            'visitPlan' => $visitPlan,
            'eligibleStalls' => $this->visitPlanService->eligibleStallsForPlan($visitPlan),
            'eligibleFoods' => $this->visitPlanService->eligibleFoodsForPlan($visitPlan),
        ]);
    }

    public function edit(Request $request, int $visitPlan): View
    {
        return view('client.visit-plans.edit', [
            'visitPlan' => $this->visitPlanService->planDetailsForClient($request->user(), $visitPlan),
            'nightMarkets' => $this->visitPlanService->activeNightMarkets(),
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
