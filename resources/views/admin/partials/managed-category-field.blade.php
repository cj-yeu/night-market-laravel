@php
    $selectedCategory = \App\Support\CatalogCategory::canonical(old('category', $currentCategory ?? null), $categoryType);
    $hasLegacyCategory = filled($selectedCategory) && ! $categories->contains(
        fn ($category) => mb_strtolower($category->name) === mb_strtolower($selectedCategory)
    );
    $categoryCollapseId = 'new-'.$categoryType.'-category';
@endphp

<div class="mb-3">
    <label for="category" class="form-label">{{ $categoryLabel }}</label>
    <select id="category" name="category" data-searchable class="form-select @error('category') is-invalid @enderror">
        <option value="">No category</option>
        @if ($hasLegacyCategory)
            <option value="{{ $selectedCategory }}" selected>{{ $selectedCategory }} (legacy category)</option>
        @endif
        @foreach ($categories as $category)
            <option value="{{ $category->name }}" @selected($selectedCategory === $category->name)>{{ $category->name }}</option>
        @endforeach
    </select>
    @error('category')<div class="invalid-feedback">{{ $message }}</div>@enderror
    <div class="form-text">Choose an active managed category. Existing legacy categories can be retained while records are updated.</div>

    <button type="button" class="btn btn-sm btn-outline-secondary mt-2" data-bs-toggle="collapse"
        data-bs-target="#{{ $categoryCollapseId }}" aria-expanded="{{ $errors->has('new_category') ? 'true' : 'false' }}"
        aria-controls="{{ $categoryCollapseId }}">
        <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Add New Category
    </button>
    <div id="{{ $categoryCollapseId }}" class="collapse {{ $errors->has('new_category') ? 'show' : '' }} mt-2">
        <label for="new_category" class="form-label">New {{ strtolower($categoryLabel) }}</label>
        <input id="new_category" name="new_category" value="{{ old('new_category') }}" maxlength="100"
            class="form-control @error('new_category') is-invalid @enderror" autocomplete="off">
        @error('new_category')<div class="invalid-feedback">{{ $message }}</div>@enderror
        <div class="form-text">A new category is shared only with {{ strtolower($categoryLabel) }} records. HTML and control characters are not accepted.</div>
    </div>
</div>
