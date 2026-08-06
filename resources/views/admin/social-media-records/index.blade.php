@extends('layouts.app')

@section('title', 'Social Media Records | Night Market Selangor')

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="display-6 fw-bold text-market mb-1">Social Media Records</h1>
            <p class="text-secondary mb-0">Manage manually collected public social media information.</p>
        </div>
        <a href="{{ route('admin.social-media-records.create') }}" class="btn btn-market">
            Add Social Media Record
        </a>
    </div>

    <div class="card market-card mb-4">
        <div class="card-body p-4">
            <form method="GET" action="{{ route('admin.social-media-records.index') }}">
                <div class="row g-3 align-items-end">
                    <div class="col-12 col-lg-5">
                        <label for="search" class="form-label">Keyword Search</label>
                        <input type="search" id="search" name="search" maxlength="255"
                            value="{{ $filters['search'] ?? '' }}" class="form-control"
                            placeholder="Market, food, summary, or post URL">
                    </div>
                    <div class="col-md-5 col-lg-3">
                        <label for="night_market_id" class="form-label">Night Market</label>
                        <select id="night_market_id" name="night_market_id" class="form-select">
                            <option value="">All night markets</option>
                            @foreach ($nightMarkets as $nightMarket)
                                <option value="{{ $nightMarket->id }}"
                                    @selected((string) ($filters['night_market_id'] ?? '') === (string) $nightMarket->id)>
                                    {{ $nightMarket->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4 col-lg-2">
                        <label for="platform" class="form-label">Platform</label>
                        <select id="platform" name="platform" class="form-select">
                            <option value="">All platforms</option>
                            @foreach ($platforms as $platform)
                                <option value="{{ $platform }}" @selected(($filters['platform'] ?? '') === $platform)>
                                    {{ $platform }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3 col-lg-2 d-flex gap-2">
                        <button type="submit" class="btn btn-market">Filter</button>
                        @if (array_filter($filters))
                            <a href="{{ route('admin.social-media-records.index') }}"
                                class="btn btn-outline-secondary">Clear</a>
                        @endif
                    </div>
                </div>
            </form>
        </div>
    </div>

    @if ($records->isEmpty())
        <div class="alert alert-info" role="alert">
            No social media records found. Add a record or adjust the current filters.
        </div>
    @else
        <div class="card market-card">
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Night Market</th>
                            <th>Related Food</th>
                            <th>Platform</th>
                            <th>Original Post</th>
                            <th>Caption / Content Summary</th>
                            <th>Posted Date</th>
                            <th>Likes</th>
                            <th>Comments</th>
                            <th>Shares</th>
                            <th>Last Updated</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($records as $record)
                            <tr>
                                <td>{{ $record->nightMarket?->name ?? 'Unavailable market' }}</td>
                                <td>{{ $record->food?->name ?? 'None' }}</td>
                                <td><span class="badge text-bg-warning">{{ $record->platform }}</span></td>
                                <td>
                                    <a href="{{ $record->original_post_url }}" target="_blank" rel="noopener noreferrer">
                                        Open post
                                    </a>
                                </td>
                                <td style="min-width: 16rem;">{{ $record->content_summary }}</td>
                                <td>{{ $record->posted_date->format('d M Y') }}</td>
                                <td>{{ number_format($record->likes) }}</td>
                                <td>{{ number_format($record->comments) }}</td>
                                <td>{{ number_format($record->shares) }}</td>
                                <td>{{ $record->updated_at->format('d M Y') }}</td>
                                <td>
                                    <div class="d-flex gap-2">
                                        <a href="{{ route('admin.social-media-records.edit', $record) }}"
                                            class="btn btn-sm btn-outline-secondary">Edit</a>
                                        <form method="POST"
                                            action="{{ route('admin.social-media-records.destroy', $record) }}"
                                            onsubmit="return confirm('Delete this social media record?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        @if ($records->hasPages())
            <nav class="d-flex justify-content-between align-items-center mt-4" aria-label="Social media records pagination">
                <a class="btn btn-outline-secondary {{ $records->onFirstPage() ? 'disabled' : '' }}"
                    href="{{ $records->previousPageUrl() ?: '#' }}">Previous</a>
                <span class="text-secondary">Page {{ $records->currentPage() }} of {{ $records->lastPage() }}</span>
                <a class="btn btn-outline-secondary {{ $records->hasMorePages() ? '' : 'disabled' }}"
                    href="{{ $records->nextPageUrl() ?: '#' }}">Next</a>
            </nav>
        @endif
    @endif
@endsection
