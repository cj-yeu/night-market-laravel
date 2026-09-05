@php
    $editing = isset($visitPlan);
    $planIsPast = $editing && $visitPlan->visit_status === 'Past';
    $marketCanChange = ! $editing || (! $planIsPast && $visitPlan->items->isEmpty());
    $selectedMarket = old('night_market_id', $editing ? $visitPlan->night_market_id : $selectedNightMarketId);
    $selectedCity = old('city', $nightMarkets->firstWhere('id', (int) $selectedMarket)?->city);
@endphp
<form method="POST" action="{{ $editing ? route('client.visit-plans.update', $visitPlan) : route('client.visit-plans.store') }}" novalidate>
    @csrf
    @if ($editing) @method('PATCH') @endif
    <div class="planner-form-layout">
        <section class="card market-card"><div class="card-body p-4">
            <h2 class="h5 text-market">When and where</h2>
            @if ($planIsPast)<div class="alert alert-secondary">Past plans keep their Night Market, visit date, and items. You can still update the title and notes.</div>
            @elseif (! $marketCanChange)<div class="alert alert-info">Remove all plan items before changing the Night Market.</div>@endif
            @if ($editing && ! $visitPlan->market_is_available)<div class="alert alert-warning">This plan’s current Night Market is no longer publicly available. It remains selected; choose an eligible Night Market before changing it.</div>@endif
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label for="visit_date" class="form-label">Visit Date</label>
                    @if ($planIsPast)<input type="hidden" name="visit_date" value="{{ $visitPlan->visit_date->toDateString() }}">@endif
                    <input type="date" id="visit_date" name="visit_date" data-schedule-date class="form-control @error('visit_date') is-invalid @enderror"
                        value="{{ old('visit_date', $editing ? $visitPlan->visit_date->toDateString() : '') }}"
                        @if (! $planIsPast) min="{{ now()->toDateString() }}" @endif @disabled($planIsPast) required>
                    @error('visit_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-12 col-md-6">
                    <label for="plan-city" class="form-label">City <span class="text-secondary">(optional)</span></label>
                    <select id="plan-city" name="city" class="form-select @error('city') is-invalid @enderror" @disabled(! $marketCanChange)>
                        <option value="">All cities</option>
                        @foreach ($nightMarkets->pluck('city')->filter()->unique()->sort() as $city)
                            <option value="{{ $city }}" @selected($selectedCity === $city)>{{ $city }}</option>
                        @endforeach
                    </select>
                    @error('city')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-12">
                    <label for="night_market_id" class="form-label">Night Market</label>
                    @if (! $marketCanChange)<input type="hidden" name="night_market_id" value="{{ $visitPlan->night_market_id }}">@endif
                    <select id="night_market_id" name="night_market_id" data-parent-select="plan-city" data-searchable data-schedule-select
                        class="form-select @error('night_market_id') is-invalid @enderror" @disabled(! $marketCanChange) required>
                        <option value="">Select a night market</option>
                        @foreach ($nightMarkets as $market)
                            <option value="{{ $market->id }}" data-parent="{{ $market->city }}"
                                data-days="{{ $market->operatingDays->pluck('day_of_week')->implode('|') }}"
                                data-schedule="{{ $market->operatingDays->map(fn ($day) => $day->day_of_week.' '.($day->opening_time?->format('g:i A') ?? 'Time not available').'–'.($day->closing_time?->format('g:i A') ?? 'Time not available'))->implode('; ') }}"
                                @selected((string) $selectedMarket === (string) $market->id)>{{ $market->name }} — {{ $market->city }}</option>
                        @endforeach
                    </select>
                    @error('night_market_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-12 mt-4"><h2 class="h5 text-market">Your plan details</h2></div>
                <div class="col-12">
                    <label for="title" class="form-label">Plan Title</label>
                    <input id="title" name="title" maxlength="255" required class="form-control @error('title') is-invalid @enderror" value="{{ old('title', $editing ? $visitPlan->title : '') }}">
                    @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-12">
                    <label for="notes" class="form-label">Notes <span class="text-secondary">(optional)</span></label>
                    <textarea id="notes" name="notes" rows="3" maxlength="5000" class="form-control @error('notes') is-invalid @enderror">{{ old('notes', $editing ? $visitPlan->notes : '') }}</textarea>
                    @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
        </div></section>
        <aside class="planner-summary" aria-labelledby="plan-summary-heading">
            <h2 id="plan-summary-heading" class="h5 text-market">Your visit at a glance</h2>
            <p data-planner-summary></p>
            <h3 class="h6">Operating Schedule</h3>
            <p data-schedule-hint role="status" class="small text-secondary"></p>
            <p class="small">{{ $editing ? $visitPlan->items_count.' planned items. Saved changes refresh the same Calendar event if already synced.' : 'Save your visit first, then choose Stalls and Foods on the itinerary page. Google Calendar is optional.' }}</p>
            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-market">{{ $editing ? 'Update Plan' : 'Create Plan' }}</button>
                <a class="btn btn-outline-secondary" href="{{ $editing ? route('client.visit-plans.show', $visitPlan) : route('client.visit-plans.index') }}">{{ $editing ? 'Cancel' : 'Back to Plans' }}</a>
            </div>
        </aside>
    </div>
</form>
