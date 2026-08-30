<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SocialMedia\FetchSocialMediaMetadataRequest;
use App\Http\Requests\SocialMedia\StoreCatalogImportProposalRequest;
use App\Models\CatalogImportProposal;
use App\Models\SocialMediaSource;
use App\Services\CatalogImportProposalService;
use App\Services\SocialMediaDiscoveryService;
use App\Services\SocialMediaMetadataService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SocialMediaAutomationController extends Controller
{
    public function __construct(
        private readonly CatalogImportProposalService $catalogImportProposalService,
        private readonly SocialMediaDiscoveryService $socialMediaDiscoveryService,
        private readonly SocialMediaMetadataService $socialMediaMetadataService,
    ) {}

    public function index(): View
    {
        return view('admin.social-media-automation.index', [
            'marketGaps' => $this->socialMediaDiscoveryService->activeMarketsWithoutActiveStalls(),
            'stallGaps' => $this->socialMediaDiscoveryService->activeStallsWithoutActiveFoods(),
            'proposals' => $this->catalogImportProposalService->proposals(),
        ]);
    }

    public function create(): View
    {
        return view('admin.social-media-automation.create', $this->catalogImportProposalService->formOptions());
    }

    public function store(StoreCatalogImportProposalRequest $request): RedirectResponse
    {
        $proposal = $this->catalogImportProposalService->createDraft(
            $request->user(),
            $request->validated(),
        );
        $source = $this->socialMediaMetadataService->fetch($proposal->socialMediaSource);

        return redirect()
            ->route('admin.social-media.automation.show', $proposal)
            ->with('status', $this->socialMediaMetadataService->statusMessage($source));
    }

    public function show(CatalogImportProposal $catalogImportProposal): View
    {
        $proposal = $this->catalogImportProposalService->detail($catalogImportProposal);

        return view('admin.social-media-automation.show', [
            'proposal' => $proposal,
            'metadataIsFresh' => $this->socialMediaMetadataService->isFresh($proposal->socialMediaSource),
            'metadataFailureMessage' => $this->socialMediaMetadataService->failureMessage($proposal->socialMediaSource->failure_code),
        ]);
    }

    public function fetchMetadata(
        FetchSocialMediaMetadataRequest $request,
        SocialMediaSource $socialMediaSource,
    ): RedirectResponse {
        $source = $this->socialMediaMetadataService->fetch($socialMediaSource);

        return back()->with('status', $this->socialMediaMetadataService->statusMessage($source));
    }
}
