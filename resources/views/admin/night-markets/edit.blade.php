@extends('layouts.app')

@section('title', 'Edit '.$nightMarket->name.' | Admin')

@section('content')
    @php($existingDays = $nightMarket->operatingDays->keyBy('day_of_week'))
    <div class="mx-auto" style="max-width: 960px;">
        <div class="d-flex justify-content-between gap-3 mb-4"><div><h1 class="h2 fw-bold mb-1">Edit Night Market</h1><p class="text-secondary mb-0">Status is managed separately from catalog details.</p></div><a href="{{ route('admin.night-markets.show', $nightMarket) }}" class="btn btn-outline-secondary align-self-start">Cancel</a></div>
        <div class="card market-card"><div class="card-body p-4 p-md-5">
            <form method="POST" action="{{ route('admin.night-markets.update', $nightMarket) }}" novalidate>
                @csrf @method('PATCH')
                <div class="row g-3">
                    <div class="col-12"><label for="name" class="form-label">Market Name</label><input id="name" name="name" value="{{ old('name', $nightMarket->name) }}" class="form-control @error('name') is-invalid @enderror" required>@error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    <div class="col-12"><label for="address" class="form-label">Address</label><input id="address" name="address" value="{{ old('address', $nightMarket->address) }}" class="form-control @error('address') is-invalid @enderror" required>@error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    <div class="col-md-6"><label for="city" class="form-label">City</label><input id="city" name="city" value="{{ old('city', $nightMarket->city) }}" class="form-control @error('city') is-invalid @enderror" required>@error('city')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    <div class="col-md-6"><label class="form-label">State</label><input value="Selangor" class="form-control" disabled></div>
                    <div class="col-12"><label for="description" class="form-label">Description</label><textarea id="description" name="description" rows="4" class="form-control @error('description') is-invalid @enderror">{{ old('description', $nightMarket->description) }}</textarea>@error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                </div>
                <fieldset class="mt-4"><legend class="h5">Operating Days</legend>@error('operating_days')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror
                    <div class="vstack gap-3">
                        @foreach ($days as $index => $day)
                            @php($existing = $existingDays->get($day))
                            @php($selected = old("operating_days.$index.day_of_week", $existing ? $day : null) === $day)
                            <div class="border rounded-3 p-3 operating-day-row"><div class="row g-3 align-items-end">
                                <div class="col-md-4"><div class="form-check mb-md-2"><input class="form-check-input day-toggle" type="checkbox" id="day_{{ $index }}" name="operating_days[{{ $index }}][day_of_week]" value="{{ $day }}" @checked($selected)><label class="form-check-label fw-semibold" for="day_{{ $index }}">{{ $day }}</label></div></div>
                                <div class="col-md-4"><label for="opening_{{ $index }}" class="form-label">Opening Time</label><input type="time" id="opening_{{ $index }}" name="operating_days[{{ $index }}][opening_time]" value="{{ old("operating_days.$index.opening_time", $existing?->opening_time?->format('H:i')) }}" class="form-control day-time @error("operating_days.$index.opening_time") is-invalid @enderror" @disabled(! $selected)>@error("operating_days.$index.opening_time")<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                <div class="col-md-4"><label for="closing_{{ $index }}" class="form-label">Closing Time</label><input type="time" id="closing_{{ $index }}" name="operating_days[{{ $index }}][closing_time]" value="{{ old("operating_days.$index.closing_time", $existing?->closing_time?->format('H:i')) }}" class="form-control day-time @error("operating_days.$index.closing_time") is-invalid @enderror" @disabled(! $selected)>@error("operating_days.$index.closing_time")<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                            </div></div>
                        @endforeach
                    </div>
                </fieldset>
                <button type="submit" class="btn btn-market mt-4">Save Changes</button>
            </form>
        </div></div>
    </div>
@endsection

@push('scripts')
    <script>
        document.querySelectorAll('.operating-day-row').forEach((row) => {
            const toggle = row.querySelector('.day-toggle');
            const inputs = row.querySelectorAll('.day-time');
            toggle.addEventListener('change', () => inputs.forEach((input) => {
                input.disabled = !toggle.checked;
                if (!toggle.checked) input.value = '';
            }));
        });
    </script>
@endpush
