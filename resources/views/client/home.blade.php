@extends('layouts.app')

@section('title', 'Client Home | Night Market Selangor')

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-lg-9">
            <div class="card market-card">
                <div class="card-body p-4 p-md-5">
                    <span class="badge rounded-pill text-bg-warning mb-3">Client Area</span>
                    <h1 class="display-6 fw-bold text-market">Welcome, {{ auth()->user()->name }}</h1>
                    <p class="lead text-secondary mb-0">
                        Your Client Home is ready. Night market discovery and visit-planning features will be added in
                        their respective modules.
                    </p>
                </div>
            </div>
        </div>
    </div>
@endsection
