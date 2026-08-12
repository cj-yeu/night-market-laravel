@extends('layouts.app')

@section('title', 'User Management | Night Market Selangor')

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="display-6 fw-bold text-market mb-1">User Management</h1>
            <p class="text-secondary mb-0">Search accounts and manage account access status.</p>
        </div>
        <a href="{{ route('admin.dashboard') }}" class="btn btn-outline-secondary">Back to Dashboard</a>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger" role="alert">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="card market-card mb-4">
        <div class="card-body p-4">
            <form method="GET" action="{{ route('admin.users.index') }}">
                <div class="row g-3 align-items-end">
                    <div class="col-12 col-lg-5">
                        <label for="search" class="form-label">Search</label>
                        <input type="search" id="search" name="search" maxlength="255"
                            value="{{ $filters['search'] ?? '' }}" class="form-control"
                            placeholder="Search by name or email">
                    </div>
                    <div class="col-sm-6 col-lg-2">
                        <label for="role" class="form-label">Role</label>
                        <select id="role" name="role" class="form-select">
                            <option value="">All</option>
                            <option value="admin" @selected(($filters['role'] ?? '') === 'admin')>Admin</option>
                            <option value="client" @selected(($filters['role'] ?? '') === 'client')>Client</option>
                        </select>
                    </div>
                    <div class="col-sm-6 col-lg-2">
                        <label for="status" class="form-label">Status</label>
                        <select id="status" name="status" class="form-select">
                            <option value="">All</option>
                            <option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option>
                            <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Inactive</option>
                        </select>
                    </div>
                    <div class="col-12 col-lg-3 d-flex gap-2">
                        <button type="submit" class="btn btn-market">Apply Filters</button>
                        @if (array_filter($filters))
                            <a href="{{ route('admin.users.index') }}" class="btn btn-outline-secondary">Clear</a>
                        @endif
                    </div>
                </div>
            </form>
        </div>
    </div>

    @if ($users->isEmpty())
        <div class="alert alert-info" role="alert">
            No users match the current search and filters.
        </div>
    @else
        <div class="card market-card overflow-hidden">
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Name</th>
                            <th scope="col">Email</th>
                            <th scope="col">Role</th>
                            <th scope="col">Account Status</th>
                            <th scope="col">Created Date</th>
                            <th scope="col">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($users as $user)
                            <tr>
                                <td class="fw-semibold">{{ $user->name }}</td>
                                <td>{{ $user->email }}</td>
                                <td>{{ ucfirst($user->role) }}</td>
                                <td>
                                    @if ($user->is_active)
                                        <span class="badge text-bg-success">Active</span>
                                    @else
                                        <span class="badge text-bg-secondary">Inactive</span>
                                    @endif
                                </td>
                                <td>{{ $user->created_at->format('d M Y') }}</td>
                                <td>
                                    @if (auth()->id() === $user->id && $user->is_active)
                                        <button type="button" class="btn btn-sm btn-outline-secondary" disabled
                                            title="You cannot deactivate your own account.">
                                            Current Account
                                        </button>
                                    @else
                                        <form method="POST" action="{{ route('admin.users.status.update', $user) }}"
                                            onsubmit="return confirm('{{ $user->is_active ? 'Deactivate' : 'Activate' }} this user account?');">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="is_active" value="{{ $user->is_active ? 0 : 1 }}">
                                            @foreach (['search', 'role', 'status'] as $filter)
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
                                    @endif
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
