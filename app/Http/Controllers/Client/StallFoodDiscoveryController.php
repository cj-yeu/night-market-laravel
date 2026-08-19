<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\StallFood\PublicFoodDiscoveryRequest;
use App\Http\Requests\StallFood\PublicStallDiscoveryRequest;
use App\Http\Requests\StallFood\StallFoodFilterRequest;
use App\Models\Stall;
use App\Services\ReviewService;
use App\Services\StallFoodService;
use Illuminate\View\View;

class StallFoodDiscoveryController extends Controller
{
    public function __construct(
        private readonly StallFoodService $stallFoodService,
        private readonly ReviewService $reviewService,
    ) {}

    public function stalls(PublicStallDiscoveryRequest $request): View
    {
        $filters = $request->validated();

        return view('client.stalls.discover', [
            'stalls' => $this->stallFoodService->discoverPublicStalls($filters),
            ...$this->stallFoodService->publicStallFilterOptions(),
            'halalStatuses' => Stall::halalStatusOptions(),
            'filters' => $filters,
        ]);
    }

    public function foods(PublicFoodDiscoveryRequest $request): View
    {
        $filters = $request->validated();

        return view('client.foods.index', [
            'foods' => $this->stallFoodService->discoverPublicFoods($filters),
            ...$this->stallFoodService->publicFoodFilterOptions(),
            'filters' => $filters,
        ]);
    }

    public function index(StallFoodFilterRequest $request, int $nightMarket): View
    {
        $nightMarket = $this->stallFoodService->findPubliclyVisibleMarket($nightMarket);
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
        $food = $this->stallFoodService->findPubliclyVisibleFood($food);

        return view('client.foods.show', [
            'food' => $food,
            ...$this->reviewService->approvedSummaryForMarket($food->stall->nightMarket),
        ]);
    }
}
