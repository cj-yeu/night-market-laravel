<article class="night-out-panel" data-plan-result data-snapshot="{{ $recommendation['snapshot_id'] }}" data-budget="{{ $preferences['budget_max'] ?? '' }}">
    <header class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-3">
        <div><span class="badge text-bg-warning mb-2">{{ $plannerResult['source'] === 'ai' ? 'AI-selected' : 'Basic recommendation' }}</span>
            <h3 class="h3 text-market">{{ $recommendation['market']->name }}</h3><p>{{ $recommendation['market']->city }}, Selangor</p>
        </div>
        <div><strong>Estimated food cost</strong><p data-total>{{ $recommendation['estimated_price_label'] }}</p></div>
    </header>
    <h4 class="h5">Why this fits</h4><p>{{ $recommendation['explanation'] }}</p>
    @if ($recommendation['template_notice'])<p class="alert alert-info small">{{ $recommendation['template_notice'] }}</p>@endif
    <p class="small text-secondary">{{ $plannerResult['recommendation_date_label'] }} · Regular schedule:
        @foreach ($recommendation['market']->operatingDays as $day)
            @if ($day->day_of_week === \Carbon\Carbon::parse($plannerResult['recommendation_date'])->englishDayOfWeek)
                {{ $day->day_of_week }} {{ $day->opening_time?->format('g:i A') ?? 'Time not available' }}–{{ $day->closing_time?->format('g:i A') ?? 'Time not available' }}
            @endif
        @endforeach
    </p>
    <form method="POST" action="{{ route('client.visit-plans.smart-planner.save') }}" data-snapshot-save>
        @csrf
        <input type="hidden" name="snapshot_id" value="{{ $recommendation['snapshot_id'] }}">
        <input type="hidden" name="night_market_id" value="{{ $recommendation['market']->id }}">
        <div class="night-out-foods">
            @foreach ($recommendation['foods'] as $item)
                <div class="night-out-food" data-food-row>
                    <div data-food-image><x-food-image :food="$item['food']" /></div>
                    <div class="p-3">
                        <h4 class="h5" data-food-name>{{ $item['food']->name }}</h4>
                        <p class="small mb-1" data-food-stall>{{ $item['stall']->name }}</p>
                        <p class="small" data-food-halal>{{ $item['stall']->halalPublicLabel() }}</p>
                        <p class="small text-secondary" data-food-category>{{ \App\Support\CatalogCategory::canonical($item['food']->category, 'food') }}</p>
                        <p class="fw-semibold" data-food-price>{{ $item['price_label'] }}</p>
                        <p class="small" data-food-reason>{{ $item['explanation'] }}</p>
                        <input type="hidden" name="food_ids[]" value="{{ $item['food']->id }}" data-selected-food>
                        <div class="d-flex gap-2 flex-wrap">
                            <button type="button" class="btn btn-outline-secondary" data-replace aria-expanded="false" aria-controls="replace-{{ $recommendation['market']->id }}-{{ $loop->index }}">Replace</button>
                            <button type="button" class="btn btn-outline-danger" data-remove aria-label="Remove {{ $item['food']->name }}">Remove</button>
                        </div>
                        <div data-replace-panel hidden class="mt-3" id="replace-{{ $recommendation['market']->id }}-{{ $loop->index }}">
                            <label class="form-label">Choose a replacement (no AI request)
                                <select class="form-select" data-replacement><option value="">Choose a food</option>
                                    @foreach ($recommendation['replacements'] as $food)<option value="{{ $food->id }}">{{ $food->name }} — {{ $food->stall->name }}</option>@endforeach
                                </select>
                            </label>
                            <button type="button" class="btn btn-outline-secondary" data-confirm-replace>Use this food</button>
                            <p class="small" data-replace-message role="status"></p>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
        <div class="planner-summary mt-4">
            <label class="form-label" for="plan-title-{{ $recommendation['market']->id }}">Plan title</label>
            <input class="form-control mb-3" id="plan-title-{{ $recommendation['market']->id }}" name="title" maxlength="255" required value="Smart visit to {{ $recommendation['market']->name }}">
            <p><strong data-count>{{ count($recommendation['foods']) }}</strong> food stops · {{ $recommendation['market']->name }}</p>
            <p class="small">Plan date{{ $plannerResult['uses_fallback'] ? ' if confirmed' : '' }}: {{ $plannerResult['recommendation_date_label'] }}</p>
            @if ($plannerResult['uses_fallback'])
                <label class="category-choice mb-3"><input type="checkbox" name="confirmed_fallback_date" value="1" required> Use recommended date: {{ $plannerResult['recommendation_date_label'] }}</label>
            @endif
            <p data-save-notice class="small" role="status">Costs are checked again when saving. Replacements keep the same market and preferences.</p>
            <button type="submit" class="btn btn-market">Save Visit Plan</button>
            <p class="small mt-2 mb-0">One saved plan per generation. Generate again to save a different itinerary.</p>
            <p class="small text-secondary mt-2 mb-0">Editable after saving. Google Calendar is available on Plan Details; nothing is synced now.</p>
            <p class="small text-secondary mt-2 mb-0">Allergens, spice levels, facilities and live opening are not verified by this planner.</p>
        </div>
    </form>
    <div hidden data-food-catalog>
        @foreach ($recommendation['replacements'] as $food)
            <template data-food="{{ $food->id }}" data-name="{{ $food->name }}" data-stall="{{ $food->stall->name }}" data-halal="{{ $food->stall->halalPublicLabel() }}" data-category="{{ \App\Support\CatalogCategory::canonical($food->category, 'food') }}" data-min="{{ $food->price_min }}" data-max="{{ $food->price_max }}"><x-food-image :food="$food" /></template>
        @endforeach
    </div>
</article>
