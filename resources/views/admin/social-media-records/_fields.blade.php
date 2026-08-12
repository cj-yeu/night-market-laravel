<div class="row g-3">
    <div class="col-md-6">
        <label for="night_market_id" class="form-label">Confirmed Night Market <span class="text-secondary">(optional until approval)</span></label>
        <select id="night_market_id" name="night_market_id"
            class="form-select @error('night_market_id') is-invalid @enderror">
            <option value="">Not confirmed yet</option>
            @foreach ($nightMarkets as $nightMarket)
                <option value="{{ $nightMarket->id }}"
                    @selected((string) old('night_market_id', $socialMediaRecord?->night_market_id) === (string) $nightMarket->id)>
                    {{ $nightMarket->name }}
                </option>
            @endforeach
        </select>
        @error('night_market_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-6">
        <label for="food_id" class="form-label">Confirmed Food <span class="text-secondary">(optional)</span></label>
        <select id="food_id" name="food_id" class="form-select @error('food_id') is-invalid @enderror">
            <option value="">No related food</option>
            @foreach ($foods as $food)
                <option value="{{ $food->id }}" data-market-id="{{ $food->stall->night_market_id }}"
                    @selected((string) old('food_id', $socialMediaRecord?->food_id) === (string) $food->id)>
                    {{ $food->name }} &mdash; {{ $food->stall->name }}
                </option>
            @endforeach
        </select>
        @error('food_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-6">
        <label for="platform" class="form-label">Platform</label>
        <select id="platform" name="platform" class="form-select @error('platform') is-invalid @enderror" required>
            <option value="">Select a platform</option>
            @foreach ($platforms as $platform)
                <option value="{{ $platform }}" @selected(old('platform', $socialMediaRecord?->platform) === $platform)>
                    {{ $platform }}
                </option>
            @endforeach
        </select>
        @error('platform')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-6">
        <label for="posted_date" class="form-label">Posted Date</label>
        <input type="date" id="posted_date" name="posted_date" max="{{ now()->toDateString() }}"
            value="{{ old('posted_date', $socialMediaRecord?->posted_date?->toDateString()) }}"
            class="form-control @error('posted_date') is-invalid @enderror" required>
        @error('posted_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-12">
        <label for="original_post_url" class="form-label">Original Post URL</label>
        <input type="url" id="original_post_url" name="original_post_url" maxlength="2048"
            value="{{ old('original_post_url', $socialMediaRecord?->original_post_url) }}"
            class="form-control @error('original_post_url') is-invalid @enderror"
            placeholder="https://" required>
        @error('original_post_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-12">
        <label for="content_summary" class="form-label">Public Caption, Description, or Transcript</label>
        <textarea id="content_summary" name="content_summary" rows="9" maxlength="50000"
            class="form-control @error('content_summary') is-invalid @enderror"
            required>{{ old('content_summary', $socialMediaRecord?->content_summary) }}</textarea>
        <div class="form-text">Paste the public text itself. A URL alone cannot be extracted.</div>
        @error('content_summary')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    @foreach ([
        'extracted_hashtags' => 'Extracted Hashtags',
        'extracted_location_mentions' => 'Possible Location Mentions',
        'extracted_market_mentions' => 'Possible Night Market Mentions',
        'extracted_food_mentions' => 'Possible Food Mentions',
    ] as $field => $label)
        <div class="col-md-6">
            <label for="{{ $field }}" class="form-label">{{ $label }}</label>
            <textarea id="{{ $field }}" name="{{ $field }}" rows="3" maxlength="10000"
                class="form-control @error($field) is-invalid @enderror"
                placeholder="Automatically extracted; edit as a comma-separated list">{{ old($field, implode(', ', $socialMediaRecord?->{$field} ?? [])) }}</textarea>
            <div class="form-text">Leave blank on creation to extract automatically from the pasted text.</div>
            @error($field)<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    @endforeach

    @foreach (['likes' => 'Likes', 'comments' => 'Comments', 'shares' => 'Shares'] as $field => $label)
        <div class="col-md-4">
            <label for="{{ $field }}" class="form-label">{{ $label }}</label>
            <input type="number" id="{{ $field }}" name="{{ $field }}" min="0"
                value="{{ old($field, $socialMediaRecord?->{$field} ?? 0) }}"
                class="form-control @error($field) is-invalid @enderror" required>
            @error($field)<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    @endforeach
</div>

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const marketSelect = document.getElementById('night_market_id');
            const foodSelect = document.getElementById('food_id');
            const selectedFood = foodSelect.value;

            const filterFoods = (preserveSelection = false) => {
                foodSelect.querySelectorAll('[data-market-id]').forEach((option) => {
                    option.hidden = option.dataset.marketId !== marketSelect.value;
                });

                if (!preserveSelection || foodSelect.selectedOptions[0]?.hidden) {
                    foodSelect.value = '';
                }
            };

            marketSelect.addEventListener('change', () => filterFoods(false));
            filterFoods(true);
            if (selectedFood && !foodSelect.selectedOptions[0]?.hidden) foodSelect.value = selectedFood;
        });
    </script>
@endpush
