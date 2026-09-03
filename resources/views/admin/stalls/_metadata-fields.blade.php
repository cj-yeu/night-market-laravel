@php($currentStall = $stall ?? null)

@include('admin.partials.managed-category-field', [
    'categories' => $categories,
    'currentCategory' => $currentStall?->category,
    'categoryType' => 'stall',
    'categoryLabel' => 'Stall Category',
])

<div class="row g-3 mb-3">
    <div class="col-12 col-md-6">
        <label for="halal_status" class="form-label">Halal classification</label>
        <select id="halal_status" name="halal_status" class="form-select @error('halal_status') is-invalid @enderror" required>
            @foreach ($halalStatuses as $value => $label)
                <option value="{{ $value }}" @selected(old('halal_status', $currentStall?->halal_status ?? \App\Models\Stall::HALAL_UNKNOWN) === $value)>
                    {{ $label }}
                </option>
            @endforeach
        </select>
        @error('halal_status') <div class="invalid-feedback">{{ $message }}</div> @enderror
        <div class="form-text">If evidence is absent, unclear, or conflicting, select Unknown. A vendor claim is not certification.</div>
    </div>
</div>

<div class="mb-3">
    <label for="halal_evidence_url" class="form-label">Halal evidence URL</label>
    <input id="halal_evidence_url" name="halal_evidence_url" type="url"
        value="{{ old('halal_evidence_url', $currentStall?->halal_evidence_url) }}"
        class="form-control @error('halal_evidence_url') is-invalid @enderror" maxlength="255"
        placeholder="https://example.com/evidence">
    @error('halal_evidence_url') <div class="invalid-feedback">{{ $message }}</div> @enderror
    <div class="form-text">Required for Halal-certified and Muslim-owned/claimed classifications.</div>
</div>

<div class="mb-3">
    <label for="halal_notes" class="form-label">Halal evidence notes</label>
    <textarea id="halal_notes" name="halal_notes" rows="3"
        class="form-control @error('halal_notes') is-invalid @enderror">{{ old('halal_notes', $currentStall?->halal_notes) }}</textarea>
    @error('halal_notes') <div class="invalid-feedback">{{ $message }}</div> @enderror
    <div class="form-text">Non-halal requires an evidence URL or a clear evidence note.</div>
</div>

<div class="row g-3 mb-3">
    <div class="col-12 col-md-7">
        <label for="source_url" class="form-label">General source URL</label>
        <input id="source_url" name="source_url" type="url" value="{{ old('source_url', $currentStall?->source_url) }}"
            class="form-control @error('source_url') is-invalid @enderror" maxlength="255"
            placeholder="https://example.com/source">
        @error('source_url') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
    <div class="col-12 col-md-5">
        <label for="verified_at" class="form-label">Verification date</label>
        <input id="verified_at" name="verified_at" type="date"
            value="{{ old('verified_at', $currentStall?->verified_at?->format('Y-m-d')) }}"
            class="form-control @error('verified_at') is-invalid @enderror" max="{{ now()->toDateString() }}">
        @error('verified_at') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
</div>
