@extends('layouts.app')

@section('title', 'Review Management | Night Market Selangor')

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="display-6 fw-bold text-market mb-1">Review Management</h1>
            <p class="text-secondary mb-0">Approve or reject reviews awaiting moderation.</p>
        </div>
        <span class="badge rounded-pill text-bg-warning fs-6">{{ $reviews->count() }} pending</span>
    </div>

    @if ($reviews->isEmpty())
        <div class="alert alert-info" role="alert">
            There are no pending reviews to moderate.
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
                                <span class="badge text-bg-warning align-self-start">
                                    {{ $review->rating }}/5 stars
                                </span>
                            </div>

                            <p class="mb-4">{{ $review->comment }}</p>

                            <div class="d-flex flex-wrap gap-2">
                                <form method="POST" action="{{ route('admin.reviews.update', $review) }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="status" value="approved">
                                    <button type="submit" class="btn btn-success">Approve</button>
                                </form>

                                <form method="POST" action="{{ route('admin.reviews.update', $review) }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="status" value="rejected">
                                    <button type="submit" class="btn btn-outline-danger">Reject</button>
                                </form>
                            </div>
                        </div>
                    </article>
                </div>
            @endforeach
        </div>
    @endif
@endsection
