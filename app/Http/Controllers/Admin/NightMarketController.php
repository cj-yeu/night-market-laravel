<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DeleteCatalogRecordRequest;
use App\Http\Requests\Admin\OperationalStatusRequest;
use App\Http\Requests\NightMarket\AdminNightMarketFilterRequest;
use App\Http\Requests\NightMarket\DeleteNightMarketImageRequest;
use App\Http\Requests\NightMarket\StoreNightMarketRequest;
use App\Http\Requests\NightMarket\UpdateNightMarketImageRequest;
use App\Http\Requests\NightMarket\UpdateNightMarketRequest;
use App\Models\MarketOperatingDay;
use App\Models\NightMarket;
use App\Models\User;
use App\Services\AdminReturnUrlService;
use App\Services\CatalogAuditLogService;
use App\Services\CatalogDeletionService;
use App\Services\NightMarketImageService;
use App\Services\NightMarketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NightMarketController extends Controller
{
    public function __construct(
        private readonly NightMarketService $nightMarketService,
        private readonly NightMarketImageService $nightMarketImageService,
        private readonly AdminReturnUrlService $adminReturnUrlService,
        private readonly CatalogAuditLogService $catalogAuditLogService,
        private readonly CatalogDeletionService $catalogDeletionService,
    ) {}

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
            'cities' => $this->nightMarketService->cityOptions(),
        ]);
    }

    public function store(StoreNightMarketRequest $request): RedirectResponse
    {
        $nightMarket = $this->nightMarketService->create($request->validated());
        $this->catalogAuditLogService->record($request->user(), $nightMarket, 'created', 'Created night market “'.$nightMarket->name.'”');

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

    public function edit(Request $request, NightMarket $nightMarket): View
    {
        return view('admin.night-markets.edit', [
            'nightMarket' => $this->nightMarketService->adminDetails($nightMarket),
            'days' => MarketOperatingDay::DAYS,
            'cities' => $this->nightMarketService->cityOptions(),
            'returnTo' => $this->adminReturnUrlService->catalogQualityUrl($request),
        ]);
    }

    public function update(UpdateNightMarketRequest $request, NightMarket $nightMarket): RedirectResponse
    {
        $before = $nightMarket->getAttributes();
        $beforeDays = $this->scheduleSignature($nightMarket);
        $nightMarket = $this->nightMarketService->update($nightMarket, $request->validated());
        $changes = $this->catalogAuditLogService->safeChanges($before, $nightMarket);
        if ($beforeDays !== $this->scheduleSignature($nightMarket)) {
            $changes['operating_days'] = ['label' => 'Operating days', 'before' => 'Updated', 'after' => 'Updated'];
        }
        if ($changes) {
            $fields = collect($changes)->pluck('label')->map(fn ($label) => strtolower($label))->implode(', ');
            $this->catalogAuditLogService->record($request->user(), $nightMarket, 'updated', 'Updated '.$fields, $changes);
        }

        $redirect = $this->adminReturnUrlService->catalogQualityUrl($request)
            ? redirect()->to($this->adminReturnUrlService->catalogQualityUrl($request))
            : redirect()->route('admin.night-markets.show', $nightMarket);

        return $redirect->with('status', $nightMarket->name.' was updated successfully.');
    }

    public function activate(OperationalStatusRequest $request, NightMarket $nightMarket): RedirectResponse
    {
        return $this->updateStatus($request->user(), $nightMarket, NightMarket::STATUS_ACTIVE);
    }

    public function deactivate(OperationalStatusRequest $request, NightMarket $nightMarket): RedirectResponse
    {
        return $this->updateStatus($request->user(), $nightMarket, NightMarket::STATUS_INACTIVE);
    }

    public function updateImage(UpdateNightMarketImageRequest $request, NightMarket $nightMarket): RedirectResponse
    {
        $replaced = $nightMarket->image_path !== null;
        $this->nightMarketImageService->replace($nightMarket, $request->file('image'));
        $this->catalogAuditLogService->record($request->user(), $nightMarket, 'image_updated', $replaced ? 'Replaced night market image' : 'Uploaded night market image', ['image' => ['label' => 'Image', 'after' => 'Image updated']]);

        return redirect()
            ->route('admin.night-markets.show', $nightMarket)
            ->with('status', 'The Night Market cover image was updated successfully.');
    }

    public function deleteImage(DeleteNightMarketImageRequest $request, NightMarket $nightMarket): RedirectResponse
    {
        $hadImage = $nightMarket->image_path !== null;
        $this->nightMarketImageService->remove($nightMarket);
        if ($hadImage) {
            $this->catalogAuditLogService->record($request->user(), $nightMarket, 'image_removed', 'Removed night market image', ['image' => ['label' => 'Image', 'before' => 'Image removed']]);
        }

        return redirect()
            ->route('admin.night-markets.show', $nightMarket)
            ->with('status', 'The Night Market cover image was removed.');
    }

    public function destroy(DeleteCatalogRecordRequest $request, NightMarket $nightMarket): RedirectResponse
    {
        $deleted = $this->catalogDeletionService->deleteNightMarket($nightMarket);
        $this->catalogAuditLogService->recordDeleted($request->user(), 'night_market', $deleted['id'], $deleted['name']);

        return redirect()->route('admin.night-markets.index')->with('status', $deleted['name'].' was permanently deleted.');
    }

    private function updateStatus(User $user, NightMarket $nightMarket, string $status): RedirectResponse
    {
        $changed = $nightMarket->status !== $status;
        $nightMarket = $this->nightMarketService->setStatus($nightMarket, $status);
        if ($changed) {
            $this->catalogAuditLogService->record($user, $nightMarket, $status === NightMarket::STATUS_ACTIVE ? 'activated' : 'deactivated', ucfirst($status).' night market', ['status' => ['label' => 'Status', 'after' => $status]]);
        }

        return redirect()
            ->route('admin.night-markets.index')
            ->with('status', $nightMarket->name.' is now '.$status.'.');
    }

    private function scheduleSignature(NightMarket $nightMarket): string
    {
        return $nightMarket->operatingDays()->orderBy('day_of_week')->get(['day_of_week', 'opening_time', 'closing_time'])->toJson();
    }
}
