@extends('layouts.app')

@section('title', 'Review '.$nightMarket->name.' | Night Market Selangor')

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-lg-7">
            <a href="{{ route('client.night-markets.show', $nightMarket) }}"
                class="btn btn-outline-secondary mb-4">Back to Night Market</a>

            <div class="card market-card">
                <div class="card-body p-4 p-md-5">
                    <h1 class="h3 fw-bold text-market">Review {{ $nightMarket->name }}</h1>
                    <p class="text-secondary mb-4">
                        Share your experience. Your review will appear publicly after administrator approval.
                    </p>

                    <form method="POST"
                        action="{{ route('client.night-markets.reviews.store', $nightMarket) }}" novalidate>
                        @csrf

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
                                id="comment" name="comment" rows="5" maxlength="1000" required>{{ old('comment') }}</textarea>
                            <div class="form-text">Enter between 10 and 1,000 characters.</div>
                            @error('comment')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <button type="submit" class="btn btn-market">Submit Review</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
