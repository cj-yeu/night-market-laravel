@php($currentFood = $food ?? null)

<div class="row g-3 mb-3">
    <div class="col-12 col-md-4">
        <label for="price_min" class="form-label">Minimum price (RM)</label>
        <input id="price_min" name="price_min" type="number" min="0" step="0.01"
            value="{{ old('price_min', $currentFood?->price_min) }}"
            class="form-control @error('price_min') is-invalid @enderror">
        @error('price_min') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
    <div class="col-12 col-md-4">
        <label for="price_max" class="form-label">Maximum price (RM)</label>
        <input id="price_max" name="price_max" type="number" min="0" step="0.01"
            value="{{ old('price_max', $currentFood?->price_max) }}"
            class="form-control @error('price_max') is-invalid @enderror">
        @error('price_max') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
    <div class="col-12 col-md-4">
        <label for="price_checked_at" class="form-label">Price checked date</label>
        <input id="price_checked_at" name="price_checked_at" type="date"
            value="{{ old('price_checked_at', $currentFood?->price_checked_at?->format('Y-m-d')) }}"
            class="form-control @error('price_checked_at') is-invalid @enderror" max="{{ now()->toDateString() }}">
        @error('price_checked_at') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
</div>
<div class="form-text mb-3">Use numeric prices only in the minimum and maximum fields; never derive them from display text.</div>

<div class="mb-3">
    <label for="price_display" class="form-label">Price display text</label>
    <input id="price_display" name="price_display" value="{{ old('price_display', $currentFood?->price_display) }}"
        class="form-control @error('price_display') is-invalid @enderror" maxlength="255"
        placeholder="For example: RM5 for 4 pieces or Market price">
    @error('price_display') <div class="invalid-feedback">{{ $message }}</div> @enderror
    <div class="form-text">When provided, this text is displayed instead of the numeric price or range. It is never parsed as a numeric price.</div>
</div>

<div class="mb-3">
    <label for="recommendation_reason" class="form-label">Must-Try recommendation reason</label>
    <textarea id="recommendation_reason" name="recommendation_reason" rows="3"
        class="form-control @error('recommendation_reason') is-invalid @enderror">{{ old('recommendation_reason', $currentFood?->recommendation_reason) }}</textarea>
    @error('recommendation_reason') <div class="invalid-feedback">{{ $message }}</div> @enderror
    <div class="form-text">Shown publicly only while the food is marked Must-Try. Explain the recommendation without inventing evidence.</div>
</div>

<div class="row g-3 mb-3">
    <div class="col-12 col-md-7">
        <label for="source_url" class="form-label">Source URL</label>
        <input id="source_url" name="source_url" type="url" value="{{ old('source_url', $currentFood?->source_url) }}"
            class="form-control @error('source_url') is-invalid @enderror" maxlength="255"
            placeholder="https://example.com/source">
        @error('source_url') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
    <div class="form-text">Link to the source used for food details and price information.</div>
    <div class="col-12 col-md-5">
        <label for="verified_at" class="form-label">Verification date</label>
        <input id="verified_at" name="verified_at" type="date"
            value="{{ old('verified_at', $currentFood?->verified_at?->format('Y-m-d')) }}"
            class="form-control @error('verified_at') is-invalid @enderror" max="{{ now()->toDateString() }}">
        @error('verified_at') <div class="invalid-feedback">{{ $message }}</div> @enderror
    </div>
    <div class="form-text">Record when the food details were last checked. Leave blank rather than guess.</div>
</div>
