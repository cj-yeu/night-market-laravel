<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Review\StoreReviewRequest;
use App\Http\Requests\Review\UpdateReviewRequest;
use App\Models\Food;
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

        return view('client.reviews.edit', compact('food', 'review'));
    }

    public function update(UpdateReviewRequest $request, Food $food, Review $review): RedirectResponse
    {
        $food = $this->reviewService->findPubliclyVisibleFood($food->id);
        $this->reviewService->updateForClient($request->user(), $food, $review, $request->validated());

        return redirect()
            ->route('foods.show', $food)
            ->with('status', 'Your review has been updated and remains published.');
    }
}
