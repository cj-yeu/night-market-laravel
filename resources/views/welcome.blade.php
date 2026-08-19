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
@endsection
