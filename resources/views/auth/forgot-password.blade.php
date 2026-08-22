@extends('layouts.app')

@section('title', 'Forgot Password | Night Market Selangor')

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-5">
            <div class="card market-card">
                <div class="card-body p-4 p-md-5">
                    <div class="text-center mb-4">
                        <h1 class="h3 fw-bold text-market">Forgot Your Password?</h1>
                        <p class="text-secondary mb-0">
                            Enter your email address and we will send a reset link if an account exists.
                        </p>
                    </div>

                    <form method="POST" action="{{ route('password.email') }}" novalidate>
                        @csrf

                        <div class="mb-4">
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

                        <button type="submit" class="btn btn-market w-100">Send Password Reset Link</button>
                    </form>

                    <p class="text-center mt-4 mb-0">
                        <a href="{{ route('login') }}" class="text-market fw-semibold">Back to Login</a>
                    </p>
                </div>
            </div>
        </div>
    </div>
@endsection
