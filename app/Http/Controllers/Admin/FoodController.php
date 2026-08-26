<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OperationalStatusRequest;
use App\Http\Requests\StallFood\AdminFoodFilterRequest;
use App\Http\Requests\StallFood\DeleteFoodImageRequest;
use App\Http\Requests\StallFood\StoreFoodRequest;
use App\Http\Requests\StallFood\UpdateFoodImageRequest;
use App\Http\Requests\StallFood\UpdateFoodRequest;
use App\Models\Food;
use App\Models\User;
use App\Services\AdminReturnUrlService;
use App\Services\CatalogAuditLogService;
use App\Services\NightMarketService;
use App\Services\StallFoodImageService;
use App\Services\StallFoodService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FoodController extends Controller
{
    public function __construct(
        private readonly StallFoodService $stallFoodService,
        private readonly NightMarketService $nightMarketService,
        private readonly StallFoodImageService $stallFoodImageService,
        private readonly AdminReturnUrlService $adminReturnUrlService,
        private readonly CatalogAuditLogService $catalogAuditLogService,
    ) {}

    public function index(AdminFoodFilterRequest $request): View
    {
        $filters = $request->validated();

        return view('admin.foods.index', [
            'foods' => $this->stallFoodService->adminFoods($filters),
            'nightMarkets' => $this->nightMarketService->adminMarketOptions(),
            'stalls' => $this->stallFoodService->adminStallOptions(),
            'categories' => $this->stallFoodService->adminCategories(),
            'filters' => $filters,
        ]);
    }

    public function create(): View
    {
        return view('admin.foods.create', [
            'stalls' => $this->stallFoodService->activeStalls(),
        ]);
    }

    public function store(StoreFoodRequest $request): RedirectResponse
    {
        $food = $this->stallFoodService->createFood($request->validated());
        $this->catalogAuditLogService->record($request->user(), $food, 'created', 'Created food “'.$food->name.'”');

        return redirect()
            ->route('admin.foods.create')
            ->with('status', $food->name.' was added successfully.');
    }

    public function show(Food $food): View
    {
        return view('admin.foods.show', [
            'food' => $this->stallFoodService->adminFoodDetails($food),
        ]);
    }

    public function edit(Request $request, Food $food): View
    {
        return view('admin.foods.edit', [
            'food' => $this->stallFoodService->adminFoodDetails($food),
            'stalls' => $this->stallFoodService->adminStallOptions(),
            'returnTo' => $this->adminReturnUrlService->catalogQualityUrl($request),
        ]);
    }

    public function update(UpdateFoodRequest $request, Food $food): RedirectResponse
    {
        $before = $food->getAttributes();
        $food = $this->stallFoodService->updateFood($food, $request->validated());
        $changes = $this->catalogAuditLogService->safeChanges($before, $food);
        if ($changes) {
            $this->catalogAuditLogService->record($request->user(), $food, 'updated', 'Updated '.collect($changes)->pluck('label')->map(fn ($label) => strtolower($label))->implode(', '), $changes);
        }

        $redirect = $this->adminReturnUrlService->catalogQualityUrl($request)
            ? redirect()->to($this->adminReturnUrlService->catalogQualityUrl($request))
            : redirect()->route('admin.foods.show', $food);

        return $redirect->with('status', $food->name.' was updated successfully.');
    }

    public function activate(OperationalStatusRequest $request, Food $food): RedirectResponse
    {
        return $this->updateStatus($request->user(), $food, Food::STATUS_ACTIVE);
    }

    public function deactivate(OperationalStatusRequest $request, Food $food): RedirectResponse
    {
        return $this->updateStatus($request->user(), $food, Food::STATUS_INACTIVE);
    }

    public function updateImage(UpdateFoodImageRequest $request, Food $food): RedirectResponse
    {
        $replaced = $food->image_path !== null;
        $this->stallFoodImageService->updateFoodImage($food, $request->file('image'));
        $this->catalogAuditLogService->record($request->user(), $food, 'image_updated', $replaced ? 'Replaced food image' : 'Uploaded food image', ['image' => ['label' => 'Image', 'after' => 'Image updated']]);

        return redirect()->route('admin.foods.show', $food)
            ->with('status', 'Food image updated successfully.');
    }

    public function deleteImage(DeleteFoodImageRequest $request, Food $food): RedirectResponse
    {
        $hadImage = $food->image_path !== null;
        $this->stallFoodImageService->removeFoodImage($food);
        if ($hadImage) {
            $this->catalogAuditLogService->record($request->user(), $food, 'image_removed', 'Removed food image', ['image' => ['label' => 'Image', 'before' => 'Image removed']]);
        }

        return redirect()->route('admin.foods.show', $food)
            ->with('status', 'Food image removed successfully.');
    }

    private function updateStatus(User $user, Food $food, string $status): RedirectResponse
    {
        $changed = $food->status !== $status;
        $food = $this->stallFoodService->setFoodStatus($food, $status);
        if ($changed) {
            $this->catalogAuditLogService->record($user, $food, $status === Food::STATUS_ACTIVE ? 'activated' : 'deactivated', ucfirst($status).' food', ['status' => ['label' => 'Status', 'after' => $status]]);
        }

        return redirect()
            ->route('admin.foods.index')
            ->with('status', $food->name.' is now '.$status.'.');
    }
}
