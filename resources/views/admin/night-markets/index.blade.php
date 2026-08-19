@extends('layouts.app')

@section('title', 'Manage Night Markets | Night Market Selangor')

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h2 fw-bold mb-1">Night Markets</h1>
            <p class="text-secondary mb-0">Manage catalog details, operating schedules, and public availability.</p>
        </div>
        <a href="{{ route('admin.night-markets.create') }}" class="btn btn-market align-self-start">Create Night Market</a>
    </div>

    <div class="card market-card mb-4">
        <div class="card-body p-4">
            <form method="GET" action="{{ route('admin.night-markets.index') }}" class="row g-3 align-items-end">
                <div class="col-12 col-lg-4">
                    <label for="search" class="form-label">Name or location</label>
                    <input id="search" name="search" type="search" value="{{ $filters['search'] ?? '' }}"
                        class="form-control @error('search') is-invalid @enderror">
                    @error('search') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-6 col-lg-2">
                    <label for="city" class="form-label">City</label>
                    <select id="city" name="city" class="form-select @error('city') is-invalid @enderror">
                        <option value="">All cities</option>
                        @foreach ($cities as $city)
                            <option value="{{ $city->city }}" @selected(($filters['city'] ?? '') === $city->city)>{{ $city->city }}</option>
                        @endforeach
                    </select>
                    @error('city') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-6 col-lg-2">
                    <label for="operating_day" class="form-label">Operating day</label>
                    <select id="operating_day" name="operating_day" class="form-select @error('operating_day') is-invalid @enderror">
                        <option value="">All days</option>
                        @foreach ($days as $day)
                            <option value="{{ $day }}" @selected(($filters['operating_day'] ?? '') === $day)>{{ $day }}</option>
                        @endforeach
                    </select>
                    @error('operating_day') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-6 col-lg-2">
                    <label for="status" class="form-label">Status</label>
                    <select id="status" name="status" class="form-select @error('status') is-invalid @enderror">
                        <option value="">All statuses</option>
                        <option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option>
                        <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Inactive</option>
                    </select>
                    @error('status') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-6 col-lg-2 d-flex gap-2">
                    <button class="btn btn-market" type="submit">Apply</button>
                    <a class="btn btn-outline-secondary" href="{{ route('admin.night-markets.index') }}">Reset</a>
                </div>
            </form>
        </div>
    </div>

    @if ($nightMarkets->isEmpty())
        <div class="alert alert-info">No night markets match the current filters.</div>
    @else
        <div class="card market-card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Market</th><th>Location</th><th>Schedule</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                        @foreach ($nightMarkets as $nightMarket)
                            <tr>
                                <td><strong>{{ $nightMarket->name }}</strong></td>
                                <td>{{ $nightMarket->city }}, {{ $nightMarket->state }}<div class="small text-secondary">{{ $nightMarket->address }}</div></td>
                                <td>
                                    @foreach ($nightMarket->operatingDays as $day)
                                        <div class="small">{{ $day->day_of_week }} {{ $day->opening_time->format('H:i') }}–{{ $day->closing_time->format('H:i') }}</div>
                                    @endforeach
                                </td>
                                <td><span class="badge {{ $nightMarket->status === 'active' ? 'text-bg-success' : 'text-bg-secondary' }}">{{ ucfirst($nightMarket->status) }}</span></td>
                                <td>
                                    <div class="d-flex justify-content-end flex-wrap gap-2">
                                        <a href="{{ route('admin.night-markets.show', $nightMarket) }}" class="btn btn-sm btn-outline-secondary">View</a>
                                        <a href="{{ route('admin.night-markets.edit', $nightMarket) }}" class="btn btn-sm btn-outline-primary">Edit</a>
                                        <form method="POST" action="{{ $nightMarket->status === 'active' ? route('admin.night-markets.deactivate', $nightMarket) : route('admin.night-markets.activate', $nightMarket) }}"
                                            onsubmit="return confirm('{{ $nightMarket->status === 'active' ? 'Deactivate this night market?' : 'Activate this night market?' }}');">
                                            @csrf @method('PATCH')
                                            <button class="btn btn-sm {{ $nightMarket->status === 'active' ? 'btn-outline-danger' : 'btn-outline-success' }}" type="submit">
                                                {{ $nightMarket->status === 'active' ? 'Deactivate' : 'Activate' }}
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @if ($nightMarkets->hasPages())
            <nav class="d-flex justify-content-between align-items-center mt-4" aria-label="Night market pagination">
                <a class="btn btn-outline-secondary {{ $nightMarkets->onFirstPage() ? 'disabled' : '' }}" href="{{ $nightMarkets->previousPageUrl() ?: '#' }}">Previous</a>
                <span class="text-secondary">Page {{ $nightMarkets->currentPage() }} of {{ $nightMarkets->lastPage() }}</span>
                <a class="btn btn-outline-secondary {{ $nightMarkets->hasMorePages() ? '' : 'disabled' }}" href="{{ $nightMarkets->nextPageUrl() ?: '#' }}">Next</a>
            </nav>
        @endif
    @endif
@endsection
