@props(['stall'])

<article class="card market-card h-100 overflow-hidden" data-stall-id="{{ $stall->id }}">
    <x-stall-image :stall="$stall" />
    <div class="card-body p-4 d-flex flex-column">
        <h2 class="h4 fw-bold text-break">{{ $stall->name }}</h2>
        <p class="small text-market fw-semibold mb-2">
            <a href="{{ route('night-markets.show', $stall->nightMarket) }}" class="text-reset">
                {{ $stall->nightMarket->name }}
            </a>
            <span class="text-secondary">· {{ $stall->nightMarket->city }}</span>
        </p>
        <div class="d-flex flex-wrap gap-2 mb-3">
            @if ($stall->category)
                <span class="badge text-bg-light border text-break">{{ $stall->categoryLabel() }}</span>
            @endif
            <x-halal-status :stall="$stall" />
        </div>
        <p class="text-secondary text-break">{{ str($stall->description ?: 'No stall description available.')->limit(150) }}</p>
        <div class="d-flex flex-wrap gap-2 mt-auto">
            <a href="{{ route('foods.index', ['stall_id' => $stall->id, 'night_market_id' => $stall->night_market_id]) }}" class="btn btn-market">Browse Foods</a>
            <a href="{{ route('client.visit-plans.index', ['item_type' => 'stall', 'item_id' => $stall->id]) }}"
                class="btn btn-outline-secondary">Add to Visit Plan</a>
        </div>
    </div>
</article>
