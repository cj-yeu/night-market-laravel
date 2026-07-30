@extends('layouts.app')

@section('title', 'Add Night Market | Night Market Selangor')

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-xl-9">
            <div class="card market-card">
                <div class="card-body p-4 p-md-5">
                    <div class="mb-4">
                        <h1 class="h3 fw-bold text-market mb-1">Add Night Market</h1>
                        <p class="text-secondary mb-0">Create a Selangor night market and its weekly operating hours.</p>
                    </div>

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
                                <input type="text" class="form-control @error('city') is-invalid @enderror"
                                    id="city" name="city" value="{{ old('city') }}" required>
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

                        <button type="submit" class="btn btn-market mt-4">Add Night Market</button>
                    </form>
                </div>
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
