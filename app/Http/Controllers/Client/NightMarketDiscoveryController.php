<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\NightMarket\MarketDiscoveryRequest;
use App\Services\NightMarketService;
use App\Services\ReviewService;
use Illuminate\View\View;

class NightMarketDiscoveryController extends Controller
{
    public function __construct(
        private readonly NightMarketService $nightMarketService,
        private readonly ReviewService $reviewService,
    ) {}

    public function index(MarketDiscoveryRequest $request): View
    {
        $filters = $request->validated();

        return view('client.night-markets.index', [
            'nightMarkets' => $this->nightMarketService->discoverPublicMarkets($filters),
            'districts' => $this->nightMarketService->publicDistricts(),
            'operatingDays' => $this->nightMarketService->operatingDayOptions(),
            'filters' => $filters,
        ]);
    }

    public function show(int $nightMarket): View
    {
        $nightMarket = $this->nightMarketService->findPubliclyVisible($nightMarket);

        return view('client.night-markets.show', [
            'nightMarket' => $nightMarket,
            'activeStalls' => $nightMarket->stalls,
            'mustTryFoods' => $this->nightMarketService->mustTryFoods($nightMarket),
            ...$this->reviewService->approvedSummaryForMarket($nightMarket),
        ]);
    }
}
