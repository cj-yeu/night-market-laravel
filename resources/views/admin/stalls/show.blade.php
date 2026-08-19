@extends('layouts.app')

@section('title', $stall->name.' | Admin')

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-4">
        <div><h1 class="h2 fw-bold mb-1">{{ $stall->name }}</h1><p class="text-secondary mb-0">Stall details</p></div>
        <div class="d-flex gap-2 align-self-start">
            <a href="{{ route('admin.stalls.index') }}" class="btn btn-outline-secondary">Back</a>
            <a href="{{ route('admin.stalls.edit', $stall) }}" class="btn btn-market">Edit</a>
        </div>
    </div>

    <div class="card market-card mb-4"><div class="card-body p-4">
        <dl class="row mb-0">
            <dt class="col-sm-3">Night Market</dt><dd class="col-sm-9"><a href="{{ route('admin.night-markets.show', $stall->nightMarket) }}">{{ $stall->nightMarket->name }}</a></dd>
            <dt class="col-sm-3">Category</dt><dd class="col-sm-9">{{ $stall->category ?: 'Not specified' }}</dd>
            <dt class="col-sm-3">Halal classification</dt><dd class="col-sm-9"><span class="badge {{ $stall->halalBadgeClass() }}">{{ $stall->halalStatusLabel() }}</span></dd>
            <dt class="col-sm-3">Halal evidence</dt><dd class="col-sm-9">
                @if ($stall->halalEvidenceUrl())
                    <a href="{{ $stall->halalEvidenceUrl() }}" target="_blank" rel="noopener noreferrer">Open evidence</a>
                @else
                    Not provided
                @endif
            </dd>
            <dt class="col-sm-3">Halal evidence notes</dt><dd class="col-sm-9">{{ $stall->halal_notes ?: 'Not provided' }}</dd>
            <dt class="col-sm-3">General source</dt><dd class="col-sm-9">
                @if ($stall->sourceUrl())<a href="{{ $stall->sourceUrl() }}" target="_blank" rel="noopener noreferrer">Open source</a>@else Not provided @endif
            </dd>
            <dt class="col-sm-3">Verified</dt><dd class="col-sm-9">{{ $stall->verified_at?->format('M j, Y') ?? 'Not verified' }}</dd>
            <dt class="col-sm-3">Status</dt><dd class="col-sm-9"><span class="badge {{ $stall->status === 'active' ? 'text-bg-success' : 'text-bg-secondary' }}">{{ ucfirst($stall->status) }}</span></dd>
            <dt class="col-sm-3">Description</dt><dd class="col-sm-9">{{ $stall->description ?: 'No description available.' }}</dd>
            <dt class="col-sm-3">Foods</dt><dd class="col-sm-9">{{ $stall->foods_count }}</dd>
        </dl>
    </div></div>

    <h2 class="h4 fw-bold">Foods at this stall</h2>
    @if ($stall->foods->isEmpty())
        <div class="alert alert-info">No foods are assigned to this stall.</div>
    @else
        <div class="list-group">
            @foreach ($stall->foods as $food)
                <a href="{{ route('admin.foods.show', $food) }}" class="list-group-item list-group-item-action d-flex justify-content-between gap-3">
                    <span>{{ $food->name }} @if ($food->category)<small class="text-secondary">— {{ $food->category }}</small>@endif</span>
                    <span class="badge {{ $food->status === 'active' ? 'text-bg-success' : 'text-bg-secondary' }}">{{ ucfirst($food->status) }}</span>
                </a>
            @endforeach
        </div>
    @endif
@endsection
