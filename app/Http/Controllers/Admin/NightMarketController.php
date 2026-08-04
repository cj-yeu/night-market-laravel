<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\NightMarket\StoreNightMarketRequest;
use App\Models\MarketOperatingDay;
use App\Services\NightMarketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class NightMarketController extends Controller
{
    public function __construct(private readonly NightMarketService $nightMarketService) {}

    public function create(): View
    {
        return view('admin.night-markets.create', [
            'days' => MarketOperatingDay::DAYS,
        ]);
    }

    public function store(StoreNightMarketRequest $request): RedirectResponse
    {
        $nightMarket = $this->nightMarketService->create($request->validated());

        return redirect()
            ->route('admin.night-markets.create')
            ->with('status', $nightMarket->name.' was added successfully.');
    }
}
