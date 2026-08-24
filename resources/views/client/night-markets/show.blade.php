@extends('layouts.app')

@section('title', $nightMarket->name.' | Night Market Selangor')

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-xl-9">
            <a href="{{ route('night-markets.index') }}"
                class="btn btn-outline-secondary mb-4">Back to Night Markets</a>

            <div class="card market-card overflow-hidden mb-4">
                <x-night-market-image :night-market="$nightMarket" loading="eager" />
                <div class="card-body p-4 p-md-5">
                    <span class="badge text-bg-warning mb-3">{{ $nightMarket->city }}</span>
                    <h1 class="display-6 fw-bold text-market">{{ $nightMarket->name }}</h1>

                    <div class="d-flex flex-wrap gap-2 mt-3">
                        @if (Route::has('night-markets.stalls.index'))
                            <a href="{{ route('night-markets.stalls.index', $nightMarket->id) }}"
                                class="btn btn-market">Browse Stalls</a>
                        @endif
                        <a href="{{ route('client.visit-plans.create', ['night_market_id' => $nightMarket->id]) }}"
                            class="btn btn-outline-secondary">Plan a Visit to This Market</a>
                        @if ($nightMarket->googleMapsUrl())
                            <a href="{{ $nightMarket->googleMapsUrl() }}" class="btn btn-outline-secondary"
                                target="_blank" rel="noopener noreferrer">View on Google Maps</a>
                        @endif
                    </div>

                    <dl class="row mt-4 mb-0">
                        <dt class="col-sm-3">Address</dt>
                        <dd class="col-sm-9">{{ $nightMarket->address }}</dd>

                        <dt class="col-sm-3">District</dt>
                        <dd class="col-sm-9">{{ $nightMarket->city }}, {{ $nightMarket->state }}</dd>

                        @if ($nightMarket->verified_at)
                            <dt class="col-sm-3">Last verified</dt>
                            <dd class="col-sm-9">{{ $nightMarket->verified_at->format('M j, Y') }}</dd>
                        @endif

                        <dt class="col-sm-3">Description</dt>
                        <dd class="col-sm-9 mb-0">{{ $nightMarket->description ?: 'No description available.' }}</dd>
                    </dl>
                </div>
            </div>

            <div class="card market-card mb-4">
                <div class="card-body p-4 p-md-5">
                    <h2 class="h4 fw-bold text-market mb-4">Operating Hours</h2>

                    <x-night-market-schedule :operating-days="$nightMarket->operatingDays" />
                </div>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-12 col-lg-6">
                    <section class="card market-card h-100">
                        <div class="card-body p-4 p-md-5">
                            <h2 class="h4 fw-bold text-market mb-3">Active Stalls</h2>

                            @if ($activeStalls->isEmpty())
                                <div class="alert alert-secondary mb-0">
                                    No active stalls are currently listed for this market.
                                </div>
                            @else
                                <div class="vstack gap-3">
                                    @foreach ($activeStalls as $stall)
                                        <article class="border rounded-3 bg-white p-3">
                                            <x-stall-image :stall="$stall" class="rounded-3 mb-3" />
                                            <h3 class="h6 fw-bold mb-1">{{ $stall->name }}</h3>
                                            <p class="text-secondary mb-0">
                                                {{ $stall->description ?: 'No stall description available.' }}
                                            </p>
                                            <a href="{{ route('foods.index', ['stall_id' => $stall->id, 'night_market_id' => $nightMarket->id]) }}"
                                                class="btn btn-sm btn-outline-secondary mt-3">Browse this Stall’s Foods</a>
                                        </article>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </section>
                </div>

                <div class="col-12 col-lg-6">
                    <section class="card market-card h-100">
                        <div class="card-body p-4 p-md-5">
                            <h2 class="h4 fw-bold text-market mb-3">Must-Try Foods</h2>

                            @if ($mustTryFoods->isEmpty())
                                <div class="alert alert-secondary mb-0">
                                    No active must-try foods are currently listed for this market.
                                </div>
                            @else
                                <div class="vstack gap-3">
                                    @foreach ($mustTryFoods as $food)
                                        <article class="border rounded-3 bg-white p-3">
                                            <x-food-image :food="$food" class="rounded-3 mb-3" />
                                            <div class="d-flex justify-content-between gap-2 mb-1">
                                                <h3 class="h6 fw-bold mb-0">{{ $food->name }}</h3>
                                                <span class="badge text-bg-warning">Must-Try</span>
                                            </div>
                                            @if ($food->category)
                                                <div class="small text-market fw-semibold mb-1">{{ $food->category }}</div>
                                            @endif
                                            <p class="text-secondary mb-0">
                                                {{ $food->description ?: 'No food description available.' }}
                                            </p>
                                            @if ($food->recommendation_reason)
                                                <p class="small border-start border-warning border-3 ps-3 mt-2 mb-0">
                                                    <strong>Why try it:</strong> {{ $food->recommendation_reason }}
                                                </p>
                                            @endif
                                            <a href="{{ route('foods.show', $food) }}" class="btn btn-sm btn-outline-secondary mt-3">View Food Details</a>
                                        </article>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </section>
                </div>
            </div>

            <section class="card market-card mb-4" aria-labelledby="market-reviews-heading">
                <div class="card-body p-4 p-md-5">
                    <div class="d-flex flex-column flex-sm-row justify-content-between gap-2 mb-4">
                        <div>
                            <h2 id="market-reviews-heading" class="h4 fw-bold text-market mb-1">Night Market Reviews</h2>
                            <p class="text-secondary mb-0">Directly published visitor feedback for {{ $nightMarket->name }}.</p>
                            @foreach ($reviewActions as $reviewAction)
                                <a href="{{ $reviewAction['url'] }}" class="btn btn-market mt-3">{{ $reviewAction['label'] }}</a>
                            @endforeach
                        </div>
                        @if ($reviewCount > 0)<div><strong>{{ number_format($averageRating, 1) }}/5</strong> <span class="text-secondary">from {{ $reviewCount }} {{ $reviewCount === 1 ? 'review' : 'reviews' }}</span></div>@endif
                    </div>
                    @if ($reviews->isEmpty())
                        <div class="alert alert-info text-center mb-0"><i class="bi bi-chat-square-text fs-3 d-block mb-2" aria-hidden="true"></i>No market reviews yet. Be the first verified Client to share feedback.</div>
                    @else
                        <div class="row g-2 mb-4" aria-label="Market rating distribution">@foreach ($ratingDistribution as $rating => $count)<div class="col-12 col-sm"><div class="border rounded-3 p-2 text-center"><strong>{{ $rating }} star</strong><span class="d-block text-secondary">{{ $count }}</span></div></div>@endforeach</div>
                        <div class="vstack gap-3">@foreach ($reviews as $review)<article class="border rounded-3 p-3 bg-white"><div class="d-flex justify-content-between gap-3 mb-2"><div class="d-flex align-items-center gap-2"><x-user-avatar :user="$review->user" size="sm" /><strong>{{ $review->user->name }}</strong></div><span class="badge text-bg-warning" aria-label="{{ $review->rating }} out of 5 stars">{{ $review->rating }}/5</span></div><p class="mb-1">{{ $review->comment }}</p>@if ($review->tags)<div class="d-flex flex-wrap gap-1 mb-2">@foreach ($review->tags as $tag)@if (isset(\App\Models\Review::MARKET_TAGS[$tag]))<x-review-tag :tag="$tag" :label="\App\Models\Review::MARKET_TAGS[$tag]" />@endif @endforeach</div>@endif<small class="text-secondary">Reviewed {{ $review->review_date->format('M j, Y') }}@if ($review->updated_at->gt($review->created_at)) · Updated {{ $review->updated_at->format('M j, Y') }}@endif</small></article>@endforeach</div>
                        <div class="mt-4">{{ $reviews->links() }}</div>
                    @endif
                </div>
            </section>

        </div>
    </div>
@endsection
