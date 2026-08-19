@props(['stall', 'loading' => 'lazy'])

<div {{ $attributes->class(['catalog-image-frame', 'stall-image-frame']) }}>
    <img
        src="{{ $stall->imageUrl() ?? asset('images/stall-placeholder.svg') }}"
        alt="{{ $stall->name }} stall"
        class="catalog-image"
        loading="{{ $loading }}"
    >
</div>
