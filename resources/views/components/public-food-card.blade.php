@props(['food', 'showRecommendation' => false])

<article class="card market-card h-100 overflow-hidden must-try-card" data-food-id="{{ $food->id }}">
    <x-food-image :food="$food" class="must-try-card-image" />
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
        <x-review-rating-summary :reviewable="$food" compact class="mb-2" />
        <p class="text-secondary text-break text-clamp-3">{{ $food->description ?: 'No food description available.' }}</p>
        @if ($showRecommendation && $food->is_must_try && $food->recommendation_reason)
            <p class="small border-start border-warning border-3 ps-3 text-break text-clamp-3">
                <strong>Why try it:</strong> {{ $food->recommendation_reason }}
            </p>
        @endif
        <div class="d-flex flex-wrap gap-2 mt-auto">
            <a href="{{ route('foods.show', $food) }}" class="btn btn-market">View Details</a>
            <a href="{{ route('client.visit-plans.index', ['item_type' => 'food', 'item_id' => $food->id]) }}"
                class="btn btn-outline-secondary">Add to Visit Plan</a>
        </div>
    </div>
</article>
