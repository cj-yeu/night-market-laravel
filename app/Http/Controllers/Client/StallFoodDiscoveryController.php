<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\StallFood\StallFoodFilterRequest;
use App\Services\StallFoodService;
use Illuminate\View\View;

class StallFoodDiscoveryController extends Controller
{
    public function __construct(private readonly StallFoodService $stallFoodService) {}

    public function index(StallFoodFilterRequest $request, int $nightMarket): View
    {
        $nightMarket = $this->stallFoodService->findActiveMarketForClient($nightMarket);
        $filters = $request->validated();

        return view('client.stalls.index', [
            'nightMarket' => $nightMarket,
            'stalls' => $this->stallFoodService->discoverStallsForMarket($nightMarket, $filters),
            'categories' => $this->stallFoodService->activeCategoriesForMarket($nightMarket),
            'filters' => $filters,
        ]);
    }

    public function show(int $food): View
    {
        return view('client.foods.show', [
            'food' => $this->stallFoodService->findActiveFoodForClient($food),
        ]);
    }
}