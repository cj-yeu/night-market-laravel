<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\NightMarket\MarketDiscoveryRequest;
use App\Services\NightMarketService;
use Illuminate\View\View;

class NightMarketDiscoveryController extends Controller
{
    public function __construct(private readonly NightMarketService $nightMarketService) {}

    public function index(MarketDiscoveryRequest $request): View
    {
        $filters = $request->validated();

        return view('client.night-markets.index', [
            'nightMarkets' => $this->nightMarketService->discoverActiveMarkets($filters),
            'districts' => $this->nightMarketService->activeDistricts(),
            'filters' => $filters,
        ]);
    }

    public function show(int $nightMarket): View
    {
        return view('client.night-markets.show', [
            'nightMarket' => $this->nightMarketService->findActiveForClient($nightMarket),
        ]);
    }
}
