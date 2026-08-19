<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OperationalStatusRequest;
use App\Http\Requests\NightMarket\AdminNightMarketFilterRequest;
use App\Http\Requests\NightMarket\StoreNightMarketRequest;
use App\Http\Requests\NightMarket\UpdateNightMarketRequest;
use App\Models\MarketOperatingDay;
use App\Models\NightMarket;
use App\Services\NightMarketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class NightMarketController extends Controller
{
    public function __construct(private readonly NightMarketService $nightMarketService) {}

    public function index(AdminNightMarketFilterRequest $request): View
    {
        $filters = $request->validated();

        return view('admin.night-markets.index', [
            'nightMarkets' => $this->nightMarketService->adminMarkets($filters),
            'cities' => $this->nightMarketService->adminCities(),
            'days' => MarketOperatingDay::DAYS,
            'filters' => $filters,
        ]);
    }

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

    public function show(NightMarket $nightMarket): View
    {
        return view('admin.night-markets.show', [
            'nightMarket' => $this->nightMarketService->adminDetails($nightMarket),
        ]);
    }

    public function edit(NightMarket $nightMarket): View
    {
        return view('admin.night-markets.edit', [
            'nightMarket' => $this->nightMarketService->adminDetails($nightMarket),
            'days' => MarketOperatingDay::DAYS,
        ]);
    }

    public function update(UpdateNightMarketRequest $request, NightMarket $nightMarket): RedirectResponse
    {
        $nightMarket = $this->nightMarketService->update($nightMarket, $request->validated());

        return redirect()
            ->route('admin.night-markets.show', $nightMarket)
            ->with('status', $nightMarket->name.' was updated successfully.');
    }

    public function activate(OperationalStatusRequest $request, NightMarket $nightMarket): RedirectResponse
    {
        return $this->updateStatus($nightMarket, NightMarket::STATUS_ACTIVE);
    }

    public function deactivate(OperationalStatusRequest $request, NightMarket $nightMarket): RedirectResponse
    {
        return $this->updateStatus($nightMarket, NightMarket::STATUS_INACTIVE);
    }

    private function updateStatus(NightMarket $nightMarket, string $status): RedirectResponse
    {
        $nightMarket = $this->nightMarketService->setStatus($nightMarket, $status);

        return redirect()
            ->route('admin.night-markets.index')
            ->with('status', $nightMarket->name.' is now '.$status.'.');
    }
}
