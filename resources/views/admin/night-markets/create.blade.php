@extends('layouts.app')

@section('title', 'Create Night Market | '.config('app.name'))

@section('content')
    <div class="mx-auto" style="max-width: 960px;">
        <nav aria-label="breadcrumb" class="mb-3">
            <ol class="breadcrumb small mb-0">
                <li class="breadcrumb-item text-secondary">Management</li>
                <li class="breadcrumb-item text-secondary">Night Markets</li>
                <li class="breadcrumb-item active" aria-current="page">Create</li>
            </ol>
        </nav>

        <header class="mb-4">
            <h1 class="h2 fw-bold mb-2">Create Night Market</h1>
            <p class="text-secondary mb-0">Add a new night market for visitors to discover.</p>
        </header>

        <div class="card market-card">
            <div class="card-body p-4 p-md-5">

                    <form method="POST" action="{{ route('admin.night-markets.store') }}" novalidate>
                        @csrf

                        <div class="row g-3">
                            <div class="col-12">
                                <label for="name" class="form-label">Market Name</label>
                                <input type="text" class="form-control @error('name') is-invalid @enderror"
                                    id="name" name="name" value="{{ old('name') }}" required>
                                @error('name')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-12">
                                <label for="address" class="form-label">Address</label>
                                <input type="text" class="form-control @error('address') is-invalid @enderror"
                                    id="address" name="address" value="{{ old('address') }}" required>
                                @error('address')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6">
                                <label for="city" class="form-label">City</label>
                                <select class="form-select @error('city') is-invalid @enderror" id="city" name="city" required>
                                    <option value="">Select a Selangor city or town</option>
                                    @foreach ($cities as $city)<option value="{{ $city }}" @selected(old('city') === $city)>{{ $city }}</option>@endforeach
                                </select>
                                <div class="form-text">Choose the controlled Selangor location used for public discovery.</div>
                                @error('city')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">State</label>
                                <input type="text" class="form-control" value="Selangor" disabled>
                            </div>

                            <div class="col-md-6">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select @error('status') is-invalid @enderror"
                                    id="status" name="status" required>
                                    <option value="active" @selected(old('status', 'active') === 'active')>Active</option>
                                    <option value="inactive" @selected(old('status') === 'inactive')>Inactive</option>
                                </select>
                                @error('status')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-12">
                                <label for="description" class="form-label">Description</label>
                                <textarea class="form-control @error('description') is-invalid @enderror"
                                    id="description" name="description" rows="4">{{ old('description') }}</textarea>
                                @error('description')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-7">
                                <label for="source_url" class="form-label">Source URL</label>
                                <input type="url" class="form-control @error('source_url') is-invalid @enderror" id="source_url" name="source_url" value="{{ old('source_url') }}" maxlength="255">
                                <div class="form-text">Link to the source used to confirm the market details.</div>
                                @error('source_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-5">
                                <label for="verified_at" class="form-label">Last verified</label>
                                <input type="date" class="form-control @error('verified_at') is-invalid @enderror" id="verified_at" name="verified_at" value="{{ old('verified_at') }}" max="{{ now()->toDateString() }}">
                                <div class="form-text">When these details were last checked. Leave blank rather than guess.</div>
                                @error('verified_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <fieldset class="mt-4">
                            <legend class="h5">Operating Days</legend>
                            @error('operating_days')
                                <div class="alert alert-danger py-2">{{ $message }}</div>
                            @enderror

                            <div class="vstack gap-3">
                                @foreach ($days as $index => $day)
                                    @php($selected = old("operating_days.$index.day_of_week") === $day)
                                    <div class="border rounded-3 p-3 operating-day-row">
                                        <div class="row g-3 align-items-end">
                                            <div class="col-md-4">
                                                <div class="form-check mb-md-2">
                                                    <input class="form-check-input day-toggle" type="checkbox"
                                                        id="day_{{ $index }}"
                                                        name="operating_days[{{ $index }}][day_of_week]"
                                                        value="{{ $day }}" @checked($selected)>
                                                    <label class="form-check-label fw-semibold"
                                                        for="day_{{ $index }}">{{ $day }}</label>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <label for="opening_{{ $index }}" class="form-label">Opening Time</label>
                                                <input type="time"
                                                    class="form-control @error("operating_days.$index.opening_time") is-invalid @enderror day-time"
                                                    id="opening_{{ $index }}"
                                                    name="operating_days[{{ $index }}][opening_time]"
                                                    value="{{ old("operating_days.$index.opening_time") }}"
                                                    @disabled(! $selected)>
                                                @error("operating_days.$index.opening_time")
                                                    <div class="invalid-feedback">{{ $message }}</div>
                                                @enderror
                                            </div>
                                            <div class="col-md-4">
                                                <label for="closing_{{ $index }}" class="form-label">Closing Time</label>
                                                <input type="time"
                                                    class="form-control @error("operating_days.$index.closing_time") is-invalid @enderror day-time"
                                                    id="closing_{{ $index }}"
                                                    name="operating_days[{{ $index }}][closing_time]"
                                                    value="{{ old("operating_days.$index.closing_time") }}"
                                                    @disabled(! $selected)>
                                                @error("operating_days.$index.closing_time")
                                                    <div class="invalid-feedback">{{ $message }}</div>
                                                @enderror
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </fieldset>

                        <div class="d-flex flex-wrap gap-2 mt-4">
                            <button type="submit" class="btn btn-market">Create Night Market</button>
                            <a href="{{ route('admin.dashboard') }}" class="btn btn-admin-secondary">Cancel</a>
                        </div>
                    </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.querySelectorAll('.operating-day-row').forEach((row) => {
            const toggle = row.querySelector('.day-toggle');
            const timeInputs = row.querySelectorAll('.day-time');

            toggle.addEventListener('change', () => {
                timeInputs.forEach((input) => {
                    input.disabled = !toggle.checked;

                    if (!toggle.checked) {
                        input.value = '';
                    }
                });
            });
        });
    </script>
@endpush
