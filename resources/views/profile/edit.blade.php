@extends('layouts.app')

@section('title', 'My Profile | Night Market Selangor')

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-lg-7">
            <div class="card market-card">
                <div class="card-body p-4 p-md-5">
                    <div class="d-flex flex-column flex-sm-row justify-content-between gap-3 mb-4">
                        <div>
                            <h1 class="h3 fw-bold text-market mb-1">My Profile</h1>
                            <p class="text-secondary mb-0">Update your personal account information.</p>
                        </div>
                        <a href="{{ route('profile.password.edit') }}" class="btn btn-outline-secondary align-self-start">
                            Change Password
                        </a>
                    </div>

                    <form method="POST" action="{{ route('profile.update') }}" novalidate>
                        @csrf
                        @method('PATCH')

                        <div class="mb-3">
                            <label for="name" class="form-label">Full Name</label>
                            <input
                                type="text"
                                class="form-control @error('name') is-invalid @enderror"
                                id="name"
                                name="name"
                                value="{{ old('name', auth()->user()->name) }}"
                                autocomplete="name"
                                required
                            >
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-4">
                            <label for="email" class="form-label">Email Address</label>
                            <input
                                type="email"
                                class="form-control @error('email') is-invalid @enderror"
                                id="email"
                                name="email"
                                value="{{ old('email', auth()->user()->email) }}"
                                autocomplete="email"
                                required
                            >
                            @error('email')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <button type="submit" class="btn btn-market">Save Profile</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
