@extends('layouts.app')

@section('title', 'Admin Dashboard | Night Market Selangor')

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-lg-9">
            <div class="card market-card">
                <div class="card-body p-4 p-md-5">
                    <span class="badge rounded-pill text-bg-dark mb-3">Administrator Area</span>
                    <h1 class="display-6 fw-bold text-market">Admin Dashboard</h1>
                    <p class="lead text-secondary mb-4">
                        Welcome, {{ auth()->user()->name }}. Manage accounts and administration modules from this
                        protected area.
                    </p>
                    <a href="{{ route('admin.users.index') }}" class="btn btn-market">Manage Users</a>
                </div>
            </div>
        </div>
    </div>
@endsection
