@extends('layouts.app')

@section('title', 'Visit Plans | '.config('app.name'))

@section('content')
    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold text-market mb-1">My Visit Plans</h1>
            <p class="text-secondary mb-0">Organize upcoming visits and revisit past itineraries.</p>
        </div>
        <div class="d-flex flex-wrap gap-2 align-self-start">
            <a href="{{ route('client.visit-plans.smart-planner.index') }}" class="btn btn-outline-secondary">Smart Planner</a>
            <a href="{{ route('client.visit-plans.create') }}" class="btn btn-market">Create Visit Plan</a>
        </div>
    </div>

    @if ($planningTarget)
        <section class="card market-card border-warning mb-4" aria-labelledby="planning-target-heading">
            <div class="card-body p-4">
                <h2 id="planning-target-heading" class="h5 fw-bold text-market">Add {{ $planningTarget['name'] }} to a plan</h2>
                <p class="text-secondary">{{ ucfirst($planningTarget['type']) }} · {{ $planningTarget['context'] }}</p>
                @error('item_id')<div class="alert alert-danger" role="alert">{{ $message }}</div>@enderror

                @if ($compatiblePlans->isEmpty())
                    <div class="alert alert-info mb-3">You do not have a plan for this Night Market yet.</div>
                    <a href="{{ route('client.visit-plans.create', ['night_market_id' => $planningTarget['night_market_id']]) }}"
                        class="btn btn-market">Create a Plan for This Market</a>
                @else
                    <div class="vstack gap-2">
                        @foreach ($compatiblePlans as $compatiblePlan)
                            <div class="border rounded-3 p-3 d-flex flex-column flex-sm-row justify-content-between gap-3">
                                <div>
                                    <strong>{{ $compatiblePlan->title }}</strong>
                                    <span class="text-secondary d-block small">
                                        {{ $compatiblePlan->visit_date->format('M j, Y') }} · {{ $compatiblePlan->visit_status }}
                                    </span>
                                </div>
                                @if ($compatiblePlan->has_target)
                                    <span class="badge text-bg-secondary align-self-start">Already added</span>
                                @else
                                    <form method="POST" action="{{ route('client.visit-plans.items.store', $compatiblePlan) }}">
                                        @csrf
                                        <input type="hidden" name="item_type" value="{{ $planningTarget['type'] }}">
                                        <input type="hidden" name="{{ $planningTarget['type'] }}_id" value="{{ $planningTarget['id'] }}">
                                        <button type="submit" class="btn btn-sm btn-market">Add to This Plan</button>
                                    </form>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>
    @endif

    <div class="card market-card mb-4">
        <div class="card-body p-4">
            <form method="GET" action="{{ route('client.visit-plans.index') }}">
                @foreach ($targetQuery as $name => $value)
                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                @endforeach
                <div class="row g-3 align-items-end">
                    <div class="col-12 col-lg-6">
                        <label for="search" class="form-label">Search Plans</label>
                        <input type="search" class="form-control @error('search') is-invalid @enderror"
                            id="search" name="search" value="{{ $filters['search'] ?? '' }}"
                            placeholder="Search by title or available Night Market">
                        @error('search')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12 col-sm-6 col-lg-3">
                        <label for="status" class="form-label">Visit Date</label>
                        <select class="form-select @error('status') is-invalid @enderror" id="status" name="status">
                            <option value="">All</option>
                            <option value="upcoming" @selected(($filters['status'] ?? '') === 'upcoming')>Upcoming</option>
                            <option value="today" @selected(($filters['status'] ?? '') === 'today')>Today</option>
                            <option value="past" @selected(($filters['status'] ?? '') === 'past')>Past</option>
                        </select>
                        @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12 col-sm-6 col-lg-3 d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-market">Apply Filters</button>
                        @if ($hasFilters)
                            <a href="{{ route('client.visit-plans.index', $targetQuery) }}"
                                class="btn btn-outline-secondary">Reset Filters</a>
                        @endif
                    </div>
                </div>
            </form>
        </div>
    </div>

    @if ($visitPlans->isEmpty())
        <div class="card market-card">
            <div class="card-body p-5 text-center">
                @if ($hasFilters)
                    <h2 class="h5">No visit plans found</h2>
                    <p class="text-secondary">No plans match the selected filters.</p>
                    <a href="{{ route('client.visit-plans.index', $targetQuery) }}" class="btn btn-outline-secondary">Reset Filters</a>
                @else
                    <h2 class="h5">No visit plans yet</h2>
                    <p class="text-secondary">Create a plan or browse public Night Markets for inspiration.</p>
                    <div class="d-flex flex-wrap justify-content-center gap-2">
                        <a href="{{ route('client.visit-plans.create') }}" class="btn btn-market">Create Visit Plan</a>
                        <a href="{{ route('night-markets.index') }}" class="btn btn-outline-secondary">Browse Night Markets</a>
                    </div>
                @endif
            </div>
        </div>
    @else
        <div class="row g-4">
            @foreach ($visitPlans as $visitPlan)
                <div class="col-12 col-md-6 col-xl-4">
                    <article class="card h-100 market-card">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <h2 class="h5 fw-bold">{{ $visitPlan->title }}</h2>
                                <span class="badge {{ $visitPlan->visit_status === 'Past' ? 'text-bg-secondary' : ($visitPlan->visit_status === 'Today' ? 'text-bg-success' : 'text-bg-warning') }}">
                                    {{ $visitPlan->visit_status }}
                                </span>
                            </div>
                            <p class="text-market fw-semibold mb-2">{{ $visitPlan->market_display_name }}</p>
                            <p class="text-secondary mb-0">{{ $visitPlan->visit_date->format('d M Y') }}</p>
                            <p class="small text-secondary mt-2 mb-3">
                                {{ $visitPlan->items_count }} planned {{ $visitPlan->items_count === 1 ? 'item' : 'items' }}
                            </p>
                            <a href="{{ route('client.visit-plans.show', $visitPlan) }}" class="btn btn-market">View Itinerary</a>
                        </div>
                    </article>
                </div>
            @endforeach
        </div>
        <div class="mt-4">{{ $visitPlans->links() }}</div>
    @endif
@endsection
