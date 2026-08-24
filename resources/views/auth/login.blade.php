@extends('layouts.app')

@section('title', 'Login | Night Market Selangor')

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-5">
            <div class="card market-card">
                <div class="card-body p-4 p-md-5">
                    <div class="text-center mb-4">
                        <h1 class="h3 fw-bold text-market">Welcome Back</h1>
                        <p class="text-secondary mb-0">Log in to plan your next night market visit.</p>
                    </div>

                    <form method="POST" action="{{ route('login.store') }}" novalidate>
                        @csrf

                        <div class="mb-3">
                            <label for="email" class="form-label">Email Address</label>
                            <input
                                type="email"
                                class="form-control @error('email') is-invalid @enderror"
                                id="email"
                                name="email"
                                value="{{ old('email') }}"
                                autocomplete="email"
                                autofocus
                                required
                            >
                            @error('email')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-4">
                            <div class="d-flex justify-content-between align-items-center gap-3">
                                <label for="password" class="form-label">Password</label>
                                <a href="{{ route('password.request') }}" class="small text-market fw-semibold">
                                    Forgot your password?
                                </a>
                            </div>
                            <input
                                type="password"
                                data-password-toggle
                                class="form-control @error('password') is-invalid @enderror"
                                id="password"
                                name="password"
                                autocomplete="current-password"
                                required
                            >
                            @error('password')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <button type="submit" class="btn btn-market w-100">Login</button>
                    </form>

                    <div class="d-flex align-items-center gap-3 my-4" aria-hidden="true">
                        <hr class="flex-grow-1 my-0">
                        <span class="small text-secondary">or</span>
                        <hr class="flex-grow-1 my-0">
                    </div>

                    <a href="{{ route('auth.google.redirect') }}" class="btn btn-outline-secondary w-100">
                        <i class="bi bi-google me-2" aria-hidden="true"></i>Continue with Google
                    </a>

                    <p class="text-center text-secondary mt-4 mb-0">
                        New here?
                        <a href="{{ route('register') }}" class="text-market fw-semibold">Create a client account</a>
                    </p>
                </div>
            </div>
        </div>
    </div>
@endsection
