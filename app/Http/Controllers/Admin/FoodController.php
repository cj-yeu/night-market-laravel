<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StallFood\StoreFoodRequest;
use App\Services\StallFoodService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class FoodController extends Controller
{
    public function __construct(private readonly StallFoodService $stallFoodService) {}

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
}
