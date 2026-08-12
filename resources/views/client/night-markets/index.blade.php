@extends('layouts.app')

@section('title', 'Discover Night Markets | Night Market Selangor')

@section('content')
    <div class="mb-4">
        <h1 class="h3 fw-bold text-market mb-1">Discover Night Markets</h1>
        <p class="text-secondary mb-0">Find active night markets across Selangor.</p>
    </div>

    <div class="card market-card mb-4">
        <div class="card-body p-4">
            <form method="GET" action="{{ route('client.night-markets.index') }}">
                <div class="row g-3 align-items-end">
                    <div class="col-12 col-xl-5">
                        <label for="search" class="form-label">Search</label>
                        <input type="search" class="form-control @error('search') is-invalid @enderror"
                            id="search" name="search" value="{{ $filters['search'] ?? '' }}"
                            placeholder="Search by market name or location">
                        @error('search')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-12 col-md-6 col-xl-2">
                        <label for="district" class="form-label">District</label>
                        <select class="form-select @error('district') is-invalid @enderror"
                            id="district" name="district">
                            <option value="">All districts</option>
                            @foreach ($districts as $district)
                                <option value="{{ $district->city }}"
                                    @selected(($filters['district'] ?? '') === $district->city)>
                                    {{ $district->city }}
                                </option>
                            @endforeach
                        </select>
                        @error('district')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-12 col-md-6 col-xl-2">
                        <label for="operating_day" class="form-label">Operating Day</label>
                        <select class="form-select @error('operating_day') is-invalid @enderror"
                            id="operating_day" name="operating_day">
                            <option value="">All Days</option>
                            @foreach ($operatingDays as $operatingDay)
                                <option value="{{ $operatingDay }}"
                                    @selected(($filters['operating_day'] ?? '') === $operatingDay)>
                                    {{ $operatingDay }}
                                </option>
                            @endforeach
                        </select>
                        @error('operating_day')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-12 col-xl-3 d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-market">Apply Filters</button>
                        @if (($filters['search'] ?? null) || ($filters['district'] ?? null) || ($filters['operating_day'] ?? null))
                            <a href="{{ route('client.night-markets.index') }}"
                                class="btn btn-outline-secondary" aria-label="Clear Filters">Reset Filters</a>
                        @endif
                    </div>
                </div>
            </form>
        </div>
    </div>

    @if ($nightMarkets->isEmpty())
        <div class="alert alert-warning text-center py-4" role="status">
            <h2 class="h5 mb-2">No night markets found</h2>
            <p class="mb-3">Try changing your search, district, or operating day filter.</p>
            <a href="{{ route('client.night-markets.index') }}"
                class="btn btn-outline-secondary" aria-label="Clear Filters">Reset Filters</a>
        </div>
    @else
        <div class="row g-4">
            @foreach ($nightMarkets as $nightMarket)
                <div class="col-12 col-md-6 col-xl-4">
                    <article class="card h-100 market-card">
                        <div class="card-body p-4 d-flex flex-column">
                            <span class="badge text-bg-warning align-self-start mb-3">{{ $nightMarket->city }}</span>
                            <h2 class="h5 fw-bold">{{ $nightMarket->name }}</h2>
                            <p class="text-secondary mb-2">
                                <strong>Address:</strong> {{ $nightMarket->address }}
                            </p>
                            <p class="text-secondary mb-3">
                                <strong>District:</strong> {{ $nightMarket->city }}, {{ $nightMarket->state }}
                            </p>
                            <div class="mb-3">
                                <strong class="d-block mb-2">Operating Days:</strong>
                                @if ($nightMarket->operatingDays->isEmpty())
                                    <span class="text-secondary">Schedule not available.</span>
                                @else
                                    <div class="d-flex flex-wrap gap-1">
                                        @foreach ($nightMarket->operatingDays as $operatingDay)
                                            <span class="badge text-bg-light border">{{ $operatingDay->day_of_week }}</span>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                            <p class="mb-4">
                                {{ \Illuminate\Support\Str::limit($nightMarket->description ?: 'No description available.', 140) }}
                            </p>
                            <a href="{{ route('client.night-markets.show', $nightMarket->id) }}"
                                class="btn btn-market mt-auto align-self-start">View Details</a>
                        </div>
                    </article>
                </div>
            @endforeach
        </div>
    @endif
@endsection
