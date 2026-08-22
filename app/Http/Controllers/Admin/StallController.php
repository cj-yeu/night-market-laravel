<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OperationalStatusRequest;
use App\Http\Requests\StallFood\AdminStallFilterRequest;
use App\Http\Requests\StallFood\DeleteStallImageRequest;
use App\Http\Requests\StallFood\StoreStallRequest;
use App\Http\Requests\StallFood\UpdateStallImageRequest;
use App\Http\Requests\StallFood\UpdateStallRequest;
use App\Models\Stall;
use App\Services\NightMarketService;
use App\Services\StallFoodImageService;
use App\Services\StallFoodService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class StallController extends Controller
{
    public function __construct(
        private readonly StallFoodService $stallFoodService,
        private readonly NightMarketService $nightMarketService,
        private readonly StallFoodImageService $stallFoodImageService,
    ) {}

    public function index(AdminStallFilterRequest $request): View
    {
        $filters = $request->validated();

        return view('admin.stalls.index', [
            'stalls' => $this->stallFoodService->adminStalls($filters),
            'nightMarkets' => $this->nightMarketService->adminMarketOptions(),
            'categories' => $this->stallFoodService->adminStallCategories(),
            'halalStatuses' => Stall::halalStatusOptions(),
            'filters' => $filters,
        ]);
    }

    public function create(): View
    {
        return view('admin.stalls.create', [
            'nightMarkets' => $this->stallFoodService->activeNightMarkets(),
            'halalStatuses' => Stall::halalStatusOptions(),
        ]);
    }

    public function store(StoreStallRequest $request): RedirectResponse
    {
        $stall = $this->stallFoodService->createStall($request->validated());

        return redirect()
            ->route('admin.stalls.create')
            ->with('status', $stall->name.' was added successfully.');
    }

    public function show(Stall $stall): View
    {
        return view('admin.stalls.show', [
            'stall' => $this->stallFoodService->adminStallDetails($stall),
        ]);
    }

    public function edit(Stall $stall): View
    {
        return view('admin.stalls.edit', [
            'stall' => $this->stallFoodService->adminStallDetails($stall),
            'nightMarkets' => $this->nightMarketService->adminMarketOptions(),
            'halalStatuses' => Stall::halalStatusOptions(),
        ]);
    }

    public function update(UpdateStallRequest $request, Stall $stall): RedirectResponse
    {
        $stall = $this->stallFoodService->updateStall($stall, $request->validated());

        return redirect()
            ->route('admin.stalls.show', $stall)
            ->with('status', $stall->name.' was updated successfully.');
    }

    public function activate(OperationalStatusRequest $request, Stall $stall): RedirectResponse
    {
        return $this->updateStatus($stall, Stall::STATUS_ACTIVE);
    }

    public function deactivate(OperationalStatusRequest $request, Stall $stall): RedirectResponse
    {
        return $this->updateStatus($stall, Stall::STATUS_INACTIVE);
    }

    public function updateImage(UpdateStallImageRequest $request, Stall $stall): RedirectResponse
    {
        $this->stallFoodImageService->updateStallImage($stall, $request->file('image'));

        return redirect()->route('admin.stalls.show', $stall)
            ->with('status', 'Stall image updated successfully.');
    }

    public function deleteImage(DeleteStallImageRequest $request, Stall $stall): RedirectResponse
    {
        $this->stallFoodImageService->removeStallImage($stall);

        return redirect()->route('admin.stalls.show', $stall)
            ->with('status', 'Stall image removed successfully.');
    }

    private function updateStatus(Stall $stall, string $status): RedirectResponse
    {
        $stall = $this->stallFoodService->setStallStatus($stall, $status);

        return redirect()
            ->route('admin.stalls.index')
            ->with('status', $stall->name.' is now '.$status.'.');
    }
}
