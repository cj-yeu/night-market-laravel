<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\SocialMedia\PublicSocialMediaHighlightRequest;
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
            'filters' => $filters,
        ]);
    }
}
