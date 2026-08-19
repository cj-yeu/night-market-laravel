@extends('layouts.app')

@section('title', 'Home | Night Market Selangor')

@section('content')
    <section class="row align-items-center g-4 py-lg-4" aria-labelledby="welcome-heading">
        <div class="col-12 col-lg-7">
            <span class="badge rounded-pill text-bg-warning mb-3">Explore Selangor</span>
            <h1 id="welcome-heading" class="display-5 fw-bold text-market">
                Discover your next night market visit
            </h1>
            <p class="lead text-secondary">
                Browse active night markets, stalls, must-try foods, approved reviews, and public social-media
                highlights before planning your visit.
            </p>
            <div class="d-flex flex-wrap gap-2 mt-4">
                <a href="{{ route('night-markets.index') }}" class="btn btn-market btn-lg">Discover Markets</a>
                <a href="{{ route('stalls.index') }}" class="btn btn-outline-secondary btn-lg">Explore Stalls</a>
                <a href="{{ route('foods.index', ['is_must_try' => '1']) }}" class="btn btn-outline-secondary btn-lg">Must-Try Foods</a>
                <a href="{{ route('social-media-highlights.index') }}" class="btn btn-outline-secondary btn-lg">
                    View Social Media Highlights
                </a>
            </div>
        </div>

        <div class="col-12 col-lg-5">
            <div class="card market-card">
                <div class="card-body p-4 p-md-5">
                    <h2 class="h4 fw-bold text-market">Browse freely, plan when ready</h2>
                    <p class="text-secondary mb-4">
                        Public discovery does not require an account. Log in or register when you want to write a
                        review or create a personal visit plan.
                    </p>
                    @guest
                        <div class="d-flex flex-wrap gap-2">
                            <a href="{{ route('login') }}" class="btn btn-market">Login</a>
                            <a href="{{ route('register') }}" class="btn btn-outline-secondary">Register</a>
                        </div>
                    @else
                        @if (auth()->user()->role === \App\Models\User::ROLE_CLIENT)
                            <a href="{{ route('client.visit-plans.create') }}" class="btn btn-market">
                                Create Visit Plan
                            </a>
                        @endif
                    @endguest
                </div>
            </div>
        </div>
    </section>

    <section class="py-4" aria-labelledby="featured-markets-heading">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-end gap-2 mb-4">
            <div>
                <h2 id="featured-markets-heading" class="h3 fw-bold text-market mb-1">Featured Night Markets</h2>
                <p class="text-secondary mb-0">A starting point for exploring active markets in Selangor.</p>
            </div>
            <a href="{{ route('night-markets.index') }}" class="btn btn-outline-secondary align-self-start">View all markets</a>
        </div>

        @if ($featuredNightMarkets->isEmpty())
            <div class="alert alert-info mb-0">No featured night markets are available right now.</div>
        @else
            <div class="row g-4">
                @foreach ($featuredNightMarkets as $nightMarket)
                    <div class="col-12 col-md-6 col-xl-4">
                        <article class="card market-card h-100 overflow-hidden">
                            <x-night-market-image :night-market="$nightMarket" />
                            <div class="card-body p-4 d-flex flex-column">
                                <span class="badge text-bg-warning align-self-start mb-2">{{ $nightMarket->city }}</span>
                                <h3 class="h5 fw-bold">{{ $nightMarket->name }}</h3>
                                <p class="text-secondary text-break">{{ $nightMarket->address }}</p>
                                <a href="{{ route('night-markets.show', $nightMarket) }}" class="btn btn-market mt-auto align-self-start">
                                    View market details
                                </a>
                            </div>
                        </article>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    <section class="py-4" aria-labelledby="must-try-heading">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-end gap-2 mb-4">
            <div>
                <h2 id="must-try-heading" class="h3 fw-bold text-market mb-1">Must-Try Food Showcase</h2>
                <p class="text-secondary mb-0">Catalog recommendations from active stalls at public markets.</p>
            </div>
            <a href="{{ route('foods.index', ['is_must_try' => '1', 'sort' => 'must_try_first']) }}" class="btn btn-outline-secondary align-self-start">View all Must-Try foods</a>
        </div>

        @if ($mustTryFoods->isEmpty())
            <div class="alert alert-info mb-0">No Must-Try foods are available right now.</div>
        @else
            <div class="row g-4">
                @foreach ($mustTryFoods as $food)
                    <div class="col-12 col-md-6 col-xl-4"><x-public-food-card :food="$food" :show-recommendation="true" /></div>
                @endforeach
            </div>
        @endif
    </section>
@endsection
