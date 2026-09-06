<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CatalogAiImportRequest;
use App\Models\CatalogImportProposal;
use App\Services\CatalogAiImportService;
use App\Services\CatalogDraftImageStorage;
use App\Services\CatalogImportProposalService;
use App\Services\CatalogSourceSearchService;

class CatalogAiImportController extends Controller
{
    public function __construct(private readonly CatalogAiImportService $workflow, private readonly CatalogImportProposalService $proposals) {}

    public function index(CatalogAiImportRequest $request, CatalogSourceSearchService $search)
    {
        $result = $this->workflow->results($request->user(), $request->validated('search_id'));

        return view('admin.ai-import.index', [...$this->proposals->formOptions(), 'result' => $result, 'searchStatus' => $search->status(),
            'context' => $result['context'] ?? $this->workflow->context($request->validated()),
            'searchId' => $request->validated('search_id'), 'searchExpired' => $request->filled('search_id') && ! $result]);
    }

    public function search(CatalogAiImportRequest $request)
    {
        $id = $this->workflow->search($request->user(), $request->validated());

        return redirect()->route('admin.ai-import.index', ['search_id' => $id], 303);
    }

    public function start(CatalogAiImportRequest $request)
    {
        $proposal = $this->workflow->start($request->user(), $request->validated());

        return redirect()->route('admin.ai-import.show', $proposal, 303);
    }

    public function history(CatalogAiImportRequest $request)
    {
        return view('admin.ai-import.history', ['proposals' => $this->proposals->proposals()]);
    }

    public function show(CatalogAiImportRequest $request, CatalogImportProposal $proposal)
    {
        if (! $this->workflow->data($proposal)) {
            return app(SocialMediaAutomationController::class)->show($proposal);
        }

        return view('admin.ai-import.draft', [...$this->proposals->formOptions(), 'proposal' => $proposal,
            'review' => $this->workflow->review($proposal), 'revision' => $this->workflow->revision($proposal)]);
    }

    public function analyse(CatalogAiImportRequest $request, CatalogImportProposal $proposal)
    {
        $this->workflow->analyse($proposal, $request->validated(), $request->file('screenshot'));

        return redirect()->route('admin.ai-import.show', $proposal, 303)->with('status', 'Source analysis saved. Confirm ownership, photos and prices before import.');
    }

    public function update(CatalogAiImportRequest $request, CatalogImportProposal $proposal)
    {
        $this->workflow->saveDraft($proposal, $request->validated());

        return redirect()->route('admin.ai-import.show', $proposal, 303)->with('status', 'Draft and selections saved. No catalog records changed.');
    }

    public function review(CatalogAiImportRequest $request, CatalogImportProposal $proposal)
    {
        return view('admin.ai-import.review', ['proposal' => $proposal, 'review' => $this->workflow->review($proposal), 'revision' => $this->workflow->revision($proposal)]);
    }

    public function import(CatalogAiImportRequest $request, CatalogImportProposal $proposal)
    {
        $result = $this->workflow->import($request->user(), $proposal, $request->validated());

        return redirect()->route($result['stall_id'] ? 'admin.stalls.show' : 'admin.night-markets.show', $result['stall_id'] ?: $result['market_id'], 303)
            ->with('status', "Imported {$result['counts']['markets']} Markets, {$result['counts']['stalls']} Stalls and {$result['counts']['foods']} Foods. New records are inactive.")
            ->with('ai_import_history', route('admin.ai-import.show', $proposal));
    }

    public function image(CatalogAiImportRequest $request, CatalogImportProposal $proposal, int $stall, int $food)
    {
        $path = $this->workflow->data($proposal)['graph']['stalls'][$stall]['foods'][$food]['image_path'] ?? null;
        $disk = app(CatalogDraftImageStorage::class)->disk();
        abort_unless($path && str_starts_with($path, 'ai-import/'.$proposal->id.'/') && $disk->exists($path), 404);

        return response()->file($disk->path($path), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function candidateImage(CatalogAiImportRequest $request, CatalogImportProposal $proposal, int $source, int $image)
    {
        $photo = $this->workflow->candidateImage($proposal, $source, $image);

        return response($photo['body'], 200, ['Content-Type' => $photo['mime'], 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
