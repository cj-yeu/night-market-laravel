@props(['food'])

<span {{ $attributes }}>{{ $food->formattedPrice() }}</span>
