@extends('layouts.app')

@section('title', 'User Details | Night Market Selangor')

@section('content')
    <div class="mb-4">
        <a href="{{ route('admin.users.index') }}" class="text-decoration-none small">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to Users
        </a>
        <h1 class="h3 fw-bold mt-2 mb-1">User Details</h1>
        <p class="text-secondary mb-0">Safe account and authentication summary.</p>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
    @endif

    <div class="row g-4">
        <div class="col-12 col-xl-7">
            <section class="card admin-surface-card h-100" aria-labelledby="account-summary-heading">
                <div class="card-body p-4">
                    <div class="d-flex flex-column flex-sm-row align-items-sm-center gap-3 mb-4">
                        <x-user-avatar :user="$user" size="lg" />
                        <div>
                            <h2 id="account-summary-heading" class="h4 mb-1">{{ $user->name }}</h2>
                            <p class="text-secondary mb-2">{{ $user->email }}</p>
                            <div class="d-flex flex-wrap gap-2">
                                <span class="badge text-bg-light border">{{ ucfirst($user->role) }}</span>
                                <span class="badge {{ $user->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">
                                    {{ $user->is_active ? 'Active' : 'Inactive' }}
                                </span>
                                <span class="badge {{ $user->hasVerifiedEmail() ? 'text-bg-success' : 'text-bg-warning' }}">
                                    {{ $user->hasVerifiedEmail() ? 'Verified email' : 'Verification pending' }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <dl class="row mb-0">
                        <dt class="col-sm-5">Registered</dt>
                        <dd class="col-sm-7">{{ $user->created_at->format('d M Y, g:i A') }}</dd>
                        <dt class="col-sm-5">Last updated</dt>
                        <dd class="col-sm-7">{{ $user->updated_at->format('d M Y, g:i A') }}</dd>
                        <dt class="col-sm-5">Email verified</dt>
                        <dd class="col-sm-7">{{ $user->email_verified_at?->format('d M Y, g:i A') ?? 'Pending' }}</dd>
                        <dt class="col-sm-5">Reviews</dt>
                        <dd class="col-sm-7">{{ $user->reviews_count }}</dd>
                        <dt class="col-sm-5">Visit plans</dt>
                        <dd class="col-sm-7">{{ $user->visit_plans_count }}</dd>
                    </dl>
                </div>
            </section>
        </div>

        <div class="col-12 col-xl-5">
            <section class="card admin-surface-card mb-4" aria-labelledby="security-summary-heading">
                <div class="card-body p-4">
                    <h2 id="security-summary-heading" class="h5 mb-3">Authentication</h2>
                    <dl class="row mb-0">
                        <dt class="col-sm-6">Sign-in method</dt>
                        <dd class="col-sm-6">{{ $user->authenticationMethodLabel() }}</dd>
                        <dt class="col-sm-6">Google connected</dt>
                        <dd class="col-sm-6">{{ $user->googleAccount ? 'Connected' : 'Not connected' }}</dd>
                    </dl>
                </div>
            </section>

            <section class="card admin-surface-card" aria-labelledby="access-management-heading">
                <div class="card-body p-4">
                    <h2 id="access-management-heading" class="h5 mb-2">Account Access</h2>

                    @if ($user->role === \App\Models\User::ROLE_CLIENT)
                        <p class="text-secondary small">
                            {{ $user->is_active
                                ? 'Deactivation signs this Client out on their next request.'
                                : 'Activation allows this Client to authenticate again.' }}
                        </p>
                        <form method="POST" action="{{ route('admin.users.status.update', $user) }}"
                            onsubmit="return confirm('{{ $user->is_active ? 'Deactivate this Client account?' : 'Activate this Client account?' }}');">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="is_active" value="{{ $user->is_active ? 0 : 1 }}">
                            <input type="hidden" name="redirect_to" value="show">
                            <button type="submit"
                                class="btn {{ $user->is_active ? 'btn-outline-danger' : 'btn-outline-success' }}">
                                {{ $user->is_active ? 'Deactivate Client' : 'Activate Client' }}
                            </button>
                        </form>
                    @else
                        <div class="alert alert-light border mb-0" role="status">
                            Admin accounts are read-only in User Management.
                        </div>
                    @endif
                </div>
            </section>
        </div>
    </div>
@endsection
