@php
    $chosenInterests = old('interests', $preferences['interests'] ?? []);
    $chosenCategories = old('categories', $preferences['explicit_categories'] ?? $preferences['categories'] ?? []);
@endphp
<section aria-labelledby="planner-preferences-heading">
    <h2 id="planner-preferences-heading" class="visually-hidden">Planning Preferences</h2>
    @if ($markets->isEmpty())
        <div class="alert alert-info">No markets currently have enough schedule, stall, and food data for planning.</div>
    @endif
    <form id="night-out-form" method="POST" action="{{ route('client.visit-plans.smart-planner.recommend') }}" class="night-out-layout" novalidate
        data-today="{{ now('Asia/Kuala_Lumpur')->toDateString() }}" data-tomorrow="{{ now('Asia/Kuala_Lumpur')->addDay()->toDateString() }}"
        data-parse-url="{{ route('client.visit-plans.smart-planner.parse') }}">
        @csrf
        <div class="night-out-main">
            <section class="night-out-panel" aria-labelledby="when-heading">
                <h3 id="when-heading" class="h4"><i class="bi bi-calendar3" aria-hidden="true"></i> When & where</h3>
                <div class="night-out-date-buttons" role="group" aria-label="Quick visit date">
                    <button type="button" class="btn btn-outline-secondary" data-date="today">Today</button>
                    <button type="button" class="btn btn-outline-secondary" data-date="tomorrow">Tomorrow</button>
                    <button type="button" class="btn btn-outline-secondary" data-date="custom">Choose date</button>
                </div>
                <div class="row g-3 mt-1">
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="visit_date">Visit Date</label>
                        <input type="date" class="form-control @error('visit_date') is-invalid @enderror" id="visit_date" name="visit_date" required data-schedule-date
                            min="{{ now('Asia/Kuala_Lumpur')->toDateString() }}" value="{{ old('visit_date', $preferences['visit_date'] ?? now('Asia/Kuala_Lumpur')->toDateString()) }}">
                        @error('visit_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div id="date-readable" class="form-text" aria-live="polite"></div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="city">City <span class="text-secondary">(optional)</span></label>
                        <select class="form-select @error('city') is-invalid @enderror" id="city" name="city">
                            <option value="">Any public Selangor city</option>
                            @foreach ($cities as $city)<option value="{{ $city->city }}" @selected(old('city', $preferences['city'] ?? '') === $city->city)>{{ $city->city }}</option>@endforeach
                        </select>
                        @error('city')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </section>

            <section class="night-out-panel" aria-labelledby="budget-heading">
                <h3 id="budget-heading" class="h4"><i class="bi bi-wallet2" aria-hidden="true"></i> Food budget & Halal</h3>
                <p id="food-budget-help" class="small text-secondary">One serving of each selected food. This does not include transport, queues or meals for multiple people. Prices can change.</p>
                <div class="d-flex flex-wrap gap-2 mb-3" role="group" aria-label="Food budget presets">
                    @foreach ([20, 30, 50] as $amount)<button type="button" class="btn btn-outline-secondary" data-budget="{{ $amount }}">RM{{ $amount }}</button>@endforeach
                    <button type="button" class="btn btn-outline-secondary" data-budget="custom">Custom</button>
                </div>
                <div class="row g-3">
                    <div class="col-12 col-md-5">
                        <label for="budget_max" class="form-label">{{ $activeTemplate === 'budget' ? 'Budget Limit (RM)' : 'Maximum food budget (RM)' }}</label>
                        <input id="budget_max" name="budget_max" type="number" min="0" max="10000" step="0.01" inputmode="decimal" aria-describedby="food-budget-help"
                            value="{{ old('budget_max', $preferences['budget_max'] ?? '') }}" placeholder="Optional" class="form-control @error('budget_max') is-invalid @enderror">
                        @error('budget_max')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12 col-md-7">
                        <label for="halal_preference" class="form-label">Stall Halal Preference</label>
                        <select id="halal_preference" name="halal_preference" class="form-select" aria-describedby="halal-help">
                            @foreach ($halalOptions as $value => $label)<option value="{{ $value }}" @selected(old('halal_preference', $preferences['halal_preference'] ?? 'any') === $value)>{{ $label }}</option>@endforeach
                        </select>
                        <div id="halal-help" class="form-text">Muslim-owned/claimed is not Halal certification. Unknown means the Stall classification is not verified; Foods do not have a separate certification.</div>
                    </div>
                </div>
            </section>

            <section class="night-out-panel" aria-labelledby="food-heading">
                <h3 id="food-heading" class="h4"><i class="bi bi-heart" aria-hidden="true"></i> What would you like to eat?</h3>
                <p class="text-secondary small">Choose any interests, or leave everything clear for Any food. Groups use actual catalog categories, including legacy aliases.</p>
                <div id="food-selected" class="d-flex flex-wrap gap-2 mb-3" aria-label="Selected food interests"></div>
                <button type="button" id="clear-food" class="btn btn-sm btn-outline-secondary mb-3">Clear selections</button>
                <fieldset>
                    <legend class="visually-hidden">Food interests</legend>
                    <div class="food-interest-grid">
                        @foreach ($interestOptions as $key => $interest)
                            <div class="food-interest">
                                <input type="checkbox" class="btn-check" name="interests[]" value="{{ $key }}" id="interest-{{ $key }}" autocomplete="off" @checked(in_array($key, $chosenInterests, true))>
                                <label for="interest-{{ $key }}"><i class="bi bi-{{ $interest['icon'] }}" aria-hidden="true"></i><span>{{ $interest['label'] }}</span><span class="interest-check" aria-hidden="true">✓</span></label>
                            </div>
                        @endforeach
                    </div>
                </fieldset>
                <details class="mt-3" @if ($chosenCategories !== []) open @endif>
                    <summary>More choices — individual categories</summary>
                    <label for="category-search" class="form-label mt-3">Find a food category</label>
                    <input type="search" id="category-search" class="form-control mb-3" placeholder="Search categories" autocomplete="off">
                    <fieldset class="category-choices">
                        <legend class="visually-hidden">Preferred Food Categories</legend>
                        @foreach ($categories as $category)
                            <label class="category-choice" data-category-choice>
                                <input type="checkbox" name="categories[]" value="{{ $category->category }}" class="form-check-input" @checked(in_array($category->category, $chosenCategories, true))>
                                <span>{{ $category->category }}</span>
                            </label>
                        @endforeach
                    </fieldset>
                    <p id="category-empty" class="small text-secondary" hidden>No matching categories. Try a different search.</p>
                </details>
                @error('interests')<div class="text-danger" role="alert">{{ $message }}</div>@enderror
                @error('categories')<div class="text-danger" role="alert">{{ $message }}</div>@enderror
            </section>

            <section class="night-out-panel" aria-labelledby="words-heading">
                <h3 id="words-heading" class="h4"><i class="bi bi-chat-square-text" aria-hidden="true"></i> Or, put it into words</h3>
                <label for="ideal-night" class="form-label">Tell us about your ideal night out… <span class="text-secondary">(optional)</span></label>
                <textarea id="ideal-night" rows="3" maxlength="1000" class="form-control" placeholder="Tomorrow in Petaling Jaya, RM30, desserts, Halal-certified only." aria-describedby="ideal-help"></textarea>
                <p id="ideal-help" class="form-text">English or Chinese. Do not include personal information. Only this text is sent when you click Understand my request. It is not a chat and cannot check allergies or live opening.</p>
                <button type="button" id="parse-night" class="btn btn-outline-market">Understand my request</button>
                <p id="parse-status" class="small mt-2" role="status" aria-live="polite"></p>
                <div id="parsed-preferences" hidden>
                    <h4 class="h6">Review suggestions — choose what to apply</h4>
                    <p class="small">Nothing changes until you confirm. Differences from your current choices are marked; applying a suggestion replaces only that preference.</p>
                    <div id="parsed-fields" class="vstack gap-2"></div>
                    <button type="button" id="apply-parsed" class="btn btn-market mt-3">Apply selected suggestions</button>
                </div>
            </section>

            <details class="night-out-panel" @if ($activeTemplate || !empty($preferences['night_market_id']) || !empty($preferences['budget_min'])) open @endif>
                <summary>More preferences & recommended templates</summary>
                <div class="row g-3 mt-2">
                    <div class="col-12">
                        <label for="night_market_id" class="form-label">Target Night Market (optional)</label>
                        <select id="night_market_id" name="night_market_id" data-parent-select="city" data-searchable data-schedule-select data-fallback="true" class="form-select">
                            <option value="">Any operating public Market</option>
                            @foreach ($markets as $market)
                                <option data-parent="{{ $market->city }}" data-days="{{ $market->operatingDays->pluck('day_of_week')->implode('|') }}" data-schedule="{{ $market->operatingDays->map(fn ($day) => $day->day_of_week.' '.($day->opening_time?->format('g:i A') ?? 'Time not available').'–'.($day->closing_time?->format('g:i A') ?? 'Time not available'))->implode('; ') }}" value="{{ $market->id }}" @selected((string) old('night_market_id', $preferences['night_market_id'] ?? '') === (string) $market->id)>{{ $market->name }} — {{ $market->city }}</option>
                            @endforeach
                        </select>
                        <p data-schedule-hint class="small text-secondary mt-2" role="status"></p>
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="budget_min" class="form-label">Minimum Budget (RM, optional preference)</label>
                        <input id="budget_min" name="budget_min" type="number" min="0" max="10000" step="0.01" value="{{ old('budget_min', $preferences['budget_min'] ?? '') }}" class="form-control">
                        <div class="form-text">Not a minimum spend. We will not add food just to reach it.</div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="max_markets" class="form-label">Maximum alternative markets</label>
                        <select id="max_markets" name="max_markets" class="form-select">
                            @for ($limit = 1; $limit <= 3; $limit++)<option value="{{ $limit }}" @selected((int) old('max_markets', $preferences['max_markets'] ?? 1) === $limit)>{{ $limit }}</option>@endfor
                        </select>
                        <div class="form-text">Each saved plan visits one market. Templates use one market.</div>
                    </div>
                    <div class="col-12"><label class="category-choice"><input type="hidden" name="must_try" value="0"><input class="form-check-input" type="checkbox" name="must_try" id="must_try" value="1" @checked((bool) old('must_try', $preferences['must_try'] ?? false))> Recommend Must-Try Foods only</label></div>
                    <fieldset class="col-12" id="planner-templates">
                        <legend class="h5">Recommended Plan Templates</legend>
                        <p class="small text-secondary">Suggestions use catalog information and fixed rules. Optional AI can select within those rules, never live data.</p>
                        <label class="category-choice"><input type="radio" name="template" value="" @checked(!$activeTemplate)> No template — my preferences</label>
                        <div class="template-grid">
                            @foreach ($templates as $key => $template)
                                <label class="template-choice">
                                    <input type="radio" name="template" value="{{ $key }}" @checked($activeTemplate === $key)>
                                    <span><strong>{{ $template['name'] }}</strong><span class="d-block small mt-1">{{ $template['description'] }}</span><span class="d-block small text-secondary mt-1">{{ $template['limit'] }}</span><span class="template-active badge text-bg-warning mt-2">Template Active</span></span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                    <div class="col-12">
                        <label for="preference_notes" class="form-label">Planning Notes (private; not sent to AI)</label>
                        <textarea id="preference_notes" name="preference_notes" rows="2" maxlength="1000" class="form-control">{{ old('preference_notes', $preferences['preference_notes'] ?? '') }}</textarea>
                    </div>
                </div>
            </details>
        </div>
        <aside class="night-out-summary night-out-panel" aria-labelledby="summary-heading">
            <p class="small text-market fw-bold">YOUR NIGHT OUT</p>
            <h3 id="summary-heading" class="h4">A plan that feels like you</h3>
            <dl id="live-preferences" class="mb-3"></dl>
            <p class="small text-secondary">Regular schedules are not live opening guarantees. Budget uses numeric price upper bounds. You choose when to save.</p>
            <label for="recommendation_mode" class="form-label">Recommendation method</label>
            <select id="recommendation_mode" name="recommendation_mode" class="form-select mb-3">
                <option value="ai" @selected(($preferences['recommendation_mode'] ?? 'ai') === 'ai')>AI-assisted selection</option>
                <option value="basic" @selected(($preferences['recommendation_mode'] ?? '') === 'basic')>Basic catalog rules (no AI)</option>
            </select>
            <button type="submit" class="btn btn-market w-100" @disabled($markets->isEmpty())>Generate Recommendations</button>
            <p id="generate-status" class="small mt-2 mb-0" role="status"></p>
            <p class="small mt-3 mb-0">AI is optional. If unavailable, we will clearly label the basic recommendation.</p>
        </aside>
    </form>
</section>
