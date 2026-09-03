@extends('layouts.app')

@section('title', 'Add Stall | '.config('app.name'))

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-lg-7">
            <div class="card market-card">
                <div class="card-body p-4 p-md-5">
                    <h1 class="h3 fw-bold text-market">Add Stall</h1>
                    <p class="text-secondary mb-4">Add a stall to an active Night Market in Selangor.</p>

                    @if ($nightMarkets->isEmpty())
                        <div class="alert alert-warning mb-0">
                            No eligible Night Markets are available. <a href="{{ route('admin.night-markets.index') }}" class="alert-link">Manage Night Markets</a> to add or activate one in Selangor first.
                        </div>
                    @else
                        <form method="POST" action="{{ route('admin.stalls.store') }}" novalidate>
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
                                <label for="name" class="form-label">Stall Name</label>
                                <input type="text" class="form-control @error('name') is-invalid @enderror"
                                    id="name" name="name" value="{{ old('name') }}" required>
                                @error('name')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="mb-3">
                                <label for="description" class="form-label">Description</label>
                                <textarea class="form-control @error('description') is-invalid @enderror"
                                    id="description" name="description" rows="4">{{ old('description') }}</textarea>
                                @error('description')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            @include('admin.stalls._metadata-fields')

                            <div class="mb-4">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select @error('status') is-invalid @enderror"
                                    id="status" name="status" required>
                                    <option value="active" @selected(old('status', 'active') === 'active')>Active</option>
                                    <option value="inactive" @selected(old('status') === 'inactive')>Inactive</option>
                                </select>
                                @error('status')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <button type="submit" class="btn btn-market">Add Stall</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
