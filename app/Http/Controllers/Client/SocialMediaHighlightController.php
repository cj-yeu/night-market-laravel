<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\SocialMedia\ClientSocialMediaHighlightRequest;
use App\Services\SocialMediaDataService;
use Illuminate\View\View;

class SocialMediaHighlightController extends Controller
{
    public function __construct(private readonly SocialMediaDataService $socialMediaDataService) {}

    public function index(ClientSocialMediaHighlightRequest $request): View
    {
        $filters = $request->validated();

        return view('client.social-media-highlights.index', [
            'records' => $this->socialMediaDataService->clientHighlights($filters),
            'insights' => $this->socialMediaDataService->clientInsights($filters),
            'filters' => $filters,
        ]);
    }
}
