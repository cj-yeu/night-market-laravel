@props(['nightMarket', 'loading' => 'lazy'])

@php($imageUrl = $nightMarket->imageUrl())

<div {{ $attributes->class(['night-market-image-frame']) }}>
    <img
        class="night-market-image"
        src="{{ $imageUrl ?? asset('images/night-market-placeholder.svg') }}"
        alt="{{ $imageUrl ? $nightMarket->name.' cover image' : 'Night market placeholder for '.$nightMarket->name }}"
        loading="{{ $loading }}"
        width="960"
        height="540"
    >
</div>
