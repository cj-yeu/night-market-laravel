@props(['reviewable', 'compact' => false])

@php
    $reviewCount = (int) ($reviewable->public_reviews_count ?? 0);
    $averageRating = $reviewCount > 0 ? round((float) $reviewable->public_reviews_avg_rating, 1) : null;
    $filledStars = $averageRating === null ? 0 : max(0, min(5, (int) round($averageRating)));
@endphp

@if ($averageRating === null)
    <span {{ $attributes->class([$compact ? 'small text-secondary' : 'text-secondary']) }}>No reviews yet</span>
@else
    <span {{ $attributes->class([
        'd-inline-flex flex-wrap align-items-center gap-1 text-nowrap',
        $compact ? 'small' : '',
    ]) }} aria-label="Rated {{ number_format($averageRating, 1) }} out of 5 from {{ $reviewCount }} {{ $reviewCount === 1 ? 'review' : 'reviews' }}">
        <strong>{{ number_format($averageRating, 1) }}</strong>
        <span class="text-warning" aria-hidden="true">{{ str_repeat('★', $filledStars) }}{{ str_repeat('☆', 5 - $filledStars) }}</span>
        <span class="text-secondary">{{ number_format($reviewCount) }} {{ $reviewCount === 1 ? 'review' : 'reviews' }}</span>
        <span class="visually-hidden">Rated {{ number_format($averageRating, 1) }} out of 5</span>
    </span>
@endif
