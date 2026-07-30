@extends('layouts.app')

@section('title', 'Add Social Media Record | Night Market Selangor')

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-xl-8">
            <div class="card market-card">
                <div class="card-body p-4 p-md-5">
                    <h1 class="h3 fw-bold text-market">Add Social Media Record</h1>
                    <p class="text-secondary mb-4">Manually record public social media content for later analysis.</p>

                    <form method="POST" action="{{ route('admin.social-media-records.store') }}" novalidate>
                        @csrf

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="platform" class="form-label">Platform</label>
                                <input type="text" class="form-control @error('platform') is-invalid @enderror"
                                    id="platform" name="platform" value="{{ old('platform') }}"
                                    placeholder="e.g. Facebook, Instagram, TikTok" required>
                                @error('platform')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6">
                                <label for="post_date" class="form-label">Post Date</label>
                                <input type="date" class="form-control @error('post_date') is-invalid @enderror"
                                    id="post_date" name="post_date" value="{{ old('post_date') }}" required>
                                @error('post_date')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-12">
                                <label for="post_url" class="form-label">Post URL</label>
                                <input type="url" class="form-control @error('post_url') is-invalid @enderror"
                                    id="post_url" name="post_url" value="{{ old('post_url') }}"
                                    placeholder="https://">
                                @error('post_url')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-12">
                                <label for="content" class="form-label">Pasted Content</label>
                                <textarea class="form-control @error('content') is-invalid @enderror"
                                    id="content" name="content" rows="7" required>{{ old('content') }}</textarea>
                                @error('content')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6">
                                <label for="engagement_count" class="form-label">Engagement Count</label>
                                <input type="number" min="0"
                                    class="form-control @error('engagement_count') is-invalid @enderror"
                                    id="engagement_count" name="engagement_count"
                                    value="{{ old('engagement_count', 0) }}" required>
                                @error('engagement_count')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6">
                                <label for="mentioned_market_name" class="form-label">Mentioned Market Name</label>
                                <input type="text"
                                    class="form-control @error('mentioned_market_name') is-invalid @enderror"
                                    id="mentioned_market_name" name="mentioned_market_name"
                                    value="{{ old('mentioned_market_name') }}">
                                @error('mentioned_market_name')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6">
                                <label for="mentioned_food_name" class="form-label">Mentioned Food Name</label>
                                <input type="text"
                                    class="form-control @error('mentioned_food_name') is-invalid @enderror"
                                    id="mentioned_food_name" name="mentioned_food_name"
                                    value="{{ old('mentioned_food_name') }}">
                                @error('mentioned_food_name')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <button type="submit" class="btn btn-market mt-4">Add Record</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
