<section class="card market-card mb-4" aria-labelledby="stall-image-heading">
    <div class="card-body p-4">
        <h2 id="stall-image-heading" class="h5 fw-bold">Stall image</h2>
        <x-stall-image :stall="$stall" class="mb-3 rounded-3" loading="eager" />

        <form method="POST" action="{{ route('admin.stalls.image.update', $stall) }}" enctype="multipart/form-data">
            @csrf
            @method('PATCH')
            <label for="stall_image_{{ $stall->id }}" class="form-label">Upload or replace image</label>
            <input id="stall_image_{{ $stall->id }}" name="image" type="file"
                accept="image/jpeg,image/png,image/webp" class="form-control @error('image') is-invalid @enderror" required>
            @error('image')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
            <div class="form-text">JPEG, PNG, or WebP. Maximum 2 MB and 4096 × 4096 pixels.</div>
            <button type="submit" class="btn btn-market mt-3">{{ $stall->imageUrl() ? 'Replace Image' : 'Upload Image' }}</button>
        </form>

        @if ($stall->imageUrl())
            <form method="POST" action="{{ route('admin.stalls.image.destroy', $stall) }}" class="mt-3"
                onsubmit="return confirm('Remove this stall image?');">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-outline-danger">Remove Image</button>
            </form>
        @endif
    </div>
</section>
