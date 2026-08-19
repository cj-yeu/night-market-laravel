@props(['stall'])

<span {{ $attributes->class(['badge', $stall->halalBadgeClass()]) }}>{{ $stall->halalPublicLabel() }}</span>
