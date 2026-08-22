@extends('layouts.app')

@section('title', 'Client Home | Night Market Selangor')

@section('content')
    <section class="card market-card mb-4">
        <div class="card-body p-4 p-md-5">
            <span class="badge rounded-pill text-bg-warning mb-3">Your Night Market Guide</span>
            <h1 class="display-6 fw-bold text-market">Welcome back, {{ auth()->user()->name }}</h1>
            <p class="lead text-secondary mb-0">Find a market, explore what is available, and build a simple plan for your next visit.</p>
        </div>
    </section>

    <div class="row g-4 mb-4">
        <div class="col-12 col-lg-7">
            <section class="card market-card h-100" aria-labelledby="getting-started-heading">
                <div class="card-body p-4">
                    <h2 id="getting-started-heading" class="h4 fw-bold text-market">Getting Started</h2>
                    <ol class="mb-0 ps-3 vstack gap-3">
                        <li><strong>Discover a night market.</strong> Find an active Selangor market near you.</li>
                        <li><strong>Explore stalls and foods.</strong> See what is available and save ideas.</li>
                        <li><strong>Create a Visit Plan or leave a Review.</strong> Keep your visit organised and share feedback.</li>
                    </ol>
                </div>
            </section>
        </div>
        <div class="col-12 col-lg-5">
            <section class="card market-card h-100" aria-labelledby="plans-summary-heading">
                <div class="card-body p-4">
                    <h2 id="plans-summary-heading" class="h4 fw-bold text-market">My Visit Plans</h2>
                    <p class="display-6 fw-bold mb-1">{{ $upcomingPlanCount }}</p>
                    <p class="text-secondary">{{ $upcomingPlanCount === 1 ? 'upcoming plan' : 'upcoming plans' }}</p>
                    @if ($nearestUpcomingPlan)
                        <p class="mb-3"><strong>Next:</strong> {{ $nearestUpcomingPlan->title }}<br><span class="text-secondary">{{ $nearestUpcomingPlan->visit_date->format('D, j M Y') }} · {{ $nearestUpcomingPlan->nightMarket->name }}</span></p>
                        <a class="btn btn-outline-secondary" href="{{ route('client.visit-plans.show', $nearestUpcomingPlan) }}">View Next Plan</a>
                    @else
                        <p class="text-secondary">No upcoming plans yet. Start with a market that interests you.</p>
                        <a class="btn btn-market" href="{{ route('client.visit-plans.create') }}">Create a Visit Plan</a>
                    @endif
                </div>
            </section>
        </div>
    </div>

    <section aria-labelledby="explore-heading">
        <h2 id="explore-heading" class="h4 fw-bold text-market mb-3">Explore and Plan</h2>
        <div class="row g-3">
            @foreach ([
                ['Discover Markets', 'Find active Selangor night markets.', 'night-markets.index', [], 'shop'],
                ['Explore Stalls', 'Browse stalls across public markets.', 'stalls.index', [], 'basket'],
                ['Must-Try Foods', 'See foods worth adding to your list.', 'foods.index', ['is_must_try' => '1'], 'cup-hot'],
                ['Smart Visit Planner', 'Get date-aware ideas for your itinerary.', 'client.visit-plans.smart-planner.index', [], 'magic'],
                ['My Visit Plans', 'Create and manage your own itineraries.', 'client.visit-plans.index', [], 'calendar-check'],
            ] as [$label, $description, $route, $parameters, $icon])
                <div class="col-12 col-sm-6 col-xl">
                    <a href="{{ route($route, $parameters) }}" class="dashboard-action card market-card h-100 text-decoration-none">
                        <div class="card-body p-4">
                            <i class="bi bi-{{ $icon }} fs-3 text-market" aria-hidden="true"></i>
                            <h3 class="h5 text-dark fw-bold mt-3">{{ $label }}</h3>
                            <p class="text-secondary mb-0">{{ $description }}</p>
                        </div>
                    </a>
                </div>
            @endforeach
        </div>
    </section>
@endsection
