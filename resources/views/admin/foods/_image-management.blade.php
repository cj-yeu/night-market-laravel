<section class="card market-card mb-4" aria-labelledby="food-image-heading">
    <div class="card-body p-4">
        <h2 id="food-image-heading" class="h5 fw-bold">Food image</h2>
        <x-food-image :food="$food" class="mb-3 rounded-3" loading="eager" />

        <form method="POST" action="{{ route('admin.foods.image.update', $food) }}" enctype="multipart/form-data">
            @csrf
            @method('PATCH')
            <label for="food_image_{{ $food->id }}" class="form-label">Upload or replace image</label>
            <input id="food_image_{{ $food->id }}" name="image" type="file"
                accept="image/jpeg,image/png,image/webp" class="form-control @error('image') is-invalid @enderror" required>
            @error('image')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
            <div class="form-text">JPEG, PNG, or WebP. Maximum 2 MB and 4096 × 4096 pixels.</div>
            <button type="submit" class="btn btn-market mt-3">{{ $food->imageUrl() ? 'Replace Image' : 'Upload Image' }}</button>
        </form>

        @if ($food->imageUrl())
            <form method="POST" action="{{ route('admin.foods.image.destroy', $food) }}" class="mt-3"
                onsubmit="return confirm('Remove this food image?');">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-outline-danger">Remove Image</button>
            </form>
        @endif
    </div>
</section>
