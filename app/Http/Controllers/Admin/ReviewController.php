<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Review\ModerateReviewRequest;
use App\Models\Review;
use App\Services\ReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ReviewController extends Controller
{
    public function __construct(private readonly ReviewService $reviewService) {}

    public function index(): View
    {
        return view('admin.reviews.index', [
            'reviews' => $this->reviewService->pendingReviews(),
        ]);
    }

    public function update(ModerateReviewRequest $request, Review $review): RedirectResponse
    {
        $status = $request->validated('status');

        $this->reviewService->moderate($review, $status);

        return redirect()
            ->route('admin.reviews.index')
            ->with('status', 'The review has been '.($status === Review::STATUS_APPROVED ? 'approved.' : 'rejected.'));
    }
}
