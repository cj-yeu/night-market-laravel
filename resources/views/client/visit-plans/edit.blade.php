@extends('layouts.app')

@section('title', 'Edit Visit Plan | '.config('app.name'))

@section('content')
    @php($planIsPast = $visitPlan->visit_status === 'Past')
    @php($marketCanChange = ! $planIsPast && $visitPlan->items->isEmpty())
    <div class="row justify-content-center">
        <div class="col-12 col-lg-7">
            <div class="card market-card">
                <div class="card-body p-4 p-md-5">
                    <h1 class="h3 fw-bold text-market">Edit Visit Plan</h1>
                    <p class="text-secondary mb-4">Update your visit details and confirm the market schedule. If this plan is synced, saved changes refresh the existing Google Calendar event.</p>

                    @if ($planIsPast)
                        <div class="alert alert-secondary">Past plans keep their Night Market, visit date, and items. You can still update the title and notes.</div>
                    @elseif (! $marketCanChange)
                        <div class="alert alert-info">Remove all plan items before changing the Night Market.</div>
                    @endif

                    @if (! $visitPlan->market_is_available)
                        <div class="alert alert-warning">This plan’s current Night Market is no longer publicly available. It remains selected; choose an eligible Night Market before changing it.</div>
                    @endif

                    <form method="POST" action="{{ route('client.visit-plans.update', $visitPlan) }}" novalidate>
                        @csrf
                        @method('PATCH')

                        <div class="mb-3">
                            <label for="title" class="form-label">Plan Title</label>
                            <input type="text" class="form-control @error('title') is-invalid @enderror"
                                id="title" name="title" value="{{ old('title', $visitPlan->title) }}"
                                maxlength="255" required>
                            @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label for="night_market_id" class="form-label">Night Market</label>
                            @if (! $marketCanChange)
                                <input type="hidden" name="night_market_id" value="{{ $visitPlan->night_market_id }}">
                            @endif
                            <select class="form-select @error('night_market_id') is-invalid @enderror"
                                id="night_market_id" name="night_market_id" @disabled(! $marketCanChange) required>
                                @foreach ($nightMarkets as $nightMarket)
                                    <option value="{{ $nightMarket->id }}"
                                        @selected((string) old('night_market_id', $visitPlan->night_market_id) === (string) $nightMarket->id)>
                                        {{ $nightMarket->name }} &mdash; {{ $nightMarket->city }}
                                    </option>
                                @endforeach
                            </select>
                            @error('night_market_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <div class="form-label">Operating Schedule</div>
                            @foreach ($nightMarkets as $nightMarket)
                                <div class="alert alert-info py-2 d-none" data-market-schedule="{{ $nightMarket->id }}">
                                    <strong>{{ $nightMarket->name }}</strong>
                                    @if ($nightMarket->operatingDays->isEmpty())
                                        <div>No operating schedule is currently available.</div>
                                    @else
                                        <ul class="mb-0 mt-1 ps-3">
                                            @foreach ($nightMarket->operatingDays as $operatingDay)
                                                <li>{{ $operatingDay->day_of_week }}: {{ $operatingDay->opening_time->format('g:i A') }}&ndash;{{ $operatingDay->closing_time->format('g:i A') }}</li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        <div class="mb-3">
                            <label for="visit_date" class="form-label">Visit Date</label>
                            @if ($planIsPast)
                                <input type="hidden" name="visit_date" value="{{ $visitPlan->visit_date->toDateString() }}">
                            @endif
                            <input type="date" class="form-control @error('visit_date') is-invalid @enderror"
                                id="visit_date" name="visit_date"
                                value="{{ old('visit_date', $visitPlan->visit_date->toDateString()) }}"
                                @if (! $planIsPast) min="{{ now()->toDateString() }}" @endif
                                @disabled($planIsPast) required>
                            @error('visit_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-4">
                            <label for="notes" class="form-label">Notes <span class="text-secondary">(optional)</span></label>
                            <textarea class="form-control @error('notes') is-invalid @enderror"
                                id="notes" name="notes" rows="4" maxlength="5000">{{ old('notes', $visitPlan->notes) }}</textarea>
                            @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="d-flex flex-wrap gap-2">
                            <button type="submit" class="btn btn-market">Update Plan</button>
                            <a href="{{ route('client.visit-plans.show', $visitPlan) }}" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const marketSelect = document.getElementById('night_market_id');
            const schedules = document.querySelectorAll('[data-market-schedule]');
            if (!marketSelect) return;

            const showSelectedSchedule = () => schedules.forEach((schedule) => {
                schedule.classList.toggle('d-none', schedule.dataset.marketSchedule !== marketSelect.value);
            });

            marketSelect.addEventListener('change', showSelectedSchedule);
            showSelectedSchedule();
        });
    </script>
@endpush
