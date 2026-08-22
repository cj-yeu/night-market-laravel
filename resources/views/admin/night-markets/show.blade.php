@extends('layouts.app')

@section('title', $nightMarket->name.' | Admin')

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h2 fw-bold mb-1">{{ $nightMarket->name }}</h1>
            <p class="text-secondary mb-0">Night market catalog details</p>
        </div>
        <div class="d-flex gap-2 align-self-start">
            <a href="{{ route('admin.night-markets.index') }}" class="btn btn-outline-secondary">Back</a>
            <a href="{{ route('admin.night-markets.edit', $nightMarket) }}" class="btn btn-market">Edit</a>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-12 col-lg-7">
            <div class="card market-card overflow-hidden h-100">
                <x-night-market-image :night-market="$nightMarket" loading="eager" />
                <div class="card-body p-4">
                    <h2 class="h5 fw-bold">Cover image</h2>
                    <form method="POST" action="{{ route('admin.night-markets.image.update', $nightMarket) }}"
                        enctype="multipart/form-data" class="row g-3 align-items-end" novalidate>
                        @csrf
                        @method('PATCH')
                        <div class="col-12 col-md">
                            <label for="image" class="form-label">JPEG, PNG, or WebP (maximum 2 MB)</label>
                            <input id="image" name="image" type="file" accept="image/jpeg,image/png,image/webp"
                                class="form-control @error('image') is-invalid @enderror" required>
                            @error('image') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-12 col-md-auto">
                            <button type="submit" class="btn btn-market">{{ $nightMarket->imageUrl() ? 'Replace image' : 'Upload image' }}</button>
                        </div>
                    </form>

                    @if ($nightMarket->imageUrl())
                        <form method="POST" action="{{ route('admin.night-markets.image.destroy', $nightMarket) }}"
                            class="mt-3" onsubmit="return confirm('Remove this Night Market cover image?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-outline-danger">Remove image</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-5">
            <div class="card market-card h-100">
                <div class="card-body p-4">
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Status</dt>
                        <dd class="col-sm-8"><span class="badge {{ $nightMarket->status === 'active' ? 'text-bg-success' : 'text-bg-secondary' }}">{{ ucfirst($nightMarket->status) }}</span></dd>
                        <dt class="col-sm-4">Address</dt><dd class="col-sm-8 text-break">{{ $nightMarket->address }}</dd>
                        <dt class="col-sm-4">City / state</dt><dd class="col-sm-8">{{ $nightMarket->city }}, {{ $nightMarket->state }}</dd>
                        <dt class="col-sm-4">Description</dt><dd class="col-sm-8">{{ $nightMarket->description ?: 'No description available.' }}</dd>
                        <dt class="col-sm-4">Stalls</dt><dd class="col-sm-8">{{ $nightMarket->stalls_count }}</dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>

    <div class="card market-card">
        <div class="card-body p-4">
            <h2 class="h5 fw-bold mb-3">Operating schedule</h2>
            <x-night-market-schedule :operating-days="$nightMarket->operatingDays" />
        </div>
    </div>
@endsection
