<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OperationalStatusRequest;
use App\Http\Requests\StallFood\AdminFoodFilterRequest;
use App\Http\Requests\StallFood\StoreFoodRequest;
use App\Http\Requests\StallFood\UpdateFoodRequest;
use App\Models\Food;
use App\Services\NightMarketService;
use App\Services\StallFoodService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class FoodController extends Controller
{
    public function __construct(
        private readonly StallFoodService $stallFoodService,
        private readonly NightMarketService $nightMarketService,
    ) {}

    public function index(AdminFoodFilterRequest $request): View
    {
        $filters = $request->validated();

        return view('admin.foods.index', [
            'foods' => $this->stallFoodService->adminFoods($filters),
            'nightMarkets' => $this->nightMarketService->adminMarketOptions(),
            'stalls' => $this->stallFoodService->adminStallOptions(),
            'categories' => $this->stallFoodService->adminCategories(),
            'filters' => $filters,
        ]);
    }

    public function create(): View
    {
        return view('admin.foods.create', [
            'stalls' => $this->stallFoodService->activeStalls(),
        ]);
    }

    public function store(StoreFoodRequest $request): RedirectResponse
    {
        $food = $this->stallFoodService->createFood($request->validated());

        return redirect()
            ->route('admin.foods.create')
            ->with('status', $food->name.' was added successfully.');
    }

    public function show(Food $food): View
    {
        return view('admin.foods.show', [
            'food' => $this->stallFoodService->adminFoodDetails($food),
        ]);
    }

    public function edit(Food $food): View
    {
        return view('admin.foods.edit', [
            'food' => $this->stallFoodService->adminFoodDetails($food),
            'stalls' => $this->stallFoodService->adminStallOptions(),
        ]);
    }

    public function update(UpdateFoodRequest $request, Food $food): RedirectResponse
    {
        $food = $this->stallFoodService->updateFood($food, $request->validated());

        return redirect()
            ->route('admin.foods.show', $food)
            ->with('status', $food->name.' was updated successfully.');
    }

    public function activate(OperationalStatusRequest $request, Food $food): RedirectResponse
    {
        return $this->updateStatus($food, Food::STATUS_ACTIVE);
    }

    public function deactivate(OperationalStatusRequest $request, Food $food): RedirectResponse
    {
        return $this->updateStatus($food, Food::STATUS_INACTIVE);
    }

    private function updateStatus(Food $food, string $status): RedirectResponse
    {
        $food = $this->stallFoodService->setFoodStatus($food, $status);

        return redirect()
            ->route('admin.foods.index')
            ->with('status', $food->name.' is now '.$status.'.');
    }
}
