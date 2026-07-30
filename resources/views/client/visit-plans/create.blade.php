@extends('layouts.app')

@section('title', 'Create Visit Plan | Night Market Selangor')

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-lg-7">
            <div class="card market-card">
                <div class="card-body p-4 p-md-5">
                    <h1 class="h3 fw-bold text-market">Create Visit Plan</h1>
                    <p class="text-secondary mb-4">Plan an upcoming visit to an active night market.</p>

                    @if ($nightMarkets->isEmpty())
                        <div class="alert alert-warning mb-0">
                            No active night markets are currently available for planning.
                        </div>
                    @else
                        <form method="POST" action="{{ route('client.visit-plans.store') }}" novalidate>
                            @csrf

                            <div class="mb-3">
                                <label for="title" class="form-label">Plan Title</label>
                                <input type="text" class="form-control @error('title') is-invalid @enderror"
                                    id="title" name="title" value="{{ old('title') }}" required>
                                @error('title')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

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
                                <label for="visit_date" class="form-label">Visit Date</label>
                                <input type="date" class="form-control @error('visit_date') is-invalid @enderror"
                                    id="visit_date" name="visit_date" value="{{ old('visit_date') }}"
                                    min="{{ now()->toDateString() }}" required>
                                @error('visit_date')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="mb-4">
                                <label for="notes" class="form-label">Notes</label>
                                <textarea class="form-control @error('notes') is-invalid @enderror"
                                    id="notes" name="notes" rows="4">{{ old('notes') }}</textarea>
                                @error('notes')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="d-flex flex-wrap gap-2">
                                <button type="submit" class="btn btn-market">Create Plan</button>
                                <a href="{{ route('client.visit-plans.index') }}"
                                    class="btn btn-outline-secondary">Back to Plans</a>
                            </div>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
