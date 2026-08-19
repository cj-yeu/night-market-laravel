<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
            'reviews' => $this->reviewService->reviewsForManagement($filters),
            ...$this->reviewService->managementFilterOptions(),
            'filters' => $filters,
            'hasFilters' => collect($filters)->contains(fn ($value) => $value !== null && $value !== ''),
        ]);
    }

    public function destroy(Review $review): RedirectResponse
    {
        $this->reviewService->delete($review);

        return redirect()
            ->route('admin.reviews.index')
            ->with('status', 'The review has been deleted.');
    }
}
