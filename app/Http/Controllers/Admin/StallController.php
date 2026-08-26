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
use App\Models\User;
use App\Services\AdminReturnUrlService;
use App\Services\CatalogAuditLogService;
use App\Services\NightMarketService;
use App\Services\StallFoodImageService;
use App\Services\StallFoodService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StallController extends Controller
{
    public function __construct(
        private readonly StallFoodService $stallFoodService,
        private readonly NightMarketService $nightMarketService,
        private readonly StallFoodImageService $stallFoodImageService,
        private readonly AdminReturnUrlService $adminReturnUrlService,
        private readonly CatalogAuditLogService $catalogAuditLogService,
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
        $this->catalogAuditLogService->record($request->user(), $stall, 'created', 'Created stall “'.$stall->name.'”');

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

    public function edit(Request $request, Stall $stall): View
    {
        return view('admin.stalls.edit', [
            'stall' => $this->stallFoodService->adminStallDetails($stall),
            'nightMarkets' => $this->nightMarketService->adminMarketOptions(),
            'halalStatuses' => Stall::halalStatusOptions(),
            'returnTo' => $this->adminReturnUrlService->catalogQualityUrl($request),
        ]);
    }

    public function update(UpdateStallRequest $request, Stall $stall): RedirectResponse
    {
        $before = $stall->getAttributes();
        $stall = $this->stallFoodService->updateStall($stall, $request->validated());
        $changes = $this->catalogAuditLogService->safeChanges($before, $stall);
        if ($changes) {
            $this->catalogAuditLogService->record($request->user(), $stall, 'updated', 'Updated '.collect($changes)->pluck('label')->map(fn ($label) => strtolower($label))->implode(', '), $changes);
        }

        $redirect = $this->adminReturnUrlService->catalogQualityUrl($request)
            ? redirect()->to($this->adminReturnUrlService->catalogQualityUrl($request))
            : redirect()->route('admin.stalls.show', $stall);

        return $redirect->with('status', $stall->name.' was updated successfully.');
    }

    public function activate(OperationalStatusRequest $request, Stall $stall): RedirectResponse
    {
        return $this->updateStatus($request->user(), $stall, Stall::STATUS_ACTIVE);
    }

    public function deactivate(OperationalStatusRequest $request, Stall $stall): RedirectResponse
    {
        return $this->updateStatus($request->user(), $stall, Stall::STATUS_INACTIVE);
    }

    public function updateImage(UpdateStallImageRequest $request, Stall $stall): RedirectResponse
    {
        $replaced = $stall->image_path !== null;
        $this->stallFoodImageService->updateStallImage($stall, $request->file('image'));
        $this->catalogAuditLogService->record($request->user(), $stall, 'image_updated', $replaced ? 'Replaced stall image' : 'Uploaded stall image', ['image' => ['label' => 'Image', 'after' => 'Image updated']]);

        return redirect()->route('admin.stalls.show', $stall)
            ->with('status', 'Stall image updated successfully.');
    }

    public function deleteImage(DeleteStallImageRequest $request, Stall $stall): RedirectResponse
    {
        $hadImage = $stall->image_path !== null;
        $this->stallFoodImageService->removeStallImage($stall);
        if ($hadImage) {
            $this->catalogAuditLogService->record($request->user(), $stall, 'image_removed', 'Removed stall image', ['image' => ['label' => 'Image', 'before' => 'Image removed']]);
        }

        return redirect()->route('admin.stalls.show', $stall)
            ->with('status', 'Stall image removed successfully.');
    }

    private function updateStatus(User $user, Stall $stall, string $status): RedirectResponse
    {
        $changed = $stall->status !== $status;
        $stall = $this->stallFoodService->setStallStatus($stall, $status);
        if ($changed) {
            $this->catalogAuditLogService->record($user, $stall, $status === Stall::STATUS_ACTIVE ? 'activated' : 'deactivated', ucfirst($status).' stall', ['status' => ['label' => 'Status', 'after' => $status]]);
        }

        return redirect()
            ->route('admin.stalls.index')
            ->with('status', $stall->name.' is now '.$status.'.');
    }
}
