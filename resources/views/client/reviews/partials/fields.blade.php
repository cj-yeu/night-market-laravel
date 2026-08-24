<fieldset class="mb-3">
    <legend class="form-label">Rating</legend>
    <div class="d-flex flex-wrap gap-3" role="radiogroup" aria-describedby="rating-help">
        @for ($rating = 5; $rating >= 1; $rating--)
            <div class="form-check">
                <input class="form-check-input @error('rating') is-invalid @enderror" type="radio"
                    name="rating" id="rating-{{ $rating }}" value="{{ $rating }}"
                    @checked((int) old('rating', $review->rating ?? 0) === $rating)>
                <label class="form-check-label" for="rating-{{ $rating }}">
                    {{ $rating }} {{ $rating === 1 ? 'star' : 'stars' }}
                </label>
            </div>
        @endfor
    </div>
    <div id="rating-help" class="form-text">Choose a whole-star rating from 1 to 5.</div>
    @error('rating')
        <div class="text-danger small mt-1">{{ $message }}</div>
    @enderror
</fieldset>

<fieldset class="mb-4">
    <legend class="form-label mb-1">Quick tags <span class="text-secondary fw-normal">(optional)</span></legend>
    <p class="form-text mt-0">Choose up to 3 tags that best describe your experience.</p>
    <div class="d-flex flex-wrap gap-2">
        @foreach ($tagOptions as $value => $label)
            <div class="form-check form-check-inline me-0">
                <input class="btn-check" type="checkbox" name="tags[]" id="tag-{{ $value }}" value="{{ $value }}"
                    @checked(in_array($value, old('tags', $review->tags ?? []), true))>
                <label class="btn btn-sm btn-outline-secondary" for="tag-{{ $value }}">{{ $label }}</label>
            </div>
        @endforeach
    </div>
    @error('tags')
        <div class="text-danger small mt-1">{{ $message }}</div>
    @enderror
    @error('tags.*')
        <div class="text-danger small mt-1">{{ $message }}</div>
    @enderror
</fieldset>

<div class="mb-4">
    <label for="comment" class="form-label">Comment</label>
    <textarea class="form-control @error('comment') is-invalid @enderror" id="comment" name="comment"
        rows="5" minlength="10" maxlength="1000" required>{{ old('comment', $review->comment ?? '') }}</textarea>
    <div class="form-text">Enter between 10 and 1,000 characters.</div>
    @error('comment')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="d-flex flex-wrap gap-2">
    <button type="submit" class="btn btn-market">{{ $submitLabel }}</button>
    <a href="{{ $cancelUrl ?? route('foods.show', $food) }}" class="btn btn-outline-secondary">Cancel</a>
</div>
