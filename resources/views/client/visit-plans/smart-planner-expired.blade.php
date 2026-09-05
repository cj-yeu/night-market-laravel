@extends('layouts.app')
@section('title', 'Recommendation unavailable | '.config('app.name'))
@section('content')
    <section class="card market-card"><div class="card-body p-4">
        <h1 class="h3 text-market">Your recommendation is no longer available</h1>
        <p role="status">{{ $message }}</p>
        <p>No new AI request was made and no plan was saved.</p>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-market" href="{{ route('client.visit-plans.smart-planner.index') }}">Edit preferences</a>
            <a class="btn btn-outline-secondary" href="{{ route('client.visit-plans.index') }}">My Visit Plans</a>
        </div>
    </div></section>
@endsection
