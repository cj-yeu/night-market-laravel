@extends('layouts.app')

@section('title', 'Must-Try Foods | '.config('app.name'))

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h2 fw-bold text-market mb-1">Explore Foods</h1>
            <p class="text-secondary mb-0">Discover food from active stalls, including verified catalog Must-Try selections.</p>
        </div>
        <div class="d-flex flex-wrap gap-2 align-self-start">
            <a href="{{ route('foods.index', ['is_must_try' => '1', 'sort' => 'must_try_first']) }}" class="btn btn-market">Show Must-Try Foods</a>
            <a href="{{ route('stalls.index') }}" class="btn btn-outline-secondary">Explore Stalls</a>
        </div>
    </div>

    <div class="card market-card mb-4"><div class="card-body p-4">
        <form method="GET" action="{{ route('foods.index') }}" class="row g-3 align-items-end">
            <div class="col-12 col-lg-4">
                <label for="food-search" class="form-label">Food name or description</label>
                <input id="food-search" name="search" type="search" maxlength="100" value="{{ $filters['search'] ?? '' }}" class="form-control @error('search') is-invalid @enderror">
                @error('search')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12 col-md-6 col-lg-4">
                <label for="food-market" class="form-label">Night Market</label>
                <select id="food-market" name="night_market_id" class="form-select @error('night_market_id') is-invalid @enderror">
                    <option value="">All public markets</option>
                    @foreach ($nightMarkets as $market)<option value="{{ $market->id }}" @selected((string) ($filters['night_market_id'] ?? '') === (string) $market->id)>{{ $market->name }}</option>@endforeach
                </select>
                @error('night_market_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12 col-md-6 col-lg-4">
                <label for="food-stall" class="form-label">Stall</label>
                <select id="food-stall" name="stall_id" class="form-select @error('stall_id') is-invalid @enderror">
                    <option value="">All public stalls</option>
                    @foreach ($publicStalls as $stall)<option value="{{ $stall->id }}" @selected((string) ($filters['stall_id'] ?? '') === (string) $stall->id)>{{ $stall->name }} — {{ $stall->nightMarket->name }}</option>@endforeach
                </select>
                @error('stall_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12 col-md-6 col-lg-3">
                <label for="food-category" class="form-label">Food category</label>
                <select id="food-category" name="category" class="form-select @error('category') is-invalid @enderror">
                    <option value="">All categories</option>
                    @foreach ($foodCategories as $category)<option value="{{ $category->category }}" @selected(($filters['category'] ?? '') === $category->category)>{{ $category->category }}</option>@endforeach
                </select>
                @error('category')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12 col-md-6 col-lg-3">
                <label for="food-halal" class="form-label">Stall Halal classification</label>
                <select id="food-halal" name="halal_status" class="form-select @error('halal_status') is-invalid @enderror">
                    <option value="">All classifications</option>
                    @foreach ($halalStatuses as $value => $label)<option value="{{ $value }}" @selected(($filters['halal_status'] ?? '') === $value)>{{ $label }}</option>@endforeach
                </select>
                @error('halal_status')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12 col-md-6 col-lg-3">
                <label for="food-must-try" class="form-label">Must-Try status</label>
                <select id="food-must-try" name="is_must_try" class="form-select @error('is_must_try') is-invalid @enderror">
                    <option value="">All foods</option>
                    <option value="1" @selected(($filters['is_must_try'] ?? '') === '1')>Must-Try only</option>
                    <option value="0" @selected(($filters['is_must_try'] ?? '') === '0')>Not marked Must-Try</option>
                </select>
                @error('is_must_try')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-6 col-lg-3">
                <label for="food-min-price" class="form-label">Minimum price (RM)</label>
                <input id="food-min-price" name="min_price" inputmode="decimal" value="{{ $filters['min_price'] ?? '' }}" class="form-control @error('min_price') is-invalid @enderror">
                @error('min_price')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-6 col-lg-3">
                <label for="food-max-price" class="form-label">Maximum price (RM)</label>
                <input id="food-max-price" name="max_price" inputmode="decimal" value="{{ $filters['max_price'] ?? '' }}" class="form-control @error('max_price') is-invalid @enderror">
                @error('max_price')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12 col-md-6 col-lg-3">
                <label for="food-sort" class="form-label">Sort by</label>
                <select id="food-sort" name="sort" class="form-select @error('sort') is-invalid @enderror">
                    <option value="name_asc" @selected(($filters['sort'] ?? 'name_asc') === 'name_asc')>Food name A–Z</option>
                    <option value="name_desc" @selected(($filters['sort'] ?? '') === 'name_desc')>Food name Z–A</option>
                    <option value="price_low_high" @selected(($filters['sort'] ?? '') === 'price_low_high')>Price low to high</option>
                    <option value="price_high_low" @selected(($filters['sort'] ?? '') === 'price_high_low')>Price high to low</option>
                    <option value="must_try_first" @selected(($filters['sort'] ?? '') === 'must_try_first')>Must-Try first</option>
                </select>
                @error('sort')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12 d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-market">Apply Filters</button>
                <a href="{{ route('foods.index') }}" class="btn btn-outline-secondary">Reset Filters</a>
            </div>
        </form>
    </div></div>

    <p class="text-secondary">Showing {{ $foods->firstItem() ?? 0 }}–{{ $foods->lastItem() ?? 0 }} of {{ $foods->total() }} foods.</p>
    @if ($foods->isEmpty())
        <div class="alert alert-warning text-center py-4" role="status">
            <h2 class="h5">No foods found</h2>
            <p>Try changing or clearing the Food filters.</p>
            <a href="{{ route('foods.index') }}" class="btn btn-outline-secondary">Clear Filters</a>
        </div>
    @else
        <div class="row g-4">
            @foreach ($foods as $food)<div class="col-12 col-md-6 col-xl-4"><x-public-food-card :food="$food" :show-recommendation="true" /></div>@endforeach
        </div>
        @if ($foods->hasPages())
            <nav class="d-flex justify-content-between align-items-center mt-4" aria-label="Food pagination">
                <a class="btn btn-outline-secondary {{ $foods->onFirstPage() ? 'disabled' : '' }}" href="{{ $foods->previousPageUrl() ?: '#' }}">Previous</a>
                <span class="text-secondary">Page {{ $foods->currentPage() }} of {{ $foods->lastPage() }}</span>
                <a class="btn btn-outline-secondary {{ $foods->hasMorePages() ? '' : 'disabled' }}" href="{{ $foods->nextPageUrl() ?: '#' }}">Next</a>
            </nav>
        @endif
    @endif
@endsection

@push('scripts')
<script>
    window.addEventListener('pageshow', function () {
        const query = new URLSearchParams(window.location.search);
        ['min_price', 'max_price'].forEach(function (name) {
            const input = document.querySelector('[name="' + name + '"]');
            if (input) input.value = query.get(name) || '';
        });
    });
</script>
@endpush
