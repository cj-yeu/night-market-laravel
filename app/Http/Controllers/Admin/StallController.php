<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StallFood\StoreStallRequest;
use App\Services\StallFoodService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class StallController extends Controller
{
    public function __construct(private readonly StallFoodService $stallFoodService) {}

    public function create(): View
    {
        return view('admin.stalls.create', [
            'nightMarkets' => $this->stallFoodService->activeNightMarkets(),
        ]);
    }

    public function store(StoreStallRequest $request): RedirectResponse
    {
        $stall = $this->stallFoodService->createStall($request->validated());

        return redirect()
            ->route('admin.stalls.create')
            ->with('status', $stall->name.' was added successfully.');
    }
}
