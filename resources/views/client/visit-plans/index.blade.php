@extends('layouts.app')

@section('title', 'Visit Plans | Night Market Selangor')

@section('content')
    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold text-market mb-1">My Visit Plans</h1>
            <p class="text-secondary mb-0">Your upcoming night market visits.</p>
        </div>
        <a href="{{ route('client.visit-plans.create') }}" class="btn btn-market align-self-start">
            Create Visit Plan
        </a>
    </div>

    <div class="card market-card mb-4">
        <div class="card-body p-4">
            <form method="GET" action="{{ route('client.visit-plans.index') }}">
                <div class="row g-3 align-items-end">
                    <div class="col-12 col-lg-8">
                        <label for="search" class="form-label">Search Plans</label>
                        <input type="search" class="form-control @error('search') is-invalid @enderror"
                            id="search" name="search" value="{{ $filters['search'] ?? '' }}"
                            placeholder="Search by plan title or night market">
                        @error('search')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-12 col-lg-4 d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-market">Search</button>
                        @if ($filters['search'] ?? null)
                            <a href="{{ route('client.visit-plans.index') }}"
                                class="btn btn-outline-secondary">Reset Search</a>
                        @endif
                    </div>
                </div>
            </form>
        </div>
    </div>

    @if ($visitPlans->isEmpty())
        <div class="card market-card">
            <div class="card-body p-5 text-center">
                @if ($filters['search'] ?? null)
                    <h2 class="h5">No visit plans found</h2>
                    <p class="text-secondary">No plans match your search.</p>
                    <a href="{{ route('client.visit-plans.index') }}"
                        class="btn btn-outline-secondary">Reset Search</a>
                @else
                    <h2 class="h5">No visit plans yet</h2>
                    <p class="text-secondary">Create your first plan for an upcoming night market visit.</p>
                    <a href="{{ route('client.visit-plans.create') }}" class="btn btn-market">Create Visit Plan</a>
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
                                <span class="badge {{ $visitPlan->visit_status === 'Completed' ? 'text-bg-secondary' : ($visitPlan->visit_status === 'Today' ? 'text-bg-success' : 'text-bg-warning') }}">
                                    {{ $visitPlan->visit_status }}
                                </span>
                            </div>
                            <p class="text-market fw-semibold mb-2">{{ $visitPlan->nightMarket->name }}</p>
                            <p class="text-secondary mb-0">
                                {{ $visitPlan->visit_date->format('d M Y') }}
                            </p>
                            <p class="small text-secondary mt-2 mb-3">
                                {{ $visitPlan->items_count }} planned {{ $visitPlan->items_count === 1 ? 'item' : 'items' }}
                            </p>
                            <a href="{{ route('client.visit-plans.show', $visitPlan) }}"
                                class="btn btn-market">View Plan</a>
                        </div>
                    </article>
                </div>
            @endforeach
        </div>
    @endif
@endsection
