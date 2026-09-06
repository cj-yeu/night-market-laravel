@extends('layouts.app')

@section('title', 'Manage Stalls | '.config('app.name'))

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-4">
        <div><h1 class="h2 fw-bold mb-1">Stalls</h1><p class="text-secondary mb-0">Manage stall assignments, metadata, and operational availability.</p></div>
        <div class="d-flex flex-wrap gap-2 align-self-start"><a href="{{ route('admin.ai-import.index',['module'=>'stalls']) }}" class="btn btn-outline-market">AI Import</a><a href="{{ route('admin.stalls.create') }}" class="btn btn-market">Create Stall</a></div>
    </div>

    @error('status')
        <div class="alert alert-danger" role="alert">{{ $message }}</div>
    @enderror

    <div class="card market-card mb-4"><div class="card-body p-4">
        <form method="GET" action="{{ route('admin.stalls.index') }}" class="row g-3 align-items-end">
            <div class="col-12 col-lg-4"><label for="search" class="form-label">Stall name or description</label><input id="search" name="search" type="search" value="{{ $filters['search'] ?? '' }}" class="form-control @error('search') is-invalid @enderror">@error('search')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-6 col-lg-2"><label for="night_market_id" class="form-label">Night Market</label><select id="night_market_id" name="night_market_id" class="form-select @error('night_market_id') is-invalid @enderror"><option value="">All markets</option>@foreach ($nightMarkets as $nightMarket)<option value="{{ $nightMarket->id }}" @selected((string) ($filters['night_market_id'] ?? '') === (string) $nightMarket->id)>{{ $nightMarket->name }}</option>@endforeach</select>@error('night_market_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-6 col-lg-2"><label for="category" class="form-label">Category</label><select id="category" name="category" class="form-select @error('category') is-invalid @enderror"><option value="">All categories</option>@foreach ($categories as $category)<option value="{{ $category->category }}" @selected(($filters['category'] ?? '') === $category->category)>{{ $category->category }}</option>@endforeach</select>@error('category')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-6 col-lg-2"><label for="halal_status" class="form-label">Halal status</label><select id="halal_status" name="halal_status" class="form-select @error('halal_status') is-invalid @enderror"><option value="">All classifications</option>@foreach ($halalStatuses as $value => $label)<option value="{{ $value }}" @selected(($filters['halal_status'] ?? '') === $value)>{{ $label }}</option>@endforeach</select>@error('halal_status')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-6 col-lg-2"><label for="status" class="form-label">Status</label><select id="status" name="status" class="form-select @error('status') is-invalid @enderror"><option value="">All statuses</option><option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option><option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Inactive</option></select>@error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-12 d-flex gap-2"><button class="btn btn-market" type="submit">Apply</button><a class="btn btn-outline-secondary" href="{{ route('admin.stalls.index') }}">Reset Filters</a></div>
        </form>
    </div></div>

    @if ($stalls->isEmpty())
        <div class="alert alert-info">No stalls match the current filters.</div>
    @else
        <div class="card market-card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
            <thead><tr><th>Image</th><th>Stall</th><th>Night Market</th><th>Category</th><th>Halal classification</th><th>Foods</th><th>Own Status</th><th>Public Visibility</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
                @foreach ($stalls as $stall)
                    <tr>
                        <td><x-stall-image :stall="$stall" class="catalog-thumbnail" /></td>
                        <td><strong>{{ $stall->name }}</strong><div class="small text-secondary">{{ str($stall->description)->limit(70) }}</div></td>
                        <td>{{ $stall->nightMarket->name }}<div class="small text-secondary">{{ $stall->nightMarket->city }}</div></td>
                        <td>{{ $stall->categoryLabel() ?: '—' }}</td>
                        <td><span class="badge {{ $stall->halalBadgeClass() }}">{{ $stall->halalStatusLabel() }}</span></td>
                        <td>{{ $stall->foods_count }}</td>
                        <td><span class="badge {{ $stall->status === 'active' ? 'text-bg-success' : 'text-bg-secondary' }}">{{ ucfirst($stall->status) }}</span></td>
                        <td><span class="badge {{ $stall->public_visibility === 'Visible' ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $stall->public_visibility }}</span>@if ($stall->public_visibility_reason)<div class="small text-secondary mt-1">{{ $stall->public_visibility_reason }}</div>@endif</td>
                        <td><div class="d-flex justify-content-end flex-wrap gap-2">
                            <a href="{{ route('admin.stalls.show', $stall) }}" class="btn btn-sm btn-outline-secondary">View</a>
                            <a href="{{ route('admin.stalls.edit', $stall) }}" class="btn btn-sm btn-outline-primary">Edit</a>
                            <form method="POST" action="{{ $stall->status === 'active' ? route('admin.stalls.deactivate', $stall) : route('admin.stalls.activate', $stall) }}" onsubmit="return confirm('{{ $stall->status === 'active' ? 'Deactivate this stall?' : 'Activate this stall?' }}');">@csrf @method('PATCH')<button class="btn btn-sm {{ $stall->status === 'active' ? 'btn-outline-danger' : 'btn-outline-success' }}" type="submit">{{ $stall->status === 'active' ? 'Deactivate' : 'Activate' }}</button></form>
                            @if ($stall->status === 'inactive')<form method="POST" action="{{ route('admin.stalls.destroy', $stall) }}" onsubmit="return confirm('Permanently delete {{ addslashes($stall->name) }}? This cannot be undone.');">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger" type="submit">Delete</button></form>@endif
                        </div></td>
                    </tr>
                @endforeach
            </tbody>
        </table></div></div>
        @if ($stalls->hasPages())<nav class="d-flex justify-content-between align-items-center mt-4" aria-label="Stall pagination"><a class="btn btn-outline-secondary {{ $stalls->onFirstPage() ? 'disabled' : '' }}" href="{{ $stalls->previousPageUrl() ?: '#' }}">Previous</a><span class="text-secondary">Page {{ $stalls->currentPage() }} of {{ $stalls->lastPage() }}</span><a class="btn btn-outline-secondary {{ $stalls->hasMorePages() ? '' : 'disabled' }}" href="{{ $stalls->nextPageUrl() ?: '#' }}">Next</a></nav>@endif
    @endif
@endsection
