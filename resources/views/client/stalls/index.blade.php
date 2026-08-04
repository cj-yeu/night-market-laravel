@extends('layouts.app')

@section('title', $nightMarket->name.' Stalls | Night Market Selangor')

@section('content')
    <div class="d-flex flex-column flex-sm-row justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold text-market mb-1">Stalls at {{ $nightMarket->name }}</h1>
            <p class="text-secondary mb-0">Browse active stalls and their food items.</p>
        </div>
        <a href="{{ route('client.night-markets.show', $nightMarket->id) }}"
            class="btn btn-outline-secondary align-self-start">Back to Market Details</a>
    </div>

    <div class="card market-card mb-4">
        <div class="card-body p-4">
            <form method="GET" action="{{ route('client.night-markets.stalls.index', $nightMarket->id) }}">
                <div class="row g-3 align-items-end">
                    <div class="col-12 col-lg-6">
                        <label for="search" class="form-label">Search</label>
                        <input type="search" class="form-control @error('search') is-invalid @enderror"
                            id="search" name="search" value="{{ $filters['search'] ?? '' }}"
                            placeholder="Search by stall or food name">
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
                            <a href="{{ route('client.night-markets.stalls.index', $nightMarket->id) }}"
                                class="btn btn-outline-secondary">Clear Filters</a>
                        @endif
                    </div>
                </div>
            </form>
        </div>
    </div>

    @if ($stalls->isEmpty())
        <div class="alert alert-warning text-center py-4" role="status">
            <h2 class="h5 mb-2">No stalls or foods found</h2>
            <p class="mb-0">Try changing your search or food category filter.</p>
        </div>
    @else
        <div class="row g-4">
            @foreach ($stalls as $stall)
                <div class="col-12 col-lg-6">
                    <article class="card h-100 market-card">
                        <div class="card-body p-4">
                            <h2 class="h4 fw-bold">{{ $stall->name }}</h2>
                            <p class="text-secondary">{{ $stall->description ?: 'No stall description available.' }}</p>

                            <h3 class="h6 text-market fw-bold mt-4">Food Items &amp; Must-Try Foods</h3>
                            @if ($stall->foods->isEmpty())
                                <p class="text-secondary mb-0">No active food items are listed for this stall.</p>
                            @else
                                <div class="list-group list-group-flush">
                                    @foreach ($stall->foods as $food)
                                        <a href="{{ route('client.foods.show', $food->id) }}"
                                            class="list-group-item list-group-item-action px-0">
                                            <div class="d-flex justify-content-between align-items-start gap-3">
                                                <div>
                                                    <span class="fw-semibold">{{ $food->name }}</span>
                                                    @if ($food->category)
                                                        <span class="text-secondary d-block small">{{ $food->category }}</span>
                                                    @endif
                                                </div>
                                                @if ($food->is_must_try)
                                                    <span class="badge text-bg-warning">Must-Try</span>
                                                @endif
                                            </div>
                                        </a>
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