@extends('layouts.app')

@section('title', 'Register | Night Market Selangor')

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-6">
            <div class="card market-card">
                <div class="card-body p-4 p-md-5">
                    <div class="text-center mb-4">
                        <h1 class="h3 fw-bold text-market">Create Your Client Account</h1>
                        <p class="text-secondary mb-0">Start discovering night markets across Selangor.</p>
                    </div>

                    <form method="POST" action="{{ route('register.store') }}" novalidate>
                        @csrf

                        <div class="mb-3">
                            <label for="name" class="form-label">Full Name</label>
                            <input
                                type="text"
                                class="form-control @error('name') is-invalid @enderror"
                                id="name"
                                name="name"
                                value="{{ old('name') }}"
                                autocomplete="name"
                                autofocus
                                required
                            >
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="email" class="form-label">Email Address</label>
                            <input
                                type="email"
                                class="form-control @error('email') is-invalid @enderror"
                                id="email"
                                name="email"
                                value="{{ old('email') }}"
                                autocomplete="email"
                                required
                            >
                            @error('email')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <div class="input-group"><input type="password" class="form-control @error('password') is-invalid @enderror" id="password" name="password" autocomplete="new-password" required><button class="btn btn-outline-secondary" type="button" data-password-toggle="password" aria-label="Show password"><i class="bi bi-eye" aria-hidden="true"></i></button>@error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                            <div class="form-text">Use at least 8 characters.</div>
                        </div>

                        <div class="mb-4">
                            <label for="password_confirmation" class="form-label">Confirm Password</label>
                            <div class="input-group"><input type="password" class="form-control" id="password_confirmation" name="password_confirmation" autocomplete="new-password" required><button class="btn btn-outline-secondary" type="button" data-password-toggle="password_confirmation" aria-label="Show password confirmation"><i class="bi bi-eye" aria-hidden="true"></i></button></div>
                        </div>

                        <button type="submit" class="btn btn-market w-100">Create Account</button>
                    </form>

                    <p class="text-center text-secondary mt-4 mb-0">
                        Already registered?
                        <a href="{{ route('login') }}" class="text-market fw-semibold">Login</a>
                    </p>
                </div>
            </div>
        </div>
    </div>
@endsection
