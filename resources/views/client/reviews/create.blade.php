@extends('layouts.app')

@section('title', 'Submit Review | Night Market Selangor')

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-lg-7">
            <div class="card market-card">
                <div class="card-body p-4 p-md-5">
                    <h1 class="h3 fw-bold text-market">Submit a Review</h1>
                    <p class="text-secondary mb-4">Share your experience at an active Selangor night market.</p>

                    @if ($nightMarkets->isEmpty())
                        <div class="alert alert-warning mb-0">
                            No active night markets are currently available for review.
                        </div>
                    @else
                        <form method="POST" action="{{ route('client.reviews.store') }}" novalidate>
                            @csrf

                            <div class="mb-3">
                                <label for="night_market_id" class="form-label">Night Market</label>
                                <select class="form-select @error('night_market_id') is-invalid @enderror"
                                    id="night_market_id" name="night_market_id" required>
                                    <option value="">Select a night market</option>
                                    @foreach ($nightMarkets as $nightMarket)
                                        <option value="{{ $nightMarket->id }}"
                                            @selected((string) old('night_market_id') === (string) $nightMarket->id)>
                                            {{ $nightMarket->name }} — {{ $nightMarket->city }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('night_market_id')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="mb-3">
                                <label for="rating" class="form-label">Rating</label>
                                <select class="form-select @error('rating') is-invalid @enderror"
                                    id="rating" name="rating" required>
                                    <option value="">Select a rating</option>
                                    @for ($rating = 5; $rating >= 1; $rating--)
                                        <option value="{{ $rating }}" @selected((int) old('rating') === $rating)>
                                            {{ $rating }} {{ $rating === 1 ? 'star' : 'stars' }}
                                        </option>
                                    @endfor
                                </select>
                                @error('rating')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="mb-4">
                                <label for="comment" class="form-label">Comment</label>
                                <textarea class="form-control @error('comment') is-invalid @enderror"
                                    id="comment" name="comment" rows="5">{{ old('comment') }}</textarea>
                                @error('comment')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <button type="submit" class="btn btn-market">Submit Review</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
