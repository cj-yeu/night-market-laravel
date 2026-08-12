@extends('layouts.app')

@section('title', 'Review Management | Night Market Selangor')

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="display-6 fw-bold text-market mb-1">Review Management</h1>
            <p class="text-secondary mb-0">Search, review, approve, or reject client feedback.</p>
        </div>
        <span class="badge rounded-pill text-bg-dark fs-6">
            {{ $reviews->count() }} {{ $reviews->count() === 1 ? 'review' : 'reviews' }}
        </span>
    </div>

    <div class="card market-card mb-4">
        <div class="card-body p-4">
            <form method="GET" action="{{ route('admin.reviews.index') }}">
                <div class="row g-3 align-items-end">
                    <div class="col-12 col-xl-5">
                        <label for="search" class="form-label">Search Reviews</label>
                        <input type="search" class="form-control @error('search') is-invalid @enderror"
                            id="search" name="search" value="{{ $filters['search'] ?? '' }}"
                            placeholder="Search review text, reviewer, or market">
                        @error('search')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-12 col-md-6 col-xl-3">
                        <label for="market_id" class="form-label">Night Market</label>
                        <select class="form-select @error('market_id') is-invalid @enderror"
                            id="market_id" name="market_id">
                            <option value="">All Markets</option>
                            @foreach ($markets as $market)
                                <option value="{{ $market->id }}"
                                    @selected((string) ($filters['market_id'] ?? '') === (string) $market->id)>
                                    {{ $market->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('market_id')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-12 col-md-6 col-xl-2">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-select @error('status') is-invalid @enderror"
                            id="status" name="status">
                            <option value="">All Statuses</option>
                            @foreach ($statusOptions as $status => $label)
                                <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                        @error('status')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-12 col-xl-2 d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-market">Apply Filters</button>
                        @if (($filters['search'] ?? null) || ($filters['market_id'] ?? null) || ($filters['status'] ?? null))
                            <a href="{{ route('admin.reviews.index') }}"
                                class="btn btn-outline-secondary">Reset Filters</a>
                        @endif
                    </div>
                </div>
            </form>
        </div>
    </div>

    @if ($reviews->isEmpty())
        <div class="alert alert-info text-center py-4" role="status">
            <h2 class="h5 mb-2">No reviews found</h2>
            <p class="mb-0">
                {{ (($filters['search'] ?? null) || ($filters['market_id'] ?? null) || ($filters['status'] ?? null))
                    ? 'No reviews match the selected filters.'
                    : 'No client reviews have been submitted yet.' }}
            </p>
        </div>
    @else
        <div class="row g-4">
            @foreach ($reviews as $review)
                <div class="col-12 col-lg-6">
                    <article class="card market-card h-100">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between gap-3 mb-3">
                                <div>
                                    <h2 class="h5 fw-bold mb-1">{{ $review->nightMarket->name }}</h2>
                                    <p class="text-secondary small mb-0">
                                        {{ $review->user->name }} &middot; {{ $review->user->email }}
                                    </p>
                                </div>
                                <div class="d-flex flex-column align-items-end gap-2">
                                    <span class="badge text-bg-warning">{{ $review->rating }}/5 stars</span>
                                    @switch($review->status)
                                        @case(\App\Models\Review::STATUS_APPROVED)
                                            <span class="badge text-bg-success">Approved</span>
                                            @break
                                        @case(\App\Models\Review::STATUS_REJECTED)
                                            <span class="badge text-bg-danger">Rejected</span>
                                            @break
                                        @default
                                            <span class="badge text-bg-secondary">Pending</span>
                                    @endswitch
                                </div>
                            </div>

                            <p class="mb-2">{{ $review->comment }}</p>
                            <p class="small text-secondary mb-4">
                                Submitted {{ $review->created_at->format('M j, Y') }}
                            </p>

                            <div class="d-flex flex-wrap gap-2">
                                @if ($review->status !== \App\Models\Review::STATUS_APPROVED)
                                    <form method="POST" action="{{ route('admin.reviews.update', $review) }}"
                                        onsubmit="return confirm('Approve this review?')">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="status" value="approved">
                                        <button type="submit" class="btn btn-success">Approve</button>
                                    </form>
                                @endif

                                @if ($review->status !== \App\Models\Review::STATUS_REJECTED)
                                    <form method="POST" action="{{ route('admin.reviews.update', $review) }}"
                                        onsubmit="return confirm('{{ $review->status === \App\Models\Review::STATUS_APPROVED ? 'Unapprove' : 'Reject' }} this review?')">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="status" value="rejected">
                                        <button type="submit" class="btn btn-outline-danger">
                                            {{ $review->status === \App\Models\Review::STATUS_APPROVED ? 'Unapprove' : 'Reject' }}
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </article>
                </div>
            @endforeach
        </div>
    @endif
@endsection
