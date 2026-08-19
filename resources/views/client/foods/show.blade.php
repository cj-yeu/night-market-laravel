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
                @if (Route::has('client.night-markets.reviews.create'))
                    <a href="{{ route('client.night-markets.reviews.create', $food->stall->nightMarket) }}"
                        class="btn btn-outline-secondary">Write a Review</a>
                @endif
                @if (Route::has('client.visit-plans.index'))
                    <a href="{{ route('client.visit-plans.index') }}"
                        class="btn btn-market">Add to Visit Plan</a>
                @endif
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

            <section class="card market-card mt-4" aria-labelledby="food-market-reviews-heading">
                <div class="card-body p-4 p-md-5">
                    <div class="d-flex flex-column flex-sm-row justify-content-between gap-2 mb-4">
                        <div>
                            <h2 id="food-market-reviews-heading" class="h4 fw-bold text-market mb-1">Approved Market Reviews</h2>
                            <p class="text-secondary mb-0">Approved reviews for {{ $food->stall->nightMarket->name }}.</p>
                        </div>
                        @if ($reviewCount > 0)
                            <div><strong>{{ number_format($averageRating, 1) }}/5</strong> · {{ $reviewCount }} {{ $reviewCount === 1 ? 'review' : 'reviews' }}</div>
                        @endif
                    </div>
                    @if ($reviews->isEmpty())
                        <div class="alert alert-info mb-0">No approved reviews are available for this market yet.</div>
                    @else
                        <div class="vstack gap-3">
                            @foreach ($reviews as $review)
                                <article class="border rounded-3 p-3 bg-white">
                                    <div class="d-flex justify-content-between gap-3 mb-2">
                                        <div class="d-flex align-items-center gap-2"><x-user-avatar :user="$review->user" size="sm" /><strong>{{ $review->user->name }}</strong></div>
                                        <span class="badge text-bg-warning">{{ $review->rating }}/5 stars</span>
                                    </div>
                                    <p class="mb-1">{{ $review->comment }}</p>
                                    <small class="text-secondary">{{ $review->created_at->format('M j, Y') }}</small>
                                </article>
                            @endforeach
                        </div>
                    @endif
                </div>
            </section>
        </div>
    </div>
@endsection
