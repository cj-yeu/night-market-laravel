@extends('layouts.app')
@section('title', 'Smart Visit Planner | '.config('app.name'))
@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/smart-planner.css') }}">
@endpush
@push('scripts')
    <script src="{{ asset('assets/smart-planner.js') }}" defer></script>
@endpush
@section('content')
    @php($activeTemplate = old('template', $preferences['template'] ?? null))
    <div class="night-out-header mb-4">
        <div>
            <p class="text-market small fw-bold mb-2">SMART VISIT PLANNER</p>
            <h1>Your next night out, planned.</h1>
            <p class="text-secondary fs-5">Discover a night market plan that fits your taste and budget.</p>
        </div>
        <a href="{{ route('client.visit-plans.index') }}" class="btn btn-outline-secondary">Back to My Visit Plans</a>
    </div>
    @if ($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif
    @if (session('planner_notice'))
        <div class="alert alert-info" role="status"><h2 class="h5">No matching recommendation</h2>{{ session('planner_notice') }}</div>
    @endif
    @if ($currentResultId)
        <div class="night-out-panel mb-4" id="existing-recommendation">
            <p class="mb-2">Your existing recommendation is available. Editing a preference makes it unavailable for saving; generate again to use your new choices.</p>
            <a class="btn btn-outline-market" data-existing-result href="{{ route('client.visit-plans.smart-planner.result', ['snapshot' => $currentResultId]) }}">View existing recommendation</a>
            <p id="planner-stale" class="mb-0 mt-2" role="status" hidden>Your preferences changed. Generate again before saving a plan.</p>
        </div>
    @endif
    @include('client.visit-plans.partials.smart-preferences')
@endsection
