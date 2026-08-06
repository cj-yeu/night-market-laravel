@extends('layouts.app')

@section('title', 'Admin Dashboard | Night Market Selangor')

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-lg-9">
            <div class="card market-card">
                <div class="card-body p-4 p-md-5">
                    <span class="badge rounded-pill text-bg-dark mb-3">Administrator Area</span>
                    <h1 class="display-6 fw-bold text-market">Admin Dashboard</h1>
                    <p class="lead text-secondary mb-0">
                        Welcome, {{ auth()->user()->name }}. This protected dashboard is a placeholder for future
                        administration modules.
                    </p>
                </div>
            </div>
        </div>
    </div>
@endsection
