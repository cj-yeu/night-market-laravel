@extends('layouts.app')

@section('title', 'Explore Stalls | Night Market Selangor')

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h2 fw-bold text-market mb-1">Explore Stalls</h1>
            <p class="text-secondary mb-0">Find active stalls at public Selangor night markets.</p>
        </div>
        <a href="{{ route('foods.index') }}" class="btn btn-outline-secondary align-self-start">Explore Foods</a>
    </div>

    <div class="card market-card mb-4"><div class="card-body p-4">
        <form method="GET" action="{{ route('stalls.index') }}" class="row g-3 align-items-end">
            <div class="col-12 col-lg-4">
                <label for="stall-search" class="form-label">Stall name or description</label>
                <input id="stall-search" name="search" type="search" value="{{ $filters['search'] ?? '' }}"
                    class="form-control @error('search') is-invalid @enderror" maxlength="100">
                @error('search')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12 col-md-6 col-lg-4">
                <label for="stall-market" class="form-label">Night Market</label>
                <select id="stall-market" name="night_market_id" class="form-select @error('night_market_id') is-invalid @enderror">
                    <option value="">All public markets</option>
                    @foreach ($nightMarkets as $market)
                        <option value="{{ $market->id }}" @selected((string) ($filters['night_market_id'] ?? '') === (string) $market->id)>{{ $market->name }}</option>
                    @endforeach
                </select>
                @error('night_market_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12 col-md-6 col-lg-4">
                <label for="stall-city" class="form-label">Market city</label>
                <select id="stall-city" name="city" class="form-select @error('city') is-invalid @enderror">
                    <option value="">All cities</option>
                    @foreach ($cities as $city)
                        <option value="{{ $city->city }}" @selected(($filters['city'] ?? '') === $city->city)>{{ $city->city }}</option>
                    @endforeach
                </select>
                @error('city')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12 col-md-4">
                <label for="stall-category" class="form-label">Stall category</label>
                <select id="stall-category" name="category" class="form-select @error('category') is-invalid @enderror">
                    <option value="">All categories</option>
                    @foreach ($stallCategories as $category)
                        <option value="{{ $category->category }}" @selected(($filters['category'] ?? '') === $category->category)>{{ $category->category }}</option>
                    @endforeach
                </select>
                @error('category')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12 col-md-4">
                <label for="stall-halal" class="form-label">Stall Halal classification</label>
                <select id="stall-halal" name="halal_status" class="form-select @error('halal_status') is-invalid @enderror">
                    <option value="">All classifications</option>
                    @foreach ($halalStatuses as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['halal_status'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('halal_status')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12 col-md-4">
                <label for="stall-sort" class="form-label">Sort by</label>
                <select id="stall-sort" name="sort" class="form-select @error('sort') is-invalid @enderror">
                    <option value="name_asc" @selected(($filters['sort'] ?? 'name_asc') === 'name_asc')>Stall name A–Z</option>
                    <option value="name_desc" @selected(($filters['sort'] ?? '') === 'name_desc')>Stall name Z–A</option>
                    <option value="market_asc" @selected(($filters['sort'] ?? '') === 'market_asc')>Night Market A–Z</option>
                </select>
                @error('sort')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12 d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-market">Apply Filters</button>
                <a href="{{ route('stalls.index') }}" class="btn btn-outline-secondary">Reset Filters</a>
            </div>
        </form>
    </div></div>

    <p class="text-secondary">Showing {{ $stalls->firstItem() ?? 0 }}–{{ $stalls->lastItem() ?? 0 }} of {{ $stalls->total() }} stalls.</p>
    @if ($stalls->isEmpty())
        <div class="alert alert-warning text-center py-4" role="status">
            <h2 class="h5">No stalls found</h2>
            <p>Try changing or clearing the Stall filters.</p>
            <a href="{{ route('stalls.index') }}" class="btn btn-outline-secondary">Clear Filters</a>
        </div>
    @else
        <div class="row g-4">
            @foreach ($stalls as $stall)
                <div class="col-12 col-md-6 col-xl-4"><x-public-stall-card :stall="$stall" /></div>
            @endforeach
        </div>
        @if ($stalls->hasPages())
            <nav class="d-flex justify-content-between align-items-center mt-4" aria-label="Stall pagination">
                <a class="btn btn-outline-secondary {{ $stalls->onFirstPage() ? 'disabled' : '' }}" href="{{ $stalls->previousPageUrl() ?: '#' }}">Previous</a>
                <span class="text-secondary">Page {{ $stalls->currentPage() }} of {{ $stalls->lastPage() }}</span>
                <a class="btn btn-outline-secondary {{ $stalls->hasMorePages() ? '' : 'disabled' }}" href="{{ $stalls->nextPageUrl() ?: '#' }}">Next</a>
            </nav>
        @endif
    @endif
@endsection
