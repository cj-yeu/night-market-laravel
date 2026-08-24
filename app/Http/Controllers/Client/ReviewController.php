<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Review\StoreReviewRequest;
use App\Http\Requests\Review\UpdateReviewRequest;
use App\Models\Food;
use App\Models\NightMarket;
use App\Models\Review;
use App\Services\ReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReviewController extends Controller
{
    public function __construct(private readonly ReviewService $reviewService) {}

    public function create(Request $request, Food $food): View|RedirectResponse
    {
        $food = $this->reviewService->findPubliclyVisibleFood($food->id);
        $review = $this->reviewService->reviewForClient($food, $request->user());

        if ($review !== null) {
            return redirect()->route('client.foods.reviews.edit', [$food, $review]);
        }

        return view('client.reviews.create', [
            'food' => $food,
            'tagOptions' => Review::foodTagOptions(),
        ]);
    }

    public function store(StoreReviewRequest $request, Food $food): RedirectResponse
    {
        $food = $this->reviewService->findPubliclyVisibleFood($food->id);
        $this->reviewService->createForClient($request->user(), $food, $request->validated());

        return redirect()
            ->route('foods.show', $food)
            ->with('status', 'Your review has been published.');
    }

    public function edit(Request $request, Food $food, Review $review): View
    {
        $food = $this->reviewService->findPubliclyVisibleFood($food->id);
        abort_unless($review->user_id === $request->user()->id, 403);
        abort_unless($review->food_id === $food->id, 404);
        abort_unless($review->review_date?->isSameDay(Review::currentReviewDate()), 403);

        return view('client.reviews.edit', compact('food', 'review') + ['tagOptions' => Review::foodTagOptions()]);
    }

    public function update(UpdateReviewRequest $request, Food $food, Review $review): RedirectResponse
    {
        $food = $this->reviewService->findPubliclyVisibleFood($food->id);
        $this->reviewService->updateForClient($request->user(), $food, $review, $request->validated());

        return redirect()
            ->route('foods.show', $food)
            ->with('status', 'Your review has been updated and remains published.');
    }

    public function createMarket(Request $request, NightMarket $nightMarket): View|RedirectResponse
    {
        $nightMarket = $this->reviewService->findPubliclyVisibleMarket($nightMarket->id);
        $review = $this->reviewService->marketReviewForClient($nightMarket, $request->user());

        if ($review !== null) {
            return redirect()->route('client.night-markets.reviews.edit', [$nightMarket, $review]);
        }

        return view('client.reviews.market-create', compact('nightMarket') + ['tagOptions' => Review::marketTagOptions()]);
    }

    public function storeMarket(StoreReviewRequest $request, NightMarket $nightMarket): RedirectResponse
    {
        $nightMarket = $this->reviewService->findPubliclyVisibleMarket($nightMarket->id);
        $this->reviewService->createMarketForClient($request->user(), $nightMarket, $request->validated());

        return redirect()->route('night-markets.show', $nightMarket)->with('status', 'Your market review has been published.');
    }

    public function editMarket(Request $request, NightMarket $nightMarket, Review $review): View
    {
        $nightMarket = $this->reviewService->findPubliclyVisibleMarket($nightMarket->id);
        abort_unless($review->user_id === $request->user()->id, 403);
        abort_unless($review->night_market_id === $nightMarket->id && $review->food_id === null, 404);
        abort_unless($review->review_date?->isSameDay(Review::currentReviewDate()), 403);

        return view('client.reviews.market-edit', compact('nightMarket', 'review') + ['tagOptions' => Review::marketTagOptions()]);
    }

    public function updateMarket(UpdateReviewRequest $request, NightMarket $nightMarket, Review $review): RedirectResponse
    {
        $nightMarket = $this->reviewService->findPubliclyVisibleMarket($nightMarket->id);
        $this->reviewService->updateMarketForClient($request->user(), $nightMarket, $review, $request->validated());

        return redirect()->route('night-markets.show', $nightMarket)->with('status', 'Your market review has been updated and remains published.');
    }
}
