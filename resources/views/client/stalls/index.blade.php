@extends('layouts.app')

@section('title', $nightMarket->name.' Stalls | Night Market Selangor')

@section('content')
    <div class="d-flex flex-column flex-sm-row justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold text-market mb-1">Stalls at {{ $nightMarket->name }}</h1>
            <p class="text-secondary mb-0">Browse active stalls and their food items.</p>
        </div>
        <div class="d-flex flex-wrap gap-2 align-self-start">
            <a href="{{ route('stalls.index', ['night_market_id' => $nightMarket->id]) }}"
                class="btn btn-outline-secondary">Explore Stall Directory</a>
            <a href="{{ route('night-markets.show', $nightMarket->id) }}"
                class="btn btn-outline-secondary">Back to Market Details</a>
            <a href="{{ route('client.visit-plans.create', ['night_market_id' => $nightMarket->id]) }}"
                class="btn btn-market">Plan a Visit to This Market</a>
        </div>
    </div>

    <div class="card market-card mb-4">
        <div class="card-body p-4">
            <form method="GET" action="{{ route('night-markets.stalls.index', $nightMarket->id) }}">
                <div class="row g-3 align-items-end">
                    <div class="col-12 col-lg-6">
                        <label for="search" class="form-label">Search</label>
                        <input type="search" class="form-control @error('search') is-invalid @enderror"
                            id="search" name="search" value="{{ $filters['search'] ?? '' }}"
                            placeholder="Search stall or food name and description">
                        @error('search')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-12 col-md-6 col-lg-3">
                        <label for="category" class="form-label">Food Category</label>
                        <select class="form-select @error('category') is-invalid @enderror"
                            id="category" name="category">
                            <option value="">All categories</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->category }}"
                                    @selected(($filters['category'] ?? '') === $category->category)>
                                    {{ $category->category }}
                                </option>
                            @endforeach
                        </select>
                        @error('category')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-12 col-md-6 col-lg-3 d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-market">Apply Filters</button>
                        @if (($filters['search'] ?? null) || ($filters['category'] ?? null))
                            <a href="{{ route('night-markets.stalls.index', $nightMarket->id) }}"
                                class="btn btn-outline-secondary" aria-label="Clear Filters">Reset Search/Filters</a>
                        @endif
                    </div>
                </div>
            </form>
        </div>
    </div>

    @if ($stalls->isEmpty())
        <div class="alert alert-warning text-center py-4" role="status">
            <h2 class="h5 mb-2">No stalls or foods found</h2>
            <p class="mb-3">Try changing your keyword search or food category filter.</p>
            <a href="{{ route('night-markets.stalls.index', $nightMarket->id) }}"
                class="btn btn-outline-secondary" aria-label="Clear Filters">Reset Search/Filters</a>
        </div>
    @else
        <div class="row g-4">
            @foreach ($stalls as $stall)
                <div class="col-12 col-lg-6">
                    <article class="card h-100 market-card">
                        <x-stall-image :stall="$stall" class="rounded-top-3" />
                        <div class="card-body p-4">
                            <h2 class="h4 fw-bold">{{ $stall->name }}</h2>
                            <p class="small text-market fw-semibold mb-2">
                                Night Market: {{ $nightMarket->name }}
                            </p>
                            <div class="d-flex flex-wrap gap-2 mb-3">
                                @if ($stall->category)
                                    <span class="badge text-bg-light border">{{ $stall->category }}</span>
                                @endif
                                <x-halal-status :stall="$stall" />
                            </div>
                            <p class="text-secondary">{{ $stall->description ?: 'No stall description available.' }}</p>
                            <a href="{{ route('foods.index', ['stall_id' => $stall->id, 'night_market_id' => $nightMarket->id]) }}"
                                class="btn btn-sm btn-outline-secondary mb-3">Browse this Stall’s Foods</a>
                            <a href="{{ route('client.visit-plans.index', ['item_type' => 'stall', 'item_id' => $stall->id]) }}"
                                class="btn btn-sm btn-outline-secondary mb-3">Add Stall to Visit Plan</a>
                            @if ($stall->hasCurrentHalalEvidence() || $stall->sourceUrl() || $stall->verified_at)
                                <div class="small text-secondary mb-3">
                                    @if ($stall->verified_at)
                                        <span class="d-block">Verified {{ $stall->verified_at->format('M j, Y') }}</span>
                                    @endif
                                    @if ($stall->hasCurrentHalalEvidence())
                                        <a href="{{ $stall->halalEvidenceUrl() }}" target="_blank" rel="noopener noreferrer">View Halal evidence</a>
                                    @endif
                                    @if ($stall->sourceUrl())
                                        <a href="{{ $stall->sourceUrl() }}" target="_blank" rel="noopener noreferrer" class="ms-2">View source</a>
                                    @endif
                                </div>
                            @endif

                            <h3 class="h6 text-market fw-bold mt-4">Food Items &amp; Must-Try Foods</h3>
                            @if ($stall->foods->isEmpty())
                                <p class="text-secondary mb-0">No active food items are listed for this stall.</p>
                            @else
                                <div class="vstack gap-2">
                                    @foreach ($stall->foods as $food)
                                        <div class="border rounded-3 p-3 bg-white">
                                            <x-food-image :food="$food" class="rounded-3 mb-3" />
                                            <div class="d-flex justify-content-between align-items-start gap-3">
                                                <div>
                                                    <span class="fw-semibold">{{ $food->name }}</span>
                                                    @if ($food->category)
                                                        <span class="text-secondary d-block small">{{ $food->category }}</span>
                                                    @endif
                                                    <x-food-price :food="$food" class="small text-market fw-semibold d-block mt-1" />
                                                    @if ($food->is_must_try && $food->recommendation_reason)
                                                        <span class="small text-secondary d-block mt-1">{{ $food->recommendation_reason }}</span>
                                                    @endif
                                                </div>
                                                @if ($food->is_must_try)
                                                    <span class="badge text-bg-warning">Must-Try</span>
                                                @endif
                                            </div>
                                            <a href="{{ route('foods.show', $food->id) }}"
                                                class="btn btn-sm btn-outline-secondary mt-3">
                                                View Food Details
                                            </a>
                                            <a href="{{ route('client.visit-plans.index', ['item_type' => 'food', 'item_id' => $food->id]) }}"
                                                class="btn btn-sm btn-outline-secondary mt-3">Add Food to Visit Plan</a>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </article>
                </div>
            @endforeach
        </div>
    @endif
@endsection
