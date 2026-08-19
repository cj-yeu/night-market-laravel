@extends('layouts.app')

@section('title', 'Discover Night Markets | Night Market Selangor')

@section('content')
    <div class="mb-4">
        <h1 class="h3 fw-bold text-market mb-1">Discover Night Markets</h1>
        <p class="text-secondary mb-0">Find active night markets across Selangor.</p>
    </div>

    <div class="card market-card mb-4">
        <div class="card-body p-4">
            <form method="GET" action="{{ route('night-markets.index') }}">
                <div class="row g-3 align-items-end">
                    <div class="col-12 col-lg-6 col-xl-4">
                        <label for="search" class="form-label">Name or location</label>
                        <input type="search" class="form-control @error('search') is-invalid @enderror"
                            id="search" name="search" value="{{ $filters['search'] ?? '' }}" maxlength="100"
                            placeholder="Search market name, address, or city">
                        @error('search') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-12 col-sm-6 col-lg-3 col-xl-2">
                        <label for="city" class="form-label">City</label>
                        <select class="form-select @error('city') is-invalid @enderror" id="city" name="city">
                            <option value="">All cities</option>
                            @foreach ($cities as $city)
                                <option value="{{ $city->city }}" @selected(($filters['city'] ?? $filters['district'] ?? '') === $city->city)>
                                    {{ $city->city }}
                                </option>
                            @endforeach
                        </select>
                        @error('city') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-12 col-sm-6 col-lg-3 col-xl-2">
                        <label for="operating_day" class="form-label">Operating day</label>
                        <select class="form-select @error('operating_day') is-invalid @enderror" id="operating_day" name="operating_day">
                            <option value="">All days</option>
                            @foreach ($operatingDays as $operatingDay)
                                <option value="{{ $operatingDay }}" @selected(($filters['operating_day'] ?? '') === $operatingDay)>
                                    {{ $operatingDay }}
                                </option>
                            @endforeach
                        </select>
                        @error('operating_day') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-12 col-sm-6 col-lg-4 col-xl-2">
                        <label for="sort" class="form-label">Sort by</label>
                        <select class="form-select @error('sort') is-invalid @enderror" id="sort" name="sort">
                            <option value="name_asc" @selected(($filters['sort'] ?? 'name_asc') === 'name_asc')>Name A–Z</option>
                            <option value="name_desc" @selected(($filters['sort'] ?? '') === 'name_desc')>Name Z–A</option>
                            <option value="city_asc" @selected(($filters['sort'] ?? '') === 'city_asc')>City A–Z</option>
                        </select>
                        @error('sort') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-12 col-sm-6 col-lg-8 col-xl-2 d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-market">Apply</button>
                        @if (($filters['search'] ?? null) || ($filters['city'] ?? null) || ($filters['district'] ?? null) || ($filters['operating_day'] ?? null) || (($filters['sort'] ?? 'name_asc') !== 'name_asc'))
                            <a href="{{ route('night-markets.index') }}" class="btn btn-outline-secondary">Reset Filters</a>
                        @endif
                    </div>
                </div>
            </form>
        </div>
    </div>

    @if ($nightMarkets->isEmpty())
        <div class="alert alert-warning text-center py-4" role="status">
            <h2 class="h5 mb-2">No night markets found</h2>
            <p class="mb-3">Try changing your search, city, operating day, or sorting option.</p>
            <a href="{{ route('night-markets.index') }}" class="btn btn-outline-secondary" aria-label="Clear Filters">Reset Filters</a>
        </div>
    @else
        <p class="text-secondary mb-3" role="status">
            Showing {{ $nightMarkets->firstItem() }}–{{ $nightMarkets->lastItem() }} of {{ $nightMarkets->total() }} night markets
        </p>

        <div class="row g-4">
            @foreach ($nightMarkets as $nightMarket)
                <div class="col-12 col-md-6 col-xl-4">
                    <article class="card h-100 market-card overflow-hidden">
                        <x-night-market-image :night-market="$nightMarket" />
                        <div class="card-body p-4 d-flex flex-column">
                            <span class="badge text-bg-warning align-self-start mb-3">{{ $nightMarket->city }}</span>
                            <h2 class="h5 fw-bold">{{ $nightMarket->name }}</h2>
                            <p class="text-secondary text-break mb-2"><strong>Address:</strong> {{ $nightMarket->address }}</p>
                            <p class="text-secondary mb-3"><strong>City / state:</strong> {{ $nightMarket->city }}, {{ $nightMarket->state }}</p>
                            <div class="mb-3">
                                <strong class="d-block mb-2">Operating schedule</strong>
                                <x-night-market-schedule :operating-days="$nightMarket->operatingDays" />
                            </div>
                            <p class="mb-4">{{ \Illuminate\Support\Str::limit($nightMarket->description ?: 'No description available.', 140) }}</p>
                            <div class="d-flex flex-wrap gap-2 mt-auto">
                                <a href="{{ route('night-markets.show', $nightMarket) }}" class="btn btn-market">View Details</a>
                                @if ($nightMarket->googleMapsUrl())
                                    <a href="{{ $nightMarket->googleMapsUrl() }}" class="btn btn-outline-secondary"
                                        target="_blank" rel="noopener noreferrer" aria-label="View {{ $nightMarket->name }} on Google Maps">
                                        View on Google Maps
                                    </a>
                                @endif
                            </div>
                        </div>
                    </article>
                </div>
            @endforeach
        </div>

        @if ($nightMarkets->hasPages())
            <nav class="d-flex flex-wrap justify-content-between align-items-center gap-3 mt-4" aria-label="Night market pagination">
                <a class="btn btn-outline-secondary {{ $nightMarkets->onFirstPage() ? 'disabled' : '' }}"
                    href="{{ $nightMarkets->previousPageUrl() ?: '#' }}" @if ($nightMarkets->onFirstPage()) aria-disabled="true" @endif>Previous</a>
                <span class="text-secondary">Page {{ $nightMarkets->currentPage() }} of {{ $nightMarkets->lastPage() }}</span>
                <a class="btn btn-outline-secondary {{ $nightMarkets->hasMorePages() ? '' : 'disabled' }}"
                    href="{{ $nightMarkets->nextPageUrl() ?: '#' }}" @if (! $nightMarkets->hasMorePages()) aria-disabled="true" @endif>Next</a>
            </nav>
        @endif
    @endif
@endsection
