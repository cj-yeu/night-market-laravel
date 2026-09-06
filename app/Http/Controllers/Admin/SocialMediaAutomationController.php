<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SocialMedia\ApproveCatalogImportProposalRequest;
use App\Http\Requests\SocialMedia\DeleteCatalogSuggestionRequest;
use App\Http\Requests\SocialMedia\FetchSocialMediaMetadataRequest;
use App\Http\Requests\SocialMedia\GenerateCatalogSuggestionsRequest;
use App\Http\Requests\SocialMedia\RejectCatalogImportProposalRequest;
use App\Http\Requests\SocialMedia\StoreCatalogImportProposalRequest;
use App\Http\Requests\SocialMedia\SubmitCatalogImportProposalRequest;
use App\Http\Requests\SocialMedia\UpdateCatalogSuggestionFoodRequest;
use App\Http\Requests\SocialMedia\UpdateCatalogSuggestionMarketRequest;
use App\Http\Requests\SocialMedia\UpdateCatalogSuggestionOperatingDayRequest;
use App\Http\Requests\SocialMedia\UpdateCatalogSuggestionStallRequest;
use App\Models\CatalogImportProposal;
use App\Models\CatalogImportProposalFood;
use App\Models\CatalogImportProposalMarket;
use App\Models\CatalogImportProposalOperatingDay;
use App\Models\CatalogImportProposalStall;
use App\Services\CatalogImportProposalImportService;
use App\Services\CatalogImportProposalService;
use App\Services\CatalogSuggestionExtractionService;
use App\Services\SocialMediaDiscoveryService;
use App\Services\SocialMediaMetadataService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SocialMediaAutomationController extends Controller
{
    public function __construct(
        private readonly CatalogImportProposalService $catalogImportProposalService,
        private readonly CatalogImportProposalImportService $catalogImportProposalImportService,
        private readonly SocialMediaDiscoveryService $socialMediaDiscoveryService,
        private readonly SocialMediaMetadataService $socialMediaMetadataService,
        private readonly CatalogSuggestionExtractionService $catalogSuggestionExtractionService,
    ) {}

    public function index(): RedirectResponse
    {
        return redirect()->route('admin.ai-import.history');
    }

    public function create(): RedirectResponse
    {
        session()->keep(['errors', '_old_input', 'status']);

        return redirect()->route('admin.ai-import.index');
    }

    public function store(StoreCatalogImportProposalRequest $request): RedirectResponse
    {
        $proposal = $this->catalogImportProposalService->createDraft(
            $request->user(),
            $request->validated(),
        );
        $source = $this->socialMediaMetadataService->fetch($proposal);

        return redirect()
            ->route('admin.ai-import.show', $proposal)
            ->with('status', $this->socialMediaMetadataService->statusMessage($source));
    }

    public function show(CatalogImportProposal $catalogImportProposal): View
    {
        $proposal = $this->catalogImportProposalService->detail($catalogImportProposal);

        return view('admin.social-media-automation.show', [
            'proposal' => $proposal,
            'displayMetadata' => $this->catalogImportProposalService->metadataForDisplay($proposal),
            'metadataIsFresh' => $this->socialMediaMetadataService->isFresh($proposal->socialMediaSource),
            'metadataFailureMessage' => $this->socialMediaMetadataService->failureMessage($proposal->socialMediaSource->failure_code),
            'extractionFailureMessage' => $this->catalogSuggestionExtractionService->failureMessage($proposal->extraction_failure_code),
            'importFailureMessage' => $this->catalogImportProposalImportService->failureMessage($proposal->failure_code),
        ]);
    }

    public function redirectToDraft(CatalogImportProposal $catalogImportProposal): RedirectResponse
    {
        session()->keep(['errors', '_old_input', 'status']);

        return redirect()->route('admin.ai-import.show', $catalogImportProposal);
    }

    public function fetchMetadata(
        FetchSocialMediaMetadataRequest $request,
        CatalogImportProposal $catalogImportProposal,
    ): RedirectResponse {
        $source = $this->socialMediaMetadataService->fetch($catalogImportProposal);

        return back()->with('status', $this->socialMediaMetadataService->statusMessage($source));
    }

    public function generateSuggestions(
        GenerateCatalogSuggestionsRequest $request,
        CatalogImportProposal $catalogImportProposal,
    ): RedirectResponse {
        $result = $this->catalogSuggestionExtractionService->generate($catalogImportProposal);

        return back()->with('status', $this->catalogSuggestionExtractionService->statusMessage($result));
    }

    public function submit(
        SubmitCatalogImportProposalRequest $request,
        CatalogImportProposal $catalogImportProposal,
    ): RedirectResponse {
        $this->catalogImportProposalImportService->submit($catalogImportProposal);

        return back()->with('status', 'The proposal was submitted for Admin review. No catalog records were created.');
    }

    public function reject(
        RejectCatalogImportProposalRequest $request,
        CatalogImportProposal $catalogImportProposal,
    ): RedirectResponse {
        $this->catalogImportProposalImportService->reject(
            $request->user(),
            $catalogImportProposal,
            $request->validated('review_note'),
        );

        return back()->with('status', 'The submitted proposal was rejected. Its draft suggestions were preserved for review.');
    }

    public function approveAndImport(
        ApproveCatalogImportProposalRequest $request,
        CatalogImportProposal $catalogImportProposal,
    ): RedirectResponse {
        $result = $this->catalogImportProposalImportService->approveAndImport($request->user(), $catalogImportProposal);

        return back()->with('status', $result->wasAlreadyImported
            ? 'This proposal was already imported. No duplicate catalog records were created.'
            : 'The proposal was approved and imported transactionally. New catalog records remain inactive until normal Admin activation.');
    }

    public function updateSuggestionMarket(
        UpdateCatalogSuggestionMarketRequest $request,
        CatalogImportProposal $catalogImportProposal,
        CatalogImportProposalMarket $proposalMarket,
    ): RedirectResponse {
        $this->catalogSuggestionExtractionService->updateMarket(
            $catalogImportProposal,
            $proposalMarket,
            $request->validated(),
        );

        return back()->with('status', 'The draft Market suggestion was updated. No catalog records were changed.');
    }

    public function updateSuggestionOperatingDay(
        UpdateCatalogSuggestionOperatingDayRequest $request,
        CatalogImportProposal $catalogImportProposal,
        CatalogImportProposalOperatingDay $proposalOperatingDay,
    ): RedirectResponse {
        $this->catalogSuggestionExtractionService->updateOperatingDay(
            $catalogImportProposal,
            $proposalOperatingDay,
            $request->validated(),
        );

        return back()->with('status', 'The draft operating-day suggestion was updated.');
    }

    public function updateSuggestionStall(
        UpdateCatalogSuggestionStallRequest $request,
        CatalogImportProposal $catalogImportProposal,
        CatalogImportProposalStall $proposalStall,
    ): RedirectResponse {
        $this->catalogSuggestionExtractionService->updateStall(
            $catalogImportProposal,
            $proposalStall,
            $request->validated(),
        );

        return back()->with('status', 'The draft Stall suggestion was updated.');
    }

    public function updateSuggestionFood(
        UpdateCatalogSuggestionFoodRequest $request,
        CatalogImportProposal $catalogImportProposal,
        CatalogImportProposalFood $proposalFood,
    ): RedirectResponse {
        $this->catalogSuggestionExtractionService->updateFood(
            $catalogImportProposal,
            $proposalFood,
            $request->validated(),
        );

        return back()->with('status', 'The draft Food suggestion was updated.');
    }

    public function destroySuggestionOperatingDay(
        DeleteCatalogSuggestionRequest $request,
        CatalogImportProposal $catalogImportProposal,
        CatalogImportProposalOperatingDay $proposalOperatingDay,
    ): RedirectResponse {
        $this->catalogSuggestionExtractionService->deleteOperatingDay($catalogImportProposal, $proposalOperatingDay);

        return back()->with('status', 'The draft operating-day suggestion was removed.');
    }

    public function destroySuggestionStall(
        DeleteCatalogSuggestionRequest $request,
        CatalogImportProposal $catalogImportProposal,
        CatalogImportProposalStall $proposalStall,
    ): RedirectResponse {
        $this->catalogSuggestionExtractionService->deleteStall($catalogImportProposal, $proposalStall);

        return back()->with('status', 'The draft Stall suggestion was removed.');
    }

    public function destroySuggestionFood(
        DeleteCatalogSuggestionRequest $request,
        CatalogImportProposal $catalogImportProposal,
        CatalogImportProposalFood $proposalFood,
    ): RedirectResponse {
        $this->catalogSuggestionExtractionService->deleteFood($catalogImportProposal, $proposalFood);

        return back()->with('status', 'The draft Food suggestion was removed.');
    }
}
