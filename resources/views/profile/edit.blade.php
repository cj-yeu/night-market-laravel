@extends('layouts.app')

@section('title', 'My Profile | '.config('app.name'))

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-lg-8">
            <div class="card market-card">
                <div class="card-body p-4 p-md-5">
                    <div class="d-flex flex-column flex-sm-row justify-content-between gap-3 mb-4">
                        <div>
                            <h1 class="h3 fw-bold text-market mb-1">My Profile</h1>
                            <p class="text-secondary mb-0">Update your personal account information.</p>
                            <span class="badge {{ $user->hasVerifiedEmail() ? 'text-bg-success' : 'text-bg-warning' }} mt-2">
                                Email: {{ $user->hasVerifiedEmail() ? 'Verified' : 'Verification pending' }}
                            </span>
                        </div>
                        <a href="{{ route('profile.password.edit') }}" class="btn btn-outline-secondary align-self-start">
                            Change Password
                        </a>
                    </div>

                    <section class="border rounded-3 bg-light p-3 p-md-4 mb-4" aria-labelledby="profile-image-heading">
                        <div class="d-flex flex-column flex-sm-row align-items-sm-center gap-4">
                            <x-user-avatar :user="$user" size="lg" />

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
                                        {{ $user->avatar_path ? 'Replace Image' : 'Upload Image' }}
                                    </button>
                                </form>

                                @if ($user->avatar_path)
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
                                value="{{ old('name', $user->name) }}"
                                autocomplete="name"
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
                                value="{{ old('email', $user->email) }}"
                                autocomplete="email"
                                required
                            >
                            @error('email')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            @if ($user->googleAccount)
                                <div class="form-text">
                                    Disconnect Google before changing this email address.
                                </div>
                            @elseif ($user->hasVerifiedEmail())
                                <div class="form-text">
                                    Changing this address requires your current password and a new verification.
                                </div>
                            @endif
                        </div>

                        <div class="mb-4">
                            <label for="current_password" class="form-label">
                                Current Password <span class="text-secondary fw-normal">(required only to change email)</span>
                            </label>
                            <input
                                type="password"
                                class="form-control @error('current_password') is-invalid @enderror"
                                id="current_password"
                                name="current_password"
                                autocomplete="current-password"
                            >
                            @error('current_password')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <button type="submit" class="btn btn-market">Save Profile</button>
                    </form>

                    @if ($user->role === \App\Models\User::ROLE_CLIENT)
                        <section class="border rounded-3 bg-light p-3 p-md-4 mt-4" aria-labelledby="my-reviews-heading">
                            <h2 id="my-reviews-heading" class="h5 fw-bold mb-1">My Reviews</h2>
                            <p class="text-secondary small mb-3">Your published market and food reviews, newest first.</p>
                            <div class="row g-3">
                                <div class="col-12 col-lg-6"><h3 class="h6 fw-bold">Market Reviews</h3>
                                    @forelse ($marketReviews as $review)
                                        <article class="border rounded-3 bg-white p-3 mb-2"><div class="d-flex justify-content-between gap-2"><strong>{{ str_repeat('★', $review->rating) }}{{ str_repeat('☆', 5 - $review->rating) }} <span class="visually-hidden">{{ $review->rating }} out of 5 stars</span></strong><small class="text-secondary">{{ $review->created_at->format('M j, Y') }}</small></div><p class="mb-2">{{ $review->comment }}</p><div class="d-flex flex-wrap align-items-center gap-2"><a href="{{ route('night-markets.show', $review->nightMarket) }}" class="small fw-semibold">{{ $review->nightMarket->name }} details</a><form method="POST" action="{{ route('client.night-markets.reviews.destroy', [$review->nightMarket, $review]) }}" onsubmit="return confirm('Delete your review for {{ addslashes($review->nightMarket->name) }}?');">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger" type="submit">Delete review</button></form></div>@if ($review->tags->isNotEmpty())<div class="d-flex flex-wrap gap-1 mt-2">@foreach($review->tags as $tag)<span class="badge text-bg-light border text-dark">{{ $tag->name }}</span>@endforeach</div>@endif</article>
                                    @empty <p class="text-secondary small mb-0">You have not written any reviews yet.</p>@endforelse
                                </div>
                                <div class="col-12 col-lg-6"><h3 class="h6 fw-bold">Food Reviews</h3>
                                    @forelse ($foodReviews as $review)
                                        <article class="border rounded-3 bg-white p-3 mb-2"><div class="d-flex justify-content-between gap-2"><strong>{{ str_repeat('★', $review->rating) }}{{ str_repeat('☆', 5 - $review->rating) }} <span class="visually-hidden">{{ $review->rating }} out of 5 stars</span></strong><small class="text-secondary">{{ $review->created_at->format('M j, Y') }}</small></div><p class="mb-2">{{ $review->comment }}</p><div class="d-flex flex-wrap align-items-center gap-2"><a href="{{ route('foods.show', $review->food) }}" class="small fw-semibold">{{ $review->food->name }} details</a><span class="small text-secondary">{{ $review->food->stall->nightMarket->name }}</span><form method="POST" action="{{ route('client.foods.reviews.destroy', [$review->food, $review]) }}" onsubmit="return confirm('Delete your review for {{ addslashes($review->food->name) }}?');">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger" type="submit">Delete review</button></form></div>@if ($review->tags->isNotEmpty())<div class="d-flex flex-wrap gap-1 mt-2">@foreach($review->tags as $tag)<span class="badge text-bg-light border text-dark">{{ $tag->name }}</span>@endforeach</div>@endif</article>
                                    @empty <p class="text-secondary small mb-0">You have not written any reviews yet.</p>@endforelse
                                </div>
                            </div>
                        </section>

                        <section class="border rounded-3 bg-light p-3 p-md-4 mt-4"
                            aria-labelledby="connected-accounts-heading">
                            <div class="d-flex flex-column flex-sm-row justify-content-between gap-2 mb-3">
                                <div>
                                    <h2 id="connected-accounts-heading" class="h5 fw-bold mb-1">Connected Accounts</h2>
                                    <p class="text-secondary small mb-0">
                                        Connect Google for another secure way to access your Client account.
                                    </p>
                                </div>
                                <span class="badge {{ $user->googleAccount ? 'text-bg-success' : 'text-bg-secondary' }} align-self-start">
                                    Google: {{ $user->googleAccount ? 'Connected' : 'Not connected' }}
                                </span>
                            </div>

                            @if ($user->googleAccount)
                                <p class="mb-3">
                                    Connected email:
                                    <strong>{{ $user->googleAccount->provider_email }}</strong>
                                </p>

                                @if ($user->password === null)
                                    <div class="alert alert-info mb-0" role="alert">
                                        This is your only login method. To disconnect Google, log out and use
                                        Forgot Password on the Login page to establish a local password first.
                                    </div>
                                @else
                                    <form method="POST" action="{{ route('profile.google.disconnect') }}" novalidate
                                        onsubmit="return confirm('Disconnect Google from your account?');">
                                        @csrf
                                        @method('DELETE')

                                        <div class="mb-3">
                                            <label for="disconnect_current_password" class="form-label">
                                                Current Password
                                            </label>
                                            <input
                                                type="password"
                                                class="form-control @error('current_password') is-invalid @enderror"
                                                id="disconnect_current_password"
                                                name="current_password"
                                                autocomplete="current-password"
                                                required
                                            >
                                            @error('current_password')
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>

                                        <button type="submit" class="btn btn-outline-danger">Disconnect Google</button>
                                    </form>
                                @endif
                            @else
                                <form method="POST" action="{{ route('profile.google.connect') }}" novalidate>
                                    @csrf

                                    @if ($user->password !== null)
                                        <div class="mb-3">
                                            <label for="connect_current_password" class="form-label">
                                                Current Password
                                            </label>
                                            <input
                                                type="password"
                                                class="form-control @error('current_password') is-invalid @enderror"
                                                id="connect_current_password"
                                                name="current_password"
                                                autocomplete="current-password"
                                                required
                                            >
                                            @error('current_password')
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    @endif

                                    <button type="submit" class="btn btn-outline-secondary">
                                        <i class="bi bi-google me-2" aria-hidden="true"></i>Connect Google
                                    </button>
                                </form>
                            @endif
                        </section>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
