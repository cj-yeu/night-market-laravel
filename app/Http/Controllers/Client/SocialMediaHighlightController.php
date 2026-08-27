<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\SocialMedia\PublicSocialMediaHighlightRequest;
use App\Models\SocialMediaRecord;
use App\Services\SocialMediaDataService;
use Illuminate\View\View;

class SocialMediaHighlightController extends Controller
{
    public function __construct(private readonly SocialMediaDataService $socialMediaDataService) {}

    public function index(PublicSocialMediaHighlightRequest $request): View
    {
        $filters = $request->validated();

        return view('client.social-media-highlights.index', [
            'records' => $this->socialMediaDataService->publicHighlights($filters),
            'insights' => $this->socialMediaDataService->publicInsights($filters),
            'popularHashtags' => $this->socialMediaDataService->popularHashtags($filters),
            'nightMarkets' => $this->socialMediaDataService->highlightedMarkets(),
            'platforms' => SocialMediaRecord::PLATFORMS,
            'sorts' => SocialMediaDataService::PUBLIC_SORTS,
            'filters' => $filters,
        ]);
    }
}
