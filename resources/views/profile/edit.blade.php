@extends('layouts.app')

@section('title', 'My Profile | Night Market Selangor')

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-lg-8">
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

                    <section class="border rounded-3 bg-light p-3 p-md-4 mb-4" aria-labelledby="profile-image-heading">
                        <div class="d-flex flex-column flex-sm-row align-items-sm-center gap-4">
                            <x-user-avatar :user="auth()->user()" size="lg" />

                            <div class="flex-grow-1">
                                <h2 id="profile-image-heading" class="h5 fw-bold mb-1">Profile Image</h2>
                                <p class="text-secondary small mb-3">
                                    Upload a JPEG, PNG, or WebP image up to 2 MB. Large images may be rejected.
                                </p>

                                <form method="POST" action="{{ route('profile.avatar.update') }}"
                                    enctype="multipart/form-data" novalidate>
                                    @csrf
                                    @method('PATCH')

                                    <div class="mb-3">
                                        <label for="avatar" class="form-label">Choose Image</label>
                                        <input
                                            type="file"
                                            class="form-control @error('avatar') is-invalid @enderror"
                                            id="avatar"
                                            name="avatar"
                                            accept="image/jpeg,image/png,image/webp"
                                            required
                                        >
                                        @error('avatar')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>

                                    <button type="submit" class="btn btn-market">
                                        {{ auth()->user()->avatar_path ? 'Replace Image' : 'Upload Image' }}
                                    </button>
                                </form>

                                @if (auth()->user()->avatar_path)
                                    <form method="POST" action="{{ route('profile.avatar.destroy') }}" class="mt-2"
                                        onsubmit="return confirm('Remove your current profile image?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-outline-danger">Remove Photo</button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </section>

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
