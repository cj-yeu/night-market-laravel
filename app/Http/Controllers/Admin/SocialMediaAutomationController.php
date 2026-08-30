<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SocialMedia\StoreCatalogImportProposalRequest;
use App\Models\CatalogImportProposal;
use App\Services\CatalogImportProposalService;
use App\Services\SocialMediaDiscoveryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SocialMediaAutomationController extends Controller
{
    public function __construct(
        private readonly CatalogImportProposalService $catalogImportProposalService,
        private readonly SocialMediaDiscoveryService $socialMediaDiscoveryService,
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

        return redirect()
            ->route('admin.social-media.automation.show', $proposal)
            ->with('status', 'The automation import draft was saved. No catalog records were created.');
    }

    public function show(CatalogImportProposal $catalogImportProposal): View
    {
        return view('admin.social-media-automation.show', [
            'proposal' => $this->catalogImportProposalService->detail($catalogImportProposal),
        ]);
    }
}
