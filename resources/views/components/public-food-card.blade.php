@props(['food', 'showRecommendation' => false])

<article class="card market-card h-100 overflow-hidden" data-food-id="{{ $food->id }}">
    <x-food-image :food="$food" />
    <div class="card-body p-4 d-flex flex-column">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
            <h2 class="h4 fw-bold text-break mb-0">{{ $food->name }}</h2>
            @if ($food->is_must_try)
                <span class="badge text-bg-warning">Must-Try</span>
            @endif
        </div>
        <p class="small text-market fw-semibold mb-1">
            <a href="{{ route('foods.index', ['stall_id' => $food->stall->id]) }}" class="text-reset">
                {{ $food->stall->name }}
            </a>
        </p>
        <p class="small text-secondary mb-2">
            <a href="{{ route('night-markets.show', $food->stall->nightMarket) }}" class="text-reset">
                {{ $food->stall->nightMarket->name }}
            </a>
            · {{ $food->stall->nightMarket->city }}
        </p>
        <div class="d-flex flex-wrap gap-2 mb-3">
            @if ($food->category)
                <span class="badge text-bg-light border text-break">{{ $food->category }}</span>
            @endif
            <x-halal-status :stall="$food->stall" />
        </div>
        <x-food-price :food="$food" class="text-market fw-bold d-block mb-2" />
        <p class="text-secondary text-break">{{ str($food->description ?: 'No food description available.')->limit(150) }}</p>
        @if ($showRecommendation && $food->is_must_try && $food->recommendation_reason)
            <p class="small border-start border-warning border-3 ps-3 text-break">
                <strong>Why try it:</strong> {{ $food->recommendation_reason }}
            </p>
        @endif
        <a href="{{ route('foods.show', $food) }}" class="btn btn-market mt-auto align-self-start">View Details</a>
    </div>
</article>
