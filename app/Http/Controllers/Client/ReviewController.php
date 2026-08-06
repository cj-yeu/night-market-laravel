<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Review\StoreReviewRequest;
use App\Services\ReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ReviewController extends Controller
{
    public function __construct(private readonly ReviewService $reviewService) {}

    public function create(int $nightMarket): View
    {
        return view('client.reviews.create', [
            'nightMarket' => $this->reviewService->findActiveMarketForClient($nightMarket),
        ]);
    }

    public function store(StoreReviewRequest $request, int $nightMarket): RedirectResponse
    {
        $nightMarket = $this->reviewService->findActiveMarketForClient($nightMarket);

        $this->reviewService->createForClient($request->user(), $nightMarket, $request->validated());

        return redirect()
            ->route('client.night-markets.show', $nightMarket)
            ->with('status', 'Your review was submitted and is awaiting approval.');
    }
}
