@extends('layouts.app')

@section('title', 'Smart Visit Planner | Night Market Selangor')

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h2 fw-bold text-market mb-1">Smart Visit Planner</h1>
            <p class="text-secondary mb-0">Transparent recommendations using current public catalog data and deterministic rules.</p>
        </div>
        <a href="{{ route('client.visit-plans.index') }}" class="btn btn-outline-secondary align-self-start">Back to My Visit Plans</a>
    </div>

    <section class="card market-card mb-4" aria-labelledby="planner-preferences-heading">
        <div class="card-body p-4 p-lg-5">
            <h2 id="planner-preferences-heading" class="h4 fw-bold text-market">Planning Preferences</h2>
            <p class="text-secondary">Only public active Selangor catalog records are considered. No external AI or live data is used.</p>

            <form method="POST" action="{{ route('client.visit-plans.smart-planner.recommend') }}" novalidate>
                @csrf
                <div class="row g-3">
                    <div class="col-12 col-md-6 col-lg-4">
                        <label for="visit_date" class="form-label">Visit Date</label>
                        <input type="date" id="visit_date" name="visit_date"
                            value="{{ old('visit_date', $preferences['visit_date'] ?? '') }}"
                            min="{{ now()->toDateString() }}"
                            class="form-control @error('visit_date') is-invalid @enderror" required>
                        @error('visit_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12 col-md-6 col-lg-4">
                        <label for="city" class="form-label">City/District <span class="text-secondary">(optional)</span></label>
                        <select id="city" name="city" class="form-select @error('city') is-invalid @enderror">
                            <option value="">Any public Selangor city</option>
                            @foreach ($cities as $city)
                                <option value="{{ $city->city }}" @selected(old('city', $preferences['city'] ?? '') === $city->city)>{{ $city->city }}</option>
                            @endforeach
                        </select>
                        @error('city')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12 col-lg-4">
                        <label for="night_market_id" class="form-label">Target Night Market <span class="text-secondary">(optional)</span></label>
                        <select id="night_market_id" name="night_market_id" class="form-select @error('night_market_id') is-invalid @enderror">
                            <option value="">Any operating public Market</option>
                            @foreach ($markets as $market)
                                <option value="{{ $market->id }}" @selected((string) old('night_market_id', $preferences['night_market_id'] ?? '') === (string) $market->id)>
                                    {{ $market->name }} — {{ $market->city }}
                                </option>
                            @endforeach
                        </select>
                        @error('night_market_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-6 col-lg-3">
                        <label for="budget_min" class="form-label">Minimum Budget (RM)</label>
                        <input id="budget_min" name="budget_min" inputmode="decimal"
                            value="{{ old('budget_min', $preferences['budget_min'] ?? '') }}"
                            class="form-control @error('budget_min') is-invalid @enderror" placeholder="e.g. 10">
                        @error('budget_min')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-6 col-lg-3">
                        <label for="budget_max" class="form-label">Maximum Budget (RM)</label>
                        <input id="budget_max" name="budget_max" inputmode="decimal"
                            value="{{ old('budget_max', $preferences['budget_max'] ?? '') }}"
                            class="form-control @error('budget_max') is-invalid @enderror" placeholder="e.g. 50">
                        @error('budget_max')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12 col-md-6 col-lg-3">
                        <label for="halal_preference" class="form-label">Stall Halal Preference</label>
                        <select id="halal_preference" name="halal_preference" class="form-select @error('halal_preference') is-invalid @enderror">
                            @foreach ($halalOptions as $value => $label)
                                <option value="{{ $value }}" @selected(old('halal_preference', $preferences['halal_preference'] ?? 'any') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('halal_preference')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12 col-md-6 col-lg-3">
                        <label for="max_markets" class="form-label">Maximum Markets</label>
                        <select id="max_markets" name="max_markets" class="form-select @error('max_markets') is-invalid @enderror">
                            @for ($limit = 1; $limit <= 3; $limit++)
                                <option value="{{ $limit }}" @selected((int) old('max_markets', $preferences['max_markets'] ?? 1) === $limit)>{{ $limit }}</option>
                            @endfor
                        </select>
                        @error('max_markets')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <fieldset class="col-12">
                        <legend class="form-label">Preferred Food Categories <span class="text-secondary">(optional)</span></legend>
                        <div class="d-flex flex-wrap gap-3">
                            @foreach ($categories as $category)
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="categories[]"
                                        id="category-{{ $loop->index }}" value="{{ $category->category }}"
                                        @checked(in_array($category->category, old('categories', $preferences['categories'] ?? []), true))>
                                    <label class="form-check-label" for="category-{{ $loop->index }}">{{ $category->category }}</label>
                                </div>
                            @endforeach
                        </div>
                        @error('categories')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                        @error('categories.*')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    </fieldset>

                    <div class="col-12">
                        <div class="form-check">
                            <input type="hidden" name="must_try" value="0">
                            <input class="form-check-input" type="checkbox" id="must_try" name="must_try" value="1"
                                @checked((bool) old('must_try', $preferences['must_try'] ?? false))>
                            <label class="form-check-label" for="must_try">Recommend Must-Try Foods only</label>
                        </div>
                        @error('must_try')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-12">
                        <label for="preference_notes" class="form-label">Planning Notes <span class="text-secondary">(optional)</span></label>
                        <textarea id="preference_notes" name="preference_notes" rows="3" maxlength="1000"
                            class="form-control @error('preference_notes') is-invalid @enderror"
                            placeholder="For your reference; notes do not create unsupported travel or live-data assumptions.">{{ old('preference_notes', $preferences['preference_notes'] ?? '') }}</textarea>
                        @error('preference_notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                <button type="submit" class="btn btn-market mt-4">Generate Recommendations</button>
            </form>
        </div>
    </section>

    @if ($plannerResult !== null)
        <section aria-labelledby="planner-results-heading" class="vstack gap-4">
            <h2 id="planner-results-heading" class="visually-hidden">Smart Planner Results</h2>

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
                        <article class="card market-card">
                            <div class="card-body p-4 p-lg-5">
                                <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-3">
                                    <div>
                                        <span class="badge text-bg-warning mb-2">Score {{ $recommendation['score'] }}</span>
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
                                            {{ $operatingDay->opening_time->format('g:i A') }}–{{ $operatingDay->closing_time->format('g:i A') }}
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

                                <h4 class="h5 fw-bold mt-4">Recommended Stalls</h4>
                                <div class="d-flex flex-wrap gap-2 mb-4">
                                    @foreach ($recommendation['stalls'] as $stallRecommendation)
                                        <span class="border rounded-3 p-2">
                                            {{ $stallRecommendation['stall']->name }}
                                            <x-halal-status :stall="$stallRecommendation['stall']" class="ms-1" />
                                        </span>
                                    @endforeach
                                </div>

                                <h4 class="h5 fw-bold">Recommended Foods</h4>
                                <div class="row g-3">
                                    @foreach ($recommendation['foods'] as $foodRecommendation)
                                        <div class="col-12 col-md-6 col-xl-4">
                                            <div class="border rounded-3 h-100 overflow-hidden bg-white">
                                                <x-food-image :food="$foodRecommendation['food']" />
                                                <div class="p-3">
                                                    <div class="d-flex justify-content-between gap-2">
                                                        <strong>{{ $foodRecommendation['food']->name }}</strong>
                                                        @if ($foodRecommendation['food']->is_must_try)
                                                            <span class="badge text-bg-warning">Must-Try</span>
                                                        @endif
                                                    </div>
                                                    <span class="small text-secondary d-block">{{ $foodRecommendation['stall']->name }}</span>
                                                    @if ($foodRecommendation['food']->category)
                                                        <span class="small text-market d-block">{{ $foodRecommendation['food']->category }}</span>
                                                    @endif
                                                    <span class="fw-semibold d-block my-2">{{ $foodRecommendation['price_label'] }}</span>
                                                    <p class="small mb-0">{{ $foodRecommendation['explanation'] }}</p>
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>

                                <form method="POST" action="{{ route('client.visit-plans.smart-planner.store') }}" class="mt-4">
                                    @csrf
                                    <input type="hidden" name="title" value="Smart visit to {{ $recommendation['market']->name }}">
                                    <input type="hidden" name="requested_date" value="{{ $plannerResult['requested_date'] }}">
                                    <input type="hidden" name="visit_date" value="{{ $plannerResult['recommendation_date'] }}">
                                    <input type="hidden" name="night_market_id" value="{{ $recommendation['market']->id }}">
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
                                    @if ($plannerResult['uses_fallback'])
                                        <div class="form-check mb-3">
                                            <input class="form-check-input" type="checkbox" name="confirmed_fallback_date" value="1"
                                                id="confirmed-fallback-date-{{ $recommendation['market']->id }}" required>
                                            <label class="form-check-label fw-semibold" for="confirmed-fallback-date-{{ $recommendation['market']->id }}">
                                                Use recommended date: {{ $plannerResult['recommendation_date_label'] }}
                                            </label>
                                        </div>
                                    @endif
                                    <p class="small text-secondary">Confirmed plan date: {{ $plannerResult['recommendation_date_label'] }}</p>
                                    <button type="submit" class="btn btn-market">
                                        {{ $plannerResult['uses_fallback'] ? 'Use Recommended Date and Create Plan' : 'Create Plan from This Recommendation' }}
                                    </button>
                                </form>
                            </div>
                        </article>
                    @endforeach
                    </div>
                </section>
            @endif
        </section>
    @endif
@endsection
