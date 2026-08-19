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
                        @if (Route::has('client.night-markets.reviews.create'))
                            <a href="{{ route('client.night-markets.reviews.create', $nightMarket) }}"
                                class="btn btn-outline-secondary">Write a Review</a>
                        @endif
                        @if (Route::has('client.visit-plans.create'))
                            <a href="{{ route('client.visit-plans.create') }}"
                                class="btn btn-outline-secondary">Create Visit Plan</a>
                        @endif
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
                                            <h3 class="h6 fw-bold mb-1">{{ $stall->name }}</h3>
                                            <p class="text-secondary mb-0">
                                                {{ $stall->description ?: 'No stall description available.' }}
                                            </p>
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
                                        </article>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </section>
                </div>
            </div>

            <div class="card market-card">
                <div class="card-body p-4 p-md-5">
                    <div class="d-flex flex-column flex-sm-row justify-content-between gap-3 mb-4">
                        <div>
                            <h2 class="h4 fw-bold text-market mb-1">Approved Reviews</h2>
                            <p class="text-secondary mb-0">Feedback shared by verified client accounts.</p>
                        </div>

                        @if ($reviewCount > 0)
                            <div class="text-sm-end">
                                <div class="fs-4 fw-bold text-market">{{ number_format($averageRating, 1) }}/5</div>
                                <div class="small text-secondary">
                                    {{ $reviewCount }} {{ $reviewCount === 1 ? 'review' : 'reviews' }}
                                </div>
                            </div>
                        @endif
                    </div>

                    @if ($reviews->isEmpty())
                        <div class="alert alert-info mb-0" role="alert">
                            No approved reviews yet. Be the first to submit a review for moderation.
                        </div>
                    @else
                        <div class="vstack gap-3">
                            @foreach ($reviews as $review)
                                <article class="border rounded-3 p-3 bg-white">
                                    <div class="d-flex justify-content-between gap-3 mb-2">
                                        <div class="d-flex align-items-center gap-2">
                                            <x-user-avatar :user="$review->user" size="sm" />
                                            <strong>{{ $review->user->name }}</strong>
                                        </div>
                                        <span class="badge text-bg-warning">{{ $review->rating }}/5 stars</span>
                                    </div>
                                    <p class="mb-1">{{ $review->comment }}</p>
                                    <small class="text-secondary">{{ $review->created_at->format('M j, Y') }}</small>
                                </article>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
