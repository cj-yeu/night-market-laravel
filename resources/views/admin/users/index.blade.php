@extends('layouts.app')

@section('title', 'User Management | Night Market Selangor')

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1">User Management</h1>
            <p class="text-secondary mb-0">Review account security and manage Client access.</p>
        </div>
        <span class="badge text-bg-light border align-self-start px-3 py-2">
            {{ $users->total() }} {{ Str::plural('account', $users->total()) }}
        </span>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
    @endif

    <div class="card market-card mb-4">
        <div class="card-body p-4">
            <form method="GET" action="{{ route('admin.users.index') }}">
                <div class="row g-3 align-items-end">
                    <div class="col-12 col-lg-4">
                        <label for="search" class="form-label">Search</label>
                        <input type="search" id="search" name="search" maxlength="100"
                            value="{{ $filters['search'] ?? '' }}" class="form-control"
                            placeholder="Partial name or email">
                    </div>
                    <div class="col-6 col-md-3 col-lg-2">
                        <label for="role" class="form-label">Role</label>
                        <select id="role" name="role" class="form-select">
                            <option value="">All roles</option>
                            <option value="admin" @selected(($filters['role'] ?? '') === 'admin')>Admin</option>
                            <option value="client" @selected(($filters['role'] ?? '') === 'client')>Client</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-3 col-lg-2">
                        <label for="status" class="form-label">Status</label>
                        <select id="status" name="status" class="form-select">
                            <option value="">All statuses</option>
                            <option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option>
                            <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Inactive</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-3 col-lg-2">
                        <label for="verification" class="form-label">Email</label>
                        <select id="verification" name="verification" class="form-select">
                            <option value="">Any verification</option>
                            <option value="verified" @selected(($filters['verification'] ?? '') === 'verified')>Verified</option>
                            <option value="pending" @selected(($filters['verification'] ?? '') === 'pending')>Pending</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-3 col-lg-2">
                        <label for="auth_method" class="form-label">Sign-in method</label>
                        <select id="auth_method" name="auth_method" class="form-select">
                            <option value="">Any method</option>
                            <option value="password" @selected(($filters['auth_method'] ?? '') === 'password')>Password</option>
                            <option value="google" @selected(($filters['auth_method'] ?? '') === 'google')>Google</option>
                            <option value="password_and_google" @selected(($filters['auth_method'] ?? '') === 'password_and_google')>
                                Password + Google
                            </option>
                        </select>
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-market">Apply Filters</button>
                        <a href="{{ route('admin.users.index') }}" class="btn btn-outline-secondary">Reset Filters</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    @if ($users->isEmpty())
        <div class="alert alert-info" role="alert">No users match the current search and filters.</div>
    @else
        <div class="card market-card overflow-hidden">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">User</th>
                            <th scope="col">Role</th>
                            <th scope="col">Status</th>
                            <th scope="col">Email verification</th>
                            <th scope="col">Authentication</th>
                            <th scope="col">Registered</th>
                            <th scope="col" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($users as $user)
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <x-user-avatar :user="$user" size="sm" />
                                        <div>
                                            <div class="fw-semibold">{{ $user->name }}</div>
                                            <div class="small text-secondary">{{ $user->email }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="badge text-bg-light border">{{ ucfirst($user->role) }}</span></td>
                                <td>
                                    <span class="badge {{ $user->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">
                                        {{ $user->is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>
                                <td>
                                    <span class="badge {{ $user->hasVerifiedEmail() ? 'text-bg-success' : 'text-bg-warning' }}">
                                        {{ $user->hasVerifiedEmail() ? 'Verified' : 'Pending' }}
                                    </span>
                                </td>
                                <td><span class="badge text-bg-info">{{ $user->authenticationMethodLabel() }}</span></td>
                                <td>{{ $user->created_at->format('d M Y') }}</td>
                                <td>
                                    <div class="d-flex justify-content-end gap-2">
                                        <a href="{{ route('admin.users.show', $user) }}" class="btn btn-sm btn-outline-secondary">View</a>

                                        @if ($user->role === \App\Models\User::ROLE_CLIENT)
                                            <form method="POST" action="{{ route('admin.users.status.update', $user) }}"
                                                onsubmit="return confirm('{{ $user->is_active ? 'Deactivate this Client account?' : 'Activate this Client account?' }}');">
                                                @csrf
                                                @method('PATCH')
                                                <input type="hidden" name="is_active" value="{{ $user->is_active ? 0 : 1 }}">
                                                @foreach (['search', 'role', 'status', 'verification', 'auth_method'] as $filter)
                                                    @if (! empty($filters[$filter]))
                                                        <input type="hidden" name="{{ $filter }}" value="{{ $filters[$filter] }}">
                                                    @endif
                                                @endforeach
                                                <input type="hidden" name="page" value="{{ $users->currentPage() }}">
                                                <button type="submit"
                                                    class="btn btn-sm {{ $user->is_active ? 'btn-outline-danger' : 'btn-outline-success' }}">
                                                    {{ $user->is_active ? 'Deactivate' : 'Activate' }}
                                                </button>
                                            </form>
                                        @else
                                            <span class="badge text-bg-light border align-self-center">Read only</span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        @if ($users->hasPages())
            <nav class="d-flex justify-content-between align-items-center mt-4" aria-label="User pagination">
                <a class="btn btn-outline-secondary {{ $users->onFirstPage() ? 'disabled' : '' }}"
                    href="{{ $users->previousPageUrl() ?: '#' }}">Previous</a>
                <span class="text-secondary">Page {{ $users->currentPage() }} of {{ $users->lastPage() }}</span>
                <a class="btn btn-outline-secondary {{ $users->hasMorePages() ? '' : 'disabled' }}"
                    href="{{ $users->nextPageUrl() ?: '#' }}">Next</a>
            </nav>
        @endif
    @endif
@endsection
