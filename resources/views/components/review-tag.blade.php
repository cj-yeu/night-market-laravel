@props(['tag', 'label'])

@php
    $tone = match ($tag) {
        'tasty', 'many_choices' => 'warm',
        'good_value', 'clean', 'family_friendly' => 'positive',
        'large_portion', 'easy_parking' => 'info',
        'long_queue' => 'caution',
        default => 'neutral',
    };
@endphp

<span {{ $attributes->merge(['class' => 'review-tag review-tag-'.$tone]) }}>{{ $label }}</span>
