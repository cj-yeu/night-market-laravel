@extends('layouts.app')

@section('title', 'My Reviews | Night Market Selangor')

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-lg-8">
            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-start gap-3 mb-4">
                <div>
                    <h1 class="h2 fw-bold text-market mb-1">My Reviews</h1>
                    <p class="text-secondary mb-0">Your Food and Night Market review history.</p>
                </div>
                <a href="{{ route('profile.edit') }}" class="btn btn-outline-secondary">Back to Profile</a>
            </div>

            <nav class="d-flex flex-wrap gap-2 mb-4" aria-label="Review type filter">
                @foreach (['all' => 'All Reviews', 'food' => 'Food Reviews', 'market' => 'Night Market Reviews'] as $value => $label)
                    <a href="{{ route('client.reviews.index', $value === 'all' ? [] : ['type' => $value]) }}"
                        class="btn {{ $type === $value ? 'btn-market' : 'btn-outline-secondary' }}">{{ $label }}</a>
                @endforeach
            </nav>

            @if ($reviews->isEmpty())
                <div class="alert alert-info text-center mb-0">
                    <i class="bi bi-chat-square-text fs-3 d-block mb-2" aria-hidden="true"></i>
                    No {{ $type === 'all' ? '' : $type.' ' }}reviews yet.
                </div>
            @else
                <div class="vstack gap-3">
                    @foreach ($reviews as $review)
                        @php($isFoodReview = $review->isFoodReview())
                        @php($target = $isFoodReview ? $review->food : $review->nightMarket)
                        <article class="card market-card">
                            <div class="card-body p-4">
                                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-2">
                                    <div>
                                        <span class="badge {{ $isFoodReview ? 'text-bg-warning' : 'text-bg-info' }} mb-2">
                                            {{ $isFoodReview ? 'Food Review' : 'Night Market Review' }}
                                        </span>
                                        <h2 class="h5 fw-bold mb-0">{{ $target?->name ?? 'Unavailable review target' }}</h2>
                                        @if ($isFoodReview && $review->food?->stall)
                                            <p class="small text-secondary mb-0">{{ $review->food->stall->name }}</p>
                                        @endif
                                    </div>
                                    <span class="badge text-bg-warning" aria-label="{{ $review->rating }} out of 5 stars">
                                        <span aria-hidden="true">{{ str_repeat('★', $review->rating) }}{{ str_repeat('☆', 5 - $review->rating) }}</span>
                                        <span class="visually-hidden">{{ $review->rating }} out of 5 stars</span>
                                    </span>
                                </div>
                                <p class="mb-2">{{ $review->comment }}</p>
                                @if ($review->tags)
                                    <div class="d-flex flex-wrap gap-1 mb-2">
                                        @foreach ($review->tags as $tag)
                                            @php($labels = $isFoodReview ? \App\Models\Review::FOOD_TAGS : \App\Models\Review::MARKET_TAGS)
                                            @if (isset($labels[$tag]))
                                                <x-review-tag :tag="$tag" :label="$labels[$tag]" />
                                            @endif
                                        @endforeach
                                    </div>
                                @endif
                                <small class="text-secondary d-block mb-3">
                                    Reviewed {{ $review->review_date->format('M j, Y') }}
                                    @if ($review->updated_at->gt($review->created_at)) · Updated {{ $review->updated_at->format('M j, Y') }} @endif
                                </small>
                                <div class="d-flex flex-wrap gap-2">
                                    @if ($target)
                                        <a href="{{ $isFoodReview ? route('foods.show', $target) : route('night-markets.show', $target) }}" class="btn btn-outline-secondary btn-sm">View Details</a>
                                    @endif
                                    @if ($review->review_date->isSameDay(\App\Models\Review::currentReviewDate()) && $target)
                                        <a href="{{ $isFoodReview ? route('client.foods.reviews.edit', [$target, $review]) : route('client.night-markets.reviews.edit', [$target, $review]) }}" class="btn btn-market btn-sm">Edit</a>
                                    @endif
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
                <div class="mt-4">{{ $reviews->links() }}</div>
            @endif
        </div>
    </div>
@endsection
