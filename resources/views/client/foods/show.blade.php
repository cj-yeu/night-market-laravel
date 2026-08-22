@extends('layouts.app')

@section('title', $food->name.' | Night Market Selangor')

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-lg-8">
            <div class="d-flex flex-wrap gap-2 mb-4">
                <a href="{{ route('foods.index', ['stall_id' => $food->stall->id, 'night_market_id' => $food->stall->night_market_id]) }}"
                    class="btn btn-outline-secondary">Foods at {{ $food->stall->name }}</a>
                <a href="{{ route('night-markets.stalls.index', $food->stall->night_market_id) }}"
                    class="btn btn-outline-secondary">Back to Stalls</a>
                <a href="{{ route('night-markets.show', $food->stall->night_market_id) }}"
                    class="btn btn-outline-secondary">Back to Market</a>
                @foreach ($reviewActions as $reviewAction)
                    <a href="{{ $reviewAction['url'] }}" class="btn btn-outline-secondary">{{ $reviewAction['label'] }}</a>
                @endforeach
                <a href="{{ route('client.visit-plans.index', ['item_type' => 'food', 'item_id' => $food->id]) }}"
                    class="btn btn-market">Add to Visit Plan</a>
            </div>

            <div class="card market-card">
                <x-food-image :food="$food" class="rounded-top-3" loading="eager" />
                <div class="card-body p-4 p-md-5">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                        @if ($food->category)
                            <span class="badge text-bg-secondary">{{ $food->category }}</span>
                        @endif
                        @if ($food->is_must_try)
                            <span class="badge text-bg-warning">Must-Try</span>
                        @endif
                    </div>

                    <h1 class="display-6 fw-bold text-market">{{ $food->name }}</h1>

                    <dl class="row mt-4 mb-0">
                        <dt class="col-sm-3">Stall</dt>
                        <dd class="col-sm-9"><a href="{{ route('foods.index', ['stall_id' => $food->stall->id]) }}">{{ $food->stall->name }}</a></dd>

                        <dt class="col-sm-3">Night Market</dt>
                        <dd class="col-sm-9"><a href="{{ route('night-markets.show', $food->stall->nightMarket) }}">{{ $food->stall->nightMarket->name }}</a></dd>

                        <dt class="col-sm-3">Category</dt>
                        <dd class="col-sm-9">{{ $food->category ?: 'Not specified' }}</dd>

                        <dt class="col-sm-3">Price</dt>
                        <dd class="col-sm-9"><x-food-price :food="$food" /></dd>

                        @if ($food->price_checked_at)
                            <dt class="col-sm-3">Price checked</dt>
                            <dd class="col-sm-9">{{ $food->price_checked_at->format('M j, Y') }}</dd>
                        @endif

                        @if ($food->is_must_try && $food->recommendation_reason)
                            <dt class="col-sm-3">Why it is recommended</dt>
                            <dd class="col-sm-9">{{ $food->recommendation_reason }}</dd>
                        @endif

                        <dt class="col-sm-3">Stall Halal status</dt>
                        <dd class="col-sm-9">
                            <x-halal-status :stall="$food->stall" />
                            <span class="small text-secondary d-block mt-1">This classification belongs to the parent stall; it is not a separate Food certification.</span>
                            @if ($food->stall->hasCurrentHalalEvidence())
                                <a href="{{ $food->stall->halalEvidenceUrl() }}" target="_blank" rel="noopener noreferrer">View Stall Halal evidence</a>
                            @endif
                        </dd>

                        @if ($food->verified_at)
                            <dt class="col-sm-3">Verified</dt>
                            <dd class="col-sm-9">{{ $food->verified_at->format('M j, Y') }}</dd>
                        @endif

                        @if ($food->sourceUrl())
                            <dt class="col-sm-3">Source</dt>
                            <dd class="col-sm-9"><a href="{{ $food->sourceUrl() }}" target="_blank" rel="noopener noreferrer">View source</a></dd>
                        @endif

                        <dt class="col-sm-3">Description</dt>
                        <dd class="col-sm-9 mb-0">{{ $food->description ?: 'No description available.' }}</dd>
                    </dl>
                </div>
            </div>

            <section class="card market-card mt-4" aria-labelledby="food-reviews-heading">
                <div class="card-body p-4 p-md-5">
                    <div class="d-flex flex-column flex-sm-row justify-content-between gap-2 mb-4">
                        <div>
                            <h2 id="food-reviews-heading" class="h4 fw-bold text-market mb-1">Food Reviews</h2>
                            <p class="text-secondary mb-0">Directly published feedback for {{ $food->name }}.</p>
                        </div>
                        @if ($reviewCount > 0)
                            <div class="text-sm-end">
                                <strong>{{ number_format($averageRating, 1) }}/5</strong>
                                <span class="text-secondary">from {{ $reviewCount }} {{ $reviewCount === 1 ? 'review' : 'reviews' }}</span>
                            </div>
                        @endif
                    </div>
                    @if ($reviews->isEmpty())
                        <div class="alert alert-info mb-0">No reviews yet. Be the first verified Client to review this Food.</div>
                    @else
                        <div class="row g-2 mb-4" aria-label="Rating distribution">
                            @foreach ($ratingDistribution as $rating => $count)
                                <div class="col-12 col-sm">
                                    <div class="border rounded-3 p-2 text-center">
                                        <strong>{{ $rating }} star</strong>
                                        <span class="d-block text-secondary">{{ $count }}</span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div class="vstack gap-3">
                            @foreach ($reviews as $review)
                                <article class="border rounded-3 p-3 bg-white">
                                    <div class="d-flex justify-content-between gap-3 mb-2">
                                        <div class="d-flex align-items-center gap-2"><x-user-avatar :user="$review->user" size="sm" /><strong>{{ $review->user->name }}</strong></div>
                                        <span class="badge text-bg-warning" aria-label="{{ $review->rating }} out of 5 stars">
                                            <span aria-hidden="true">{{ str_repeat('★', $review->rating) }}{{ str_repeat('☆', 5 - $review->rating) }}</span>
                                            <span class="visually-hidden">{{ $review->rating }} out of 5 stars</span>
                                        </span>
                                    </div>
                                    <p class="mb-1">{{ $review->comment }}</p>
                                    <small class="text-secondary">
                                        {{ $review->created_at->format('M j, Y') }}
                                        @if ($review->updated_at->gt($review->created_at))
                                            · Updated {{ $review->updated_at->format('M j, Y') }}
                                        @endif
                                    </small>
                                </article>
                            @endforeach
                        </div>
                        <div class="mt-4">{{ $reviews->links() }}</div>
                    @endif
                </div>
            </section>
        </div>
    </div>
@endsection
