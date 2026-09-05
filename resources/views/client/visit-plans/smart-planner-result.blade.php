@extends('layouts.app')
@section('title', 'Your recommended night out | '.config('app.name'))
@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/smart-planner.css') }}">
@endpush
@push('scripts')
    <script src="{{ asset('assets/smart-planner.js') }}" defer></script>
@endpush
@section('content')
    <div class="night-out-header mb-4">
        <div><p class="text-market small fw-bold mb-2">YOUR NIGHT OUT</p><h1>Your recommended night out</h1></div>
        <a class="btn btn-outline-secondary" href="{{ route('client.visit-plans.smart-planner.index', ['recommendation' => $snapshotId]) }}">Edit preferences</a>
    </div>
    @if ($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif
    <section id="planner-results" class="vstack gap-4" aria-label="Your recommendations" data-result-page>
        <p class="small text-secondary mb-0" role="status">{{ $plannerResult['source_notice'] }}</p>
        @if ($plannerResult['uses_fallback'])
            <section class="night-out-panel alternative-date" aria-labelledby="alternative-date-heading">
                <h2 id="alternative-date-heading" class="h4">A different date fits your preferences</h2>
                <dl class="date-comparison">
                    <div><dt>Requested date</dt><dd>{{ $plannerResult['requested_date_label'] }}</dd></div>
                    <div><dt>Suggested date</dt><dd>{{ $plannerResult['recommendation_date_label'] }}</dd></div>
                </dl>
                <p>{{ $plannerResult['requested_reason_message'] }}</p>
                <p class="mb-0">Your requested date has not been changed. Confirm the suggested date below before saving, or edit your preferences.</p>
            </section>
        @endif
        @foreach ($recommendations as $recommendation)
            @include('client.visit-plans.partials.smart-result')
        @endforeach
        <details class="night-out-panel preference-details">
            <summary>Your preference summary</summary>
            <dl class="mt-2">
                <dt>Requested date</dt><dd>{{ $plannerResult['requested_date_label'] }}</dd>
                <dt>City</dt><dd>{{ $preferences['city'] ?? 'Any public Selangor city' }}</dd>
                <dt>Food interests</dt>
                <dd>{{ collect($preferences['interests'] ?? [])->map(fn ($key) => \App\Support\PlannerFoodInterests::GROUPS[$key]['label'])->implode(' · ') ?: 'Any food interest' }}</dd>
                @if ($preferences['categories'] ?? [])
                    <dt>Individual food categories</dt><dd>{{ implode(' · ', $preferences['categories']) }}</dd>
                @endif
                <dt>Food budget</dt><dd>{{ isset($preferences['budget_max']) ? 'Up to RM'.number_format((float) $preferences['budget_max'], 2) : 'No upper budget set' }}. One serving per food; transport and meals for multiple people are excluded.</dd>
                @if (!empty($preferences['budget_min']))<dt>Optional budget preference</dt><dd>RM{{ number_format((float) $preferences['budget_min'], 2) }} — not a minimum spend.</dd>@endif
                <dt>Halal preference</dt><dd>{{ ($preferences['halal_preference'] ?? 'any') === 'any' ? 'Any classification' : (\App\Models\Stall::halalStatusOptions()[$preferences['halal_preference']] ?? 'Unknown') }}. Muslim-owned/claimed is not certification.</dd>
                <dt>Must-Try only</dt><dd>{{ $preferences['must_try'] ? 'Yes' : 'No' }}</dd>
                @if ($plannerResult['template'] ?? null)<dt>Template</dt><dd>{{ $plannerResult['template']['name'] }}</dd>@endif
                @if ($preferences['preference_notes'] ?? null)<dt>Your private planning note</dt><dd>{{ $preferences['preference_notes'] }}</dd>@endif
            </dl>
        </details>
    </section>
@endsection
