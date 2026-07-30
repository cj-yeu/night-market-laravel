<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\VisitPlan\StoreVisitPlanRequest;
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
        $this->visitPlanService->createForClient($request->user(), $request->validated());

        return redirect()
            ->route('client.visit-plans.index')
            ->with('status', 'Your visit plan was created successfully.');
    }
}
