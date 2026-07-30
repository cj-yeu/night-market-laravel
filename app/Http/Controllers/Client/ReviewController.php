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

    public function create(): View
    {
        return view('client.reviews.create', [
            'nightMarkets' => $this->reviewService->activeNightMarkets(),
        ]);
    }

    public function store(StoreReviewRequest $request): RedirectResponse
    {
        $this->reviewService->createForClient($request->user(), $request->validated());

        return redirect()
            ->route('client.reviews.create')
            ->with('status', 'Your review was submitted and is awaiting approval.');
    }
}
