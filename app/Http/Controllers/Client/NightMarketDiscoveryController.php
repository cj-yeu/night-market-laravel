<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\NightMarket\MarketDiscoveryRequest;
use App\Models\NightMarket;
use App\Models\User;
use App\Services\NightMarketService;
use App\Services\ReviewService;
use App\Services\StallFoodService;
use Illuminate\View\View;

class NightMarketDiscoveryController extends Controller
{
    public function __construct(
        private readonly NightMarketService $nightMarketService,
        private readonly StallFoodService $stallFoodService,
        private readonly ReviewService $reviewService,
    ) {}

    public function home(): View
    {
        return view('welcome', [
            'featuredNightMarkets' => $this->nightMarketService->featuredPublicMarkets(),
            'mustTryFoods' => $this->stallFoodService->featuredMustTryFoods(),
        ]);
    }

    public function index(MarketDiscoveryRequest $request): View
    {
        $filters = $request->validated();

        return view('client.night-markets.index', [
            'nightMarkets' => $this->nightMarketService->discoverPublicMarkets($filters),
            'cities' => $this->nightMarketService->publicCities(),
            'operatingDays' => $this->nightMarketService->operatingDayOptions(),
            'filters' => $filters,
        ]);
    }

    public function show(int $nightMarket): View
    {
        $nightMarket = $this->nightMarketService->findPubliclyVisible($nightMarket);

        return view('client.night-markets.show', [
            'nightMarket' => $nightMarket,
            'activeStalls' => $nightMarket->stalls,
            'mustTryFoods' => $this->nightMarketService->mustTryFoods($nightMarket),
            ...$this->reviewContextForMarket($nightMarket),
        ]);
    }

    /** @return array<string, mixed> */
    private function reviewContextForMarket(NightMarket $nightMarket): array
    {
        $viewer = request()->user();
        $summary = $this->reviewService->publicSummaryForMarket($nightMarket, $viewer);
        $summary['reviewActions'] = match (true) {
            $viewer === null => [['label' => 'Log in to Review', 'url' => route('login')], ['label' => 'Register to Review', 'url' => route('register')]],
            $viewer->role !== User::ROLE_CLIENT => [],
            ! $viewer->hasVerifiedEmail() => [['label' => 'Verify Email to Review', 'url' => route('verification.notice')]],
            $summary['viewerReview'] !== null => [['label' => 'Edit My Market Review', 'url' => route('client.night-markets.reviews.edit', [$nightMarket, $summary['viewerReview']])]],
            default => [['label' => 'Write a Market Review', 'url' => route('client.night-markets.reviews.create', $nightMarket)]],
        };

        return $summary;
    }
}
