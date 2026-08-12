<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Review\ModerateReviewRequest;
use App\Http\Requests\Review\ReviewManagementFilterRequest;
use App\Models\Review;
use App\Services\ReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ReviewController extends Controller
{
    public function __construct(private readonly ReviewService $reviewService) {}

    public function index(ReviewManagementFilterRequest $request): View
    {
        $filters = $request->validated();

        return view('admin.reviews.index', [
            'reviews' => $this->reviewService->reviewsForModeration($filters),
            'markets' => $this->reviewService->marketsWithReviews(),
            'statusOptions' => $this->reviewService->moderationStatusOptions(),
            'filters' => $filters,
        ]);
    }

    public function update(ModerateReviewRequest $request, Review $review): RedirectResponse
    {
        $status = $request->validated('status');
        $action = $status === Review::STATUS_APPROVED
            ? 'approved'
            : ($review->status === Review::STATUS_APPROVED ? 'unapproved' : 'rejected');

        $this->reviewService->moderate($review, $status);

        return redirect()
            ->route('admin.reviews.index')
            ->with('status', 'The review has been '.$action.'.');
    }
}
