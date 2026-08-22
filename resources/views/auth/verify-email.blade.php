@extends('layouts.app')

@section('title', 'Verify Email | Night Market Selangor')

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-6">
            <div class="card market-card">
                <div class="card-body p-4 p-md-5">
                    <span class="badge rounded-pill text-bg-warning mb-3">Verification pending</span>
                    <h1 class="h3 fw-bold text-market mb-3">Verify your email address</h1>
                    <p class="text-secondary">
                        Before using trusted Client features such as Reviews and Visit Planner, verify the email
                        address associated with your account.
                    </p>

                    <div class="alert alert-light border" role="status">
                        Verification email: <strong>{{ $user->email }}</strong>
                    </div>

                    <form method="POST" action="{{ route('verification.send') }}" class="mb-3">
                        @csrf
                        <button type="submit" class="btn btn-market w-100">Resend Verification Email</button>
                    </form>

                    <div class="d-flex flex-column flex-sm-row gap-2">
                        <a href="{{ route('profile.edit') }}" class="btn btn-outline-secondary flex-fill">
                            Correct Email in Profile
                        </a>

                        <form method="POST" action="{{ route('logout') }}" class="flex-fill">
                            @csrf
                            <button type="submit" class="btn btn-outline-danger w-100">Logout</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
