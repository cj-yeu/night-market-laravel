@extends('layouts.app')

@section('title', 'Review Management | Night Market Selangor')

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold text-market mb-1">Review Management</h1>
            <p class="text-secondary mb-0">View published and legacy reviews. Admins may delete inappropriate content only.</p>
        </div>
    </div>

    <div class="card market-card mb-4">
        <div class="card-body p-4">
            <form method="GET" action="{{ route('admin.reviews.index') }}">
                <div class="row g-3 align-items-end">
                    <div class="col-12 col-lg-4">
                        <label for="search" class="form-label">Reviewer or comment</label>
                        <input type="search" class="form-control @error('search') is-invalid @enderror"
                            id="search" name="search" value="{{ $filters['search'] ?? '' }}" maxlength="255">
                        @error('search')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12 col-md-4 col-lg-2">
                        <label for="market_id" class="form-label">Night Market</label>
                        <select class="form-select" id="market_id" name="market_id">
                            <option value="">All Markets</option>
                            @foreach ($markets as $market)
                                <option value="{{ $market->id }}" @selected((int) ($filters['market_id'] ?? 0) === $market->id)>{{ $market->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12 col-md-4 col-lg-2">
                        <label for="stall_id" class="form-label">Stall</label>
                        <select class="form-select" id="stall_id" name="stall_id">
                            <option value="">All Stalls</option>
                            @foreach ($stalls as $stall)
                                <option value="{{ $stall->id }}" @selected((int) ($filters['stall_id'] ?? 0) === $stall->id)>{{ $stall->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12 col-md-4 col-lg-2">
                        <label for="food_id" class="form-label">Food</label>
                        <select class="form-select" id="food_id" name="food_id">
                            <option value="">All Foods</option>
                            @foreach ($foods as $food)
                                <option value="{{ $food->id }}" @selected((int) ($filters['food_id'] ?? 0) === $food->id)>{{ $food->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-3 col-lg-2">
                        <label for="rating" class="form-label">Rating</label>
                        <select class="form-select" id="rating" name="rating">
                            <option value="">All Ratings</option>
                            @for ($rating = 5; $rating >= 1; $rating--)
                                <option value="{{ $rating }}" @selected((int) ($filters['rating'] ?? 0) === $rating)>{{ $rating }} stars</option>
                            @endfor
                        </select>
                    </div>
                    <div class="col-6 col-md-3 col-lg-2">
                        <label for="date_from" class="form-label">From</label>
                        <input type="date" class="form-control" id="date_from" name="date_from" value="{{ $filters['date_from'] ?? '' }}">
                    </div>
                    <div class="col-6 col-md-3 col-lg-2">
                        <label for="date_to" class="form-label">To</label>
                        <input type="date" class="form-control" id="date_to" name="date_to" value="{{ $filters['date_to'] ?? '' }}">
                    </div>
                    <div class="col-12 col-md-6 d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-market">Apply Filters</button>
                        @if ($hasFilters)
                            <a href="{{ route('admin.reviews.index') }}" class="btn btn-outline-secondary">Reset Filters</a>
                        @endif
                    </div>
                </div>
            </form>
        </div>
    </div>

    @if ($reviews->isEmpty())
        <div class="alert alert-info text-center py-4" role="status">
            <h2 class="h5 mb-2">No reviews found</h2>
            <p class="mb-0">No reviews match the selected filters.</p>
        </div>
    @else
        <div class="row g-4">
            @foreach ($reviews as $review)
                <div class="col-12 col-xl-6">
                    <article class="card market-card h-100">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between gap-3 mb-3">
                                <div class="d-flex align-items-center gap-2">
                                    <x-user-avatar :user="$review->user" size="sm" />
                                    <div>
                                        <h2 class="h6 fw-bold mb-0">{{ $review->user->name }}</h2>
                                        <span class="small text-secondary">{{ $review->food?->name ?? 'Legacy unassigned review' }}</span>
                                    </div>
                                </div>
                                <span class="badge text-bg-warning" aria-label="{{ $review->rating }} out of 5 stars">{{ $review->rating }}/5 stars</span>
                            </div>

                            <dl class="row small mb-3">
                                <dt class="col-4">Stall</dt><dd class="col-8">{{ $review->food?->stall?->name ?? 'Not assigned' }}</dd>
                                <dt class="col-4">Night Market</dt><dd class="col-8">{{ $review->food?->stall?->nightMarket?->name ?? $review->nightMarket?->name ?? 'Not assigned' }}</dd>
                            </dl>
                            <p class="mb-2">{{ \Illuminate\Support\Str::limit($review->comment, 240) }}</p>
                            <p class="small text-secondary mb-4">
                                Submitted {{ $review->created_at->format('M j, Y') }}
                                @if ($review->updated_at->gt($review->created_at))
                                    · Updated {{ $review->updated_at->format('M j, Y') }}
                                @endif
                            </p>

                            <form method="POST" action="{{ route('admin.reviews.destroy', $review) }}"
                                onsubmit="return confirm('Permanently delete this review?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-outline-danger">Delete Review</button>
                            </form>
                        </div>
                    </article>
                </div>
            @endforeach
        </div>
        <div class="mt-4">{{ $reviews->links() }}</div>
    @endif
@endsection
