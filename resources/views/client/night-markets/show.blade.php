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

            <section class="card market-card mb-4" aria-labelledby="market-social-media-heading">
                <div class="card-body p-4 p-md-5">
                    <h2 id="market-social-media-heading" class="h4 fw-bold text-market mb-1">Social Media Highlights</h2>
                    <p class="text-secondary mb-4">Administrator-approved public posts about {{ $nightMarket->name }}.</p>

                    @if ($socialMediaHighlights->isEmpty())
                        <div class="alert alert-secondary mb-0">
                            No approved social-media highlights for this market yet.
                        </div>
                    @else
                        <div class="row g-3">
                            @foreach ($socialMediaHighlights as $highlight)
                                <div class="col-12 col-lg-6">
                                    <article class="border rounded-3 bg-white p-3 h-100 d-flex flex-column">
                                        <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                                            <span class="badge text-bg-warning">{{ $highlight->platform }}</span>
                                            <span class="text-secondary small">
                                                Published {{ $highlight->posted_date->format('d M Y') }}
                                            </span>
                                        </div>
                                        <h3 class="h6 fw-bold mb-1">
                                            {{ $highlight->extracted_title ?: $nightMarket->name }}
                                        </h3>
                                        @if ($highlight->food)
                                            <p class="small fw-semibold mb-2">Featured food: {{ $highlight->food->name }}</p>
                                        @endif
                                        <p class="text-secondary small mb-3">
                                            {{ \Illuminate\Support\Str::limit($highlight->content_summary, 200) }}
                                        </p>
                                        @if ($highlight->safe_source_url)
                                            <a href="{{ $highlight->safe_source_url }}" target="_blank"
                                                rel="noopener noreferrer"
                                                class="btn btn-sm btn-outline-secondary mt-auto">Open Original Post</a>
                                        @endif
                                    </article>
                                </div>
                            @endforeach
                        </div>
                        @if (Route::has('social-media-highlights.index'))
                            <a href="{{ route('social-media-highlights.index') }}"
                                class="btn btn-outline-secondary mt-4">View All Social Media Highlights</a>
                        @endif
                    @endif
                </div>
            </section>

        </div>
    </div>
@endsection
