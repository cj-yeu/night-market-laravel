@extends('layouts.app')

@section('title', $food->name.' | Admin')

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-4">
        <div><h1 class="h2 fw-bold mb-1">{{ $food->name }}</h1><p class="text-secondary mb-0">Food catalog details</p></div>
        <div class="d-flex gap-2 align-self-start">
            <a href="{{ route('admin.foods.index') }}" class="btn btn-outline-secondary">Back</a>
            <a href="{{ route('admin.foods.edit', $food) }}" class="btn btn-market">Edit</a>
            @if ($food->status === 'inactive')
                <form method="POST" action="{{ route('admin.foods.destroy', $food) }}" onsubmit="return confirm('Permanently delete {{ addslashes($food->name) }}? This cannot be undone.');">@csrf @method('DELETE')<button type="submit" class="btn btn-outline-danger">Delete</button></form>
            @endif
        </div>
    </div>

    @include('admin.foods._image-management')

    <div class="card market-card"><div class="card-body p-4">
        <dl class="row mb-0">
            <dt class="col-sm-3">Stall</dt><dd class="col-sm-9"><a href="{{ route('admin.stalls.show', $food->stall) }}">{{ $food->stall->name }}</a></dd>
            <dt class="col-sm-3">Night Market</dt><dd class="col-sm-9"><a href="{{ route('admin.night-markets.show', $food->stall->nightMarket) }}">{{ $food->stall->nightMarket->name }}</a></dd>
            <dt class="col-sm-3">Category</dt><dd class="col-sm-9">{{ $food->category ?: 'Not specified' }}</dd>
            <dt class="col-sm-3">Price</dt><dd class="col-sm-9"><x-food-price :food="$food" /></dd>
            <dt class="col-sm-3">Price checked</dt><dd class="col-sm-9">{{ $food->price_checked_at?->format('M j, Y') ?? 'Not recorded' }}</dd>
            <dt class="col-sm-3">Must-Try</dt><dd class="col-sm-9">{{ $food->is_must_try ? 'Yes' : 'No' }}</dd>
            <dt class="col-sm-3">Recommendation</dt><dd class="col-sm-9">{{ $food->recommendation_reason ?: 'Not provided' }}</dd>
            <dt class="col-sm-3">Source</dt><dd class="col-sm-9">
                @if ($food->sourceUrl())<a href="{{ $food->sourceUrl() }}" target="_blank" rel="noopener noreferrer">Open source</a>@else Not provided @endif
            </dd>
            <dt class="col-sm-3">Verified</dt><dd class="col-sm-9">{{ $food->verified_at?->format('M j, Y') ?? 'Not verified' }}</dd>
            <dt class="col-sm-3">Status</dt><dd class="col-sm-9"><span class="badge {{ $food->status === 'active' ? 'text-bg-success' : 'text-bg-secondary' }}">{{ ucfirst($food->status) }}</span></dd>
            <dt class="col-sm-3">Description</dt><dd class="col-sm-9">{{ $food->description ?: 'No description available.' }}</dd>
        </dl>
    </div></div>
@endsection
