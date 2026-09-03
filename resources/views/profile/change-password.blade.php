@extends('layouts.app')

@section('title', 'Change Password | Night Market Selangor')

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-lg-7">
            <div class="card market-card">
                <div class="card-body p-4 p-md-5">
                    <div class="mb-4">
                        <h1 class="h3 fw-bold text-market mb-1">Change Password</h1>
                        <p class="text-secondary mb-0">Confirm your current password before choosing a new one.</p>
                    </div>

                    <form method="POST" action="{{ route('profile.password.update') }}" novalidate>
                        @csrf
                        @method('PATCH')

                        <div class="mb-3">
                            <label for="current_password" class="form-label">Current Password</label>
                            <div class="input-group"><input type="password" class="form-control @error('current_password') is-invalid @enderror" id="current_password" name="current_password" autocomplete="current-password" autofocus required><button class="btn btn-outline-secondary" type="button" data-password-toggle="current_password" aria-label="Show current password"><i class="bi bi-eye" aria-hidden="true"></i></button>@error('current_password')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label">New Password</label>
                            <div class="input-group"><input type="password" class="form-control @error('password') is-invalid @enderror" id="password" name="password" autocomplete="new-password" required><button class="btn btn-outline-secondary" type="button" data-password-toggle="password" aria-label="Show new password"><i class="bi bi-eye" aria-hidden="true"></i></button>@error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        </div>

                        <div class="mb-4">
                            <label for="password_confirmation" class="form-label">Confirm New Password</label>
                            <div class="input-group"><input type="password" class="form-control" id="password_confirmation" name="password_confirmation" autocomplete="new-password" required><button class="btn btn-outline-secondary" type="button" data-password-toggle="password_confirmation" aria-label="Show password confirmation"><i class="bi bi-eye" aria-hidden="true"></i></button></div>
                        </div>

                        <div class="d-flex flex-wrap gap-2">
                            <button type="submit" class="btn btn-market">Change Password</button>
                            <a href="{{ route('profile.edit') }}" class="btn btn-outline-secondary">Back to Profile</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
