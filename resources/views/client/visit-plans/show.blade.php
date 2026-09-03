@extends('layouts.app')

@section('title', $visitPlan->title.' | '.config('app.name'))

@section('content')
    @php($planIsPast = $visitPlan->visit_status === 'Past')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-3 mb-4">
        <div>
            <a href="{{ route('client.visit-plans.index') }}"
                class="btn btn-sm btn-outline-secondary mb-3">Back to My Visit Plans</a>
            <h1 class="display-6 fw-bold text-market mb-1">{{ $visitPlan->title }}</h1>
            <p class="text-secondary mb-0">{{ $visitPlan->market_display_name }}</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            @if ($visitPlan->market_is_available)
                <a href="{{ route('night-markets.show', $visitPlan->night_market_id) }}"
                    class="btn btn-outline-secondary">View Market</a>
                <a href="{{ route('night-markets.stalls.index', $visitPlan->night_market_id) }}"
                    class="btn btn-outline-secondary">Browse Market Stalls</a>
            @endif
            <a href="{{ route('client.visit-plans.edit', $visitPlan) }}" class="btn btn-market">Edit Plan</a>
            <form method="POST" action="{{ route('client.visit-plans.destroy', $visitPlan) }}"
                onsubmit="return confirm('Delete this visit plan?');">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-outline-danger">Delete Plan</button>
            </form>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-12 col-lg-7">
            <div class="card market-card mb-4">
                <div class="card-body p-4">
                    <h2 class="h4 fw-bold text-market">Visit Details</h2>
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Visit Date</dt>
                        <dd class="col-sm-8">{{ $visitPlan->visit_date->format('l, d M Y') }}</dd>
                        <dt class="col-sm-4">Status</dt>
                        <dd class="col-sm-8"><span class="badge text-bg-secondary">{{ $visitPlan->visit_status }}</span></dd>
                        <dt class="col-sm-4">Planned Items</dt>
                        <dd class="col-sm-8">{{ $visitPlan->items_count }}</dd>
                        <dt class="col-sm-4">Night Market</dt>
                        <dd class="col-sm-8">{{ $visitPlan->market_display_name }}</dd>
                        @if ($visitPlan->market_is_available)
                            <dt class="col-sm-4">Location</dt>
                            <dd class="col-sm-8">{{ $visitPlan->nightMarket->city }}, {{ $visitPlan->nightMarket->state }}</dd>
                        @endif
                        <dt class="col-sm-4">Notes</dt>
                        <dd class="col-sm-8 mb-0">{{ $visitPlan->notes ?: 'No notes added.' }}</dd>
                    </dl>
                </div>
            </div>

            <div class="card market-card mb-4">
                <div class="card-body p-4">
                    <h2 class="h4 fw-bold text-market mb-3">Selected Stalls</h2>
                    @if ($selectedStalls->isEmpty())
                        <div class="alert alert-info mb-0">No stalls have been added yet.</div>
                    @else
                        <div class="list-group list-group-flush">
                            @foreach ($selectedStalls as $item)
                                <div class="list-group-item px-0 d-flex justify-content-between gap-3">
                                    <div>
                                        <div class="fw-semibold">{{ $item->display_name }}</div>
                                        @if ($item->notes)
                                            <div class="small text-secondary">{{ $item->notes }}</div>
                                        @endif
                                    </div>
                                    @if ($canChangeItems)
                                        <form method="POST"
                                            action="{{ route('client.visit-plans.items.destroy', [$visitPlan, $item]) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                                        </form>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            <div class="card market-card">
                <div class="card-body p-4">
                    <h2 class="h4 fw-bold text-market mb-3">Selected Foods</h2>
                    @if ($selectedFoods->isEmpty())
                        <div class="alert alert-info mb-0">No foods have been added yet.</div>
                    @else
                        <div class="list-group list-group-flush">
                            @foreach ($selectedFoods as $item)
                                <div class="list-group-item px-0 d-flex justify-content-between gap-3">
                                    <div>
                                        <div class="fw-semibold">{{ $item->display_name }}</div>
                                        @if ($item->is_available && $item->food)
                                            <div class="small text-secondary">
                                                {{ $item->food->stall?->name ?: 'Stall unavailable' }}
                                                @if ($item->food->category) · {{ $item->food->category }} @endif
                                            </div>
                                            <div class="small text-secondary">
                                                <x-food-price :food="$item->food" />
                                                @if ($item->food->is_must_try) · <span class="badge text-bg-warning">Must-Try</span> @endif
                                            </div>
                                        @endif
                                        @if ($item->notes)
                                            <div class="small text-secondary">{{ $item->notes }}</div>
                                        @endif
                                    </div>
                                    @if ($canChangeItems)
                                        <form method="POST"
                                            action="{{ route('client.visit-plans.items.destroy', [$visitPlan, $item]) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                                        </form>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-5">
            <div class="card market-card mb-4">
                <div class="card-body p-4">
                    <h2 class="h4 fw-bold text-market">Operating Schedule</h2>
                    @if (! $visitPlan->market_is_available)
                        <div class="alert alert-secondary mb-0">This Night Market is no longer publicly available.</div>
                    @elseif ($visitPlan->nightMarket->operatingDays->isEmpty())
                        <div class="alert alert-warning mb-0">No operating schedule is available.</div>
                    @else
                        <ul class="mb-0 ps-3">
                            @foreach ($visitPlan->nightMarket->operatingDays as $operatingDay)
                                <li class="mb-1">
                                    <strong>{{ $operatingDay->day_of_week }}</strong>:
                                    {{ $operatingDay->opening_time->format('g:i A') }}&ndash;{{ $operatingDay->closing_time->format('g:i A') }}
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>

            <div class="card market-card mb-4">
                <div class="card-body p-4">
                    <h2 class="h4 fw-bold text-market">Google Calendar</h2>
                    <p class="small text-secondary">Add this visit to your own Google Calendar. No invitations will be sent.</p>

                    @php($calendarEvent = $calendarIntegration['event'])
                    @if ($calendarEvent)
                        <div class="alert alert-success mb-3" role="status">
                            <i class="bi bi-calendar-check me-1" aria-hidden="true"></i>
                            Added to Google Calendar
                        </div>

                        @if ($calendarIntegration['needs_sync'])
                            <div class="alert alert-warning small" role="status">
                                Your visit plan has changed. Update the Google Calendar event.
                            </div>
                        @endif

                        <div class="d-grid gap-2">
                            @if ($calendarEvent->google_event_url)
                                <a href="{{ $calendarEvent->google_event_url }}" class="btn btn-outline-secondary"
                                    target="_blank" rel="noopener noreferrer">
                                    <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Open in Google Calendar
                                </a>
                            @endif

                            @if (! $planIsPast)
                                <form method="POST" action="{{ route('client.visit-plans.google-calendar.sync', $visitPlan) }}"
                                    data-calendar-submit>
                                    @csrf
                                    <button type="submit" class="btn btn-market w-100">
                                        <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Update Calendar Event
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('client.visit-plans.google-calendar.destroy', $visitPlan) }}"
                                    data-calendar-submit
                                    onsubmit="return confirm('Remove this event from Google Calendar? Your visit plan will stay here.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-outline-danger w-100">Remove from Calendar</button>
                                </form>
                            @endif
                        </div>
                    @elseif (! $calendarIntegration['can_create'])
                        <div class="alert alert-secondary mb-0">Past visit plans cannot be added to Google Calendar.</div>
                    @elseif ($calendarIntegration['connected'])
                        <form method="POST" action="{{ route('client.visit-plans.google-calendar.sync', $visitPlan) }}" data-calendar-submit>
                            @csrf
                            <button type="submit" class="btn btn-market w-100">
                                <i class="bi bi-calendar-plus me-1" aria-hidden="true"></i>Add to Google Calendar
                            </button>
                        </form>
                    @else
                        <a href="{{ route('client.visit-plans.google-calendar.connect', $visitPlan) }}" class="btn btn-market w-100">
                            <i class="bi bi-calendar-plus me-1" aria-hidden="true"></i>Add to Google Calendar
                        </a>
                    @endif
                </div>
            </div>

            <div class="card market-card">
                <div class="card-body p-4">
                    <h2 class="h4 fw-bold text-market">Add a Plan Item</h2>
                    @if ($planIsPast)
                        <div class="alert alert-secondary mb-0">Past visit plans cannot change items. You can still update the title or notes.</div>
                    @elseif ($eligibleStalls->isEmpty() && $eligibleFoods->isEmpty())
                        <div class="alert alert-secondary mb-0">No active stalls or foods are available for this market.</div>
                    @else
                        <form method="POST" action="{{ route('client.visit-plans.items.store', $visitPlan) }}" novalidate>
                            @csrf
                            <div class="mb-3">
                                <label for="item_type" class="form-label">Item Type</label>
                                <select id="item_type" name="item_type"
                                    class="form-select @error('item_type') is-invalid @enderror" required>
                                    <option value="stall">Stall</option>
                                    <option value="food">Food</option>
                                </select>
                                @error('item_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="mb-3">
                                <label for="item_id" class="form-label">Stall or Food</label>
                                <select id="item_id" name="item_id"
                                    class="form-select @error('item_id') is-invalid @enderror" required>
                                    <option value="">Select an item</option>
                                    @foreach ($eligibleStalls as $stall)
                                        <option value="{{ $stall->id }}" data-item-type="stall">{{ $stall->name }}</option>
                                    @endforeach
                                    @foreach ($eligibleFoods as $food)
                                        <option value="{{ $food->id }}" data-item-type="food">
                                            {{ $food->name }} &mdash; {{ $food->stall->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('item_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="mb-3">
                                <label for="item_notes" class="form-label">Notes <span class="text-secondary">(optional)</span></label>
                                <textarea id="item_notes" name="notes" rows="2" maxlength="1000"
                                    class="form-control @error('notes') is-invalid @enderror">{{ old('notes') }}</textarea>
                                @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <button type="submit" class="btn btn-market">Add to Plan</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const typeSelect = document.getElementById('item_type');
            const itemSelect = document.getElementById('item_id');
            if (typeSelect && itemSelect) {
                const filterItems = () => {
                    itemSelect.value = '';
                    itemSelect.querySelectorAll('[data-item-type]').forEach((option) => {
                        option.hidden = option.dataset.itemType !== typeSelect.value;
                    });
                };
                typeSelect.addEventListener('change', filterItems);
                filterItems();
            }

            document.querySelectorAll('[data-calendar-submit]').forEach((form) => {
                form.addEventListener('submit', () => {
                    const button = form.querySelector('button[type="submit"]');
                    if (!button) return;
                    button.disabled = true;
                    button.setAttribute('aria-busy', 'true');
                });
            });
        });
    </script>
@endpush
