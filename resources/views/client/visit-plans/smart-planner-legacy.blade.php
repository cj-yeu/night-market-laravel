{{-- Compatibility rendering for existing deterministic callers without recommendation_mode. --}}
@extends('layouts.app')

@section('title', 'Smart Visit Planner | '.config('app.name'))
@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/smart-planner.css') }}">
@endpush
@push('scripts')
    <script src="{{ asset('assets/smart-planner.js') }}" defer></script>
@endpush

@section('content')
    @php($activeTemplate = $preferences['template'] ?? null)
    <div class="night-out-header mb-4">
        <div>
            <p class="text-market small fw-bold mb-2">SMART VISIT PLANNER</p>
            <h1>Your next night out, planned.</h1>
            <p class="text-secondary fs-5">Discover a night market plan that fits your taste and budget.</p>
        </div>
        <a href="{{ route('client.visit-plans.index') }}" class="btn btn-outline-secondary">Back to My Visit Plans</a>
    </div>
    @if ($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif
    @include('client.visit-plans.partials.smart-preferences')

    @if ($plannerResult !== null)
        <section aria-labelledby="planner-results-heading" class="vstack gap-4" id="planner-results" data-invalidate-url="{{ route('client.visit-plans.smart-planner.invalidate') }}">
            <h2 id="planner-results-heading" class="visually-hidden">Smart Planner Results</h2>
            @if (isset($plannerResult['source_notice']))<p class="alert alert-info" role="status">{{ $plannerResult['source_notice'] }}</p>@endif
            <p id="planner-stale" class="alert alert-warning" role="status" hidden>Your preferences changed. Generate again before saving.</p>

            @if ($plannerResult['template'] ?? null)
                <div class="alert alert-info mb-0" role="status">
                    <strong>{{ $plannerResult['template']['name'] }}</strong>: {{ $plannerResult['template']['description'] }}
                    @if ($plannerResult['template']['notice'])<div class="mt-2">{{ $plannerResult['template']['notice'] }}</div>@endif
                    <div class="small mt-2">{{ $plannerResult['template']['limit'] }}</div>
                </div>
            @endif

            <section class="card market-card" aria-labelledby="requested-date-heading">
                <div class="card-body p-4">
                    <h3 id="requested-date-heading" class="h4 fw-bold text-market">Your Requested Date</h3>
                    <p class="fs-5 fw-semibold mb-3">{{ $plannerResult['requested_date_label'] }}</p>
                    <div class="d-flex flex-wrap gap-2" aria-label="Selected preferences">
                        <span class="badge text-bg-light border">City: {{ $preferences['city'] ?? 'Any public Selangor city' }}</span>
                        <span class="badge text-bg-light border">
                            Market:
                            {{ isset($preferences['night_market_id']) ? ($markets->firstWhere('id', (int) $preferences['night_market_id'])?->name ?? 'Selected public Market') : 'Any operating public Market' }}
                        </span>
                        <span class="badge text-bg-light border">Categories: {{ ($preferences['categories'] ?? []) === [] ? 'Any' : implode(', ', $preferences['categories']) }}</span>
                        <span class="badge text-bg-light border">Halal: {{ $halalOptions[$preferences['halal_preference']] }}</span>
                        <span class="badge text-bg-light border">Must-Try only: {{ $preferences['must_try'] ? 'Yes' : 'No' }}</span>
                        <span class="badge text-bg-light border">
                            Budget:
                            {{ isset($preferences['budget_min'], $preferences['budget_max']) ? 'RM'.number_format((float) $preferences['budget_min'], 2).'–RM'.number_format((float) $preferences['budget_max'], 2) : 'Not set' }}
                        </span>
                    </div>
                    @if ($preferences['preference_notes'] ?? null)
                        <p class="text-secondary mt-3 mb-0">Your planning note: {{ $preferences['preference_notes'] }}</p>
                    @endif
                </div>
            </section>

            @if ($plannerResult['uses_fallback'])
                <section class="card border-warning" aria-labelledby="recommended-date-heading">
                    <div class="card-body p-4">
                        <h3 id="recommended-date-heading" class="h4 fw-bold text-market">Recommended Visit Date</h3>
                        <p>{{ $plannerResult['requested_reason_message'] }}</p>
                        <p class="fs-5 mb-2">
                            The nearest suitable option is
                            <strong>{{ $recommendations[0]['market']->name }}</strong>
                            on <strong>{{ $plannerResult['recommendation_date_label'] }}</strong>.
                        </p>
                        <p class="small text-secondary mb-0">Use recommended date in an itinerary below to explicitly confirm this fallback date before creating your plan.</p>
                    </div>
                </section>
            @endif

            @if ($recommendations === [])
                <div class="alert alert-info py-4">
                    <h3 class="h5">No matching option</h3>
                    <p>{{ $plannerResult['requested_reason_message'] }}</p>
                    @if ($plannerResult['fallback_exhausted'])
                        <p class="mb-0">No matching option exists within the next {{ $plannerResult['fallback_limit_days'] }} days.</p>
                    @endif
                </div>
            @else
                <section aria-labelledby="recommended-itinerary-heading">
                    <h3 id="recommended-itinerary-heading" class="h3 fw-bold text-market">Recommended Itinerary</h3>
                    <p class="text-secondary">
                        Recommendation date: {{ $plannerResult['recommendation_date_label'] }}.
                        A plan is created only for the date confirmed below.
                    </p>
                    <div class="vstack gap-4">
                    @foreach ($recommendations as $recommendation)
                        @if (isset($recommendation['snapshot_id']))
                            @include('client.visit-plans.partials.smart-result')
                        @else
                        <article class="card market-card">
                            <div class="card-body p-4 p-lg-5">
                                <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-3">
                                    <div>
                                        <span class="badge text-bg-light mb-2">Basic recommendation</span>
                                        <h3 class="h3 fw-bold text-market mb-1">{{ $recommendation['market']->name }}</h3>
                                        <p class="text-secondary mb-0">{{ $recommendation['market']->city }}, {{ $recommendation['market']->state }}</p>
                                    </div>
                                    <div class="text-lg-end">
                                        <strong class="d-block">Estimated known-price total</strong>
                                        <span>{{ $recommendation['estimated_price_label'] }}</span>
                                    </div>
                                </div>

                                <p>{{ $recommendation['explanation'] }}</p>
                                <div class="small text-secondary mb-3">
                                    @foreach ($recommendation['market']->operatingDays as $operatingDay)
                                        <span class="d-block">
                                            {{ $operatingDay->day_of_week }}:
                                            {{ $operatingDay->opening_time?->format('g:i A') ?? 'Time not available' }}–{{ $operatingDay->closing_time?->format('g:i A') ?? 'Time not available' }}
                                        </span>
                                    @endforeach
                                </div>

                                @if ($recommendation['unknown_price_count'] > 0)
                                    <div class="alert alert-warning py-2">
                                        {{ $recommendation['unknown_price_count'] }} recommended
                                        {{ $recommendation['unknown_price_count'] === 1 ? 'Food has' : 'Foods have' }} no complete numeric price range and
                                        {{ $recommendation['unknown_price_count'] === 1 ? 'is' : 'are' }} excluded from the estimated total.
                                    </div>
                                @endif

                                @if ($recommendation['stalls'] !== [])
                                    <h4 class="h5 fw-bold mt-4">Recommended Stalls</h4>
                                    <div class="d-flex flex-wrap gap-2 mb-4">
                                        @foreach ($recommendation['stalls'] as $stallRecommendation)
                                            <span class="border rounded-3 p-2">
                                                {{ $stallRecommendation['stall']->name }}
                                                <x-halal-status :stall="$stallRecommendation['stall']" class="ms-1" />
                                            </span>
                                        @endforeach
                                    </div>
                                @endif

                                <h4 class="h5 fw-bold">{{ ($plannerResult['template']['key'] ?? null) === 'quick_visit' ? 'Recommended Stops' : 'Recommended Foods' }}</h4>
                                <div class="row g-3">
                                    @foreach ($recommendation['foods'] as $foodRecommendation)
                                        <div class="col-12 col-md-6 col-xl-4">
                                            <div class="border rounded-3 h-100 overflow-hidden bg-white">
                                                <x-food-image :food="$foodRecommendation['food']" />
                                                <div class="p-3">
                                                    <div class="d-flex justify-content-between align-items-start gap-2">
                                                        <strong>{{ $foodRecommendation['food']->name }}</strong>
                                                        @if ($foodRecommendation['food']->is_must_try)
                                                            <span class="badge text-bg-warning">Must-Try</span>
                                                        @endif
                                                    </div>
                                                    <span class="small text-secondary d-block">{{ $foodRecommendation['stall']->name }}</span>
                                                    @if ($foodRecommendation['food']->category)
                                                        <span class="small text-market d-block">{{ \App\Support\CatalogCategory::canonical($foodRecommendation['food']->category, 'food') }}</span>
                                                    @endif
                                                    <span class="fw-semibold d-block my-2">{{ $foodRecommendation['price_label'] }}</span>
                                                    <p class="small mb-0">{{ $foodRecommendation['explanation'] }}</p>
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>

                                <form method="POST" action="{{ route('client.visit-plans.smart-planner.store') }}" class="planner-summary mt-4">
                                    @csrf
                                    <input type="hidden" name="title" value="Smart visit to {{ $recommendation['market']->name }}">
                                    <input type="hidden" name="requested_date" value="{{ $plannerResult['requested_date'] }}">
                                    <input type="hidden" name="visit_date" value="{{ $plannerResult['recommendation_date'] }}">
                                    <input type="hidden" name="night_market_id" value="{{ $recommendation['market']->id }}">
                                    @if ($plannerResult['template'] ?? null)<input type="hidden" name="template" value="{{ $plannerResult['template']['key'] }}">@endif
                                    <input type="hidden" name="halal_preference" value="{{ $preferences['halal_preference'] }}">
                                    <input type="hidden" name="must_try" value="{{ $preferences['must_try'] ? 1 : 0 }}">
                                    <input type="hidden" name="max_markets" value="{{ $preferences['max_markets'] }}">
                                    @if ($preferences['city'] ?? null)<input type="hidden" name="city" value="{{ $preferences['city'] }}">@endif
                                    @if (isset($preferences['budget_min'], $preferences['budget_max']))
                                        <input type="hidden" name="budget_min" value="{{ $preferences['budget_min'] }}">
                                        <input type="hidden" name="budget_max" value="{{ $preferences['budget_max'] }}">
                                    @endif
                                    @if ($preferences['preference_notes'] ?? null)<input type="hidden" name="preference_notes" value="{{ $preferences['preference_notes'] }}">@endif
                                    @foreach ($preferences['categories'] ?? [] as $category)
                                        <input type="hidden" name="categories[]" value="{{ $category }}">
                                    @endforeach
                                    @foreach ($recommendation['stalls'] as $stallRecommendation)
                                        <input type="hidden" name="stall_ids[]" value="{{ $stallRecommendation['stall']->id }}">
                                    @endforeach
                                    @foreach ($recommendation['foods'] as $foodRecommendation)
                                        <input type="hidden" name="food_ids[]" value="{{ $foodRecommendation['food']->id }}">
                                    @endforeach
                                    <p class="mb-2"><strong>{{ count($recommendation['foods']) }} Food stops</strong> · {{ $recommendation['market']->name }}</p>
                                    @if ($plannerResult['uses_fallback'])
                                        <p class="small">Requested: {{ $plannerResult['requested_date_label'] }}<br>Alternative: {{ $plannerResult['recommendation_date_label'] }}</p>
                                        <div class="form-check mb-3">
                                            <input class="form-check-input" type="checkbox" name="confirmed_fallback_date" value="1"
                                                id="confirmed-fallback-date-{{ $recommendation['market']->id }}" required>
                                            <label class="form-check-label fw-semibold" for="confirmed-fallback-date-{{ $recommendation['market']->id }}">
                                                Use recommended date: {{ $plannerResult['recommendation_date_label'] }}
                                            </label>
                                        </div>
                                    @endif
                                    <p class="small text-secondary">{{ $plannerResult['uses_fallback'] ? 'Plan date if confirmed:' : 'Plan date:' }} {{ $plannerResult['recommendation_date_label'] }}</p>
                                    <button type="submit" class="btn btn-market">
                                        {{ $plannerResult['uses_fallback'] ? 'Use Recommended Date and Create Plan' : 'Create Plan from This Recommendation' }}
                                    </button>
                                </form>
                            </div>
                        </article>
                        @endif
                    @endforeach
                    </div>
                </section>
            @endif
        </section>
    @endif
@endsection
