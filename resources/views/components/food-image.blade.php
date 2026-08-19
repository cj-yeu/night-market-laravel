@props(['food', 'loading' => 'lazy'])

<div {{ $attributes->class(['catalog-image-frame', 'food-image-frame']) }}>
    <img
        src="{{ $food->imageUrl() ?? asset('images/food-placeholder.svg') }}"
        alt="{{ $food->name }} food"
        class="catalog-image"
        loading="{{ $loading }}"
    >
</div>
