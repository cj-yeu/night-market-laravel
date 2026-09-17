<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CatalogAiImportRequest;
use App\Models\CatalogImportProposal;
use App\Services\CatalogAiImportService;
use App\Services\CatalogDraftImageStorage;
use App\Services\CatalogImportProposalService;
use App\Services\CatalogSourceSearchService;
use Illuminate\Support\Facades\Log;

class CatalogAiImportController extends Controller
{
    public function __construct(private readonly CatalogAiImportService $workflow, private readonly CatalogImportProposalService $proposals) {}

    public function index(CatalogAiImportRequest $request, CatalogSourceSearchService $search)
    {
        $result = $this->workflow->results($request->user(), $request->validated('search_id'));

        $context = $result['context'] ?? $this->workflow->context($request->validated());

        return view('admin.ai-import.index', [...$this->proposals->formOptions(), 'result' => $result, 'searchStatus' => $search->status(),
            'context' => $context, 'unfinishedDraft' => $this->workflow->unfinishedDraft($context),
            'searchId' => $request->validated('search_id'), 'searchExpired' => $request->filled('search_id') && ! $result]);
    }

    public function search(CatalogAiImportRequest $request)
    {
        $id = $this->workflow->search($request->user(), $request->validated());

        return redirect()->route('admin.ai-import.index', ['search_id' => $id], 303)->withFragment('sources-heading');
    }

    public function start(CatalogAiImportRequest $request)
    {
        $proposal = $this->workflow->start($request->user(), $request->validated());

        return redirect()->route('admin.ai-import.show', $proposal, 303);
    }

    public function history(CatalogAiImportRequest $request)
    {
        $filters = $request->safe()->only(['status', 'draft_search', 'draft_type', 'draft_condition', 'draft_sort']);

        return view('admin.ai-import.history', ['proposals' => $this->proposals->proposals($filters), 'filters' => $filters]);
    }

    public function prepare(CatalogAiImportRequest $request)
    {
        $prepared = $this->workflow->prepare($request->user(), $request->validated(), $request->file('screenshot'));

        $response = redirect()->route('admin.ai-import.review', $prepared['proposal'], 303);

        return $prepared['errors'] ? $response->withErrors($prepared['errors']) : $response;
    }

    public function show(CatalogAiImportRequest $request, CatalogImportProposal $proposal)
    {
        if ($proposal->status === CatalogImportProposal::STATUS_IMPORTED
            && isset($this->workflow->data($proposal)['import_result'])) {
            return redirect()->route('admin.ai-import.success', $proposal);
        }

        $snapshot = $proposal->review_metadata_snapshot;
        if (! is_array($snapshot) || (! array_key_exists('ai_import', $snapshot) && ! array_key_exists('ai_import_last_good', $snapshot))) {
            return app(SocialMediaAutomationController::class)->show($proposal);
        }

        return $this->draftView($request, $proposal);
    }

    public function analyse(CatalogAiImportRequest $request, CatalogImportProposal $proposal)
    {
        $this->workflow->analyse($proposal, $request->validated(), $request->file('screenshot'));

        return redirect()->route('admin.ai-import.review', $proposal, 303)->with('status', 'Source analysis saved. Confirm ownership, photos and prices before import.');
    }

    public function update(CatalogAiImportRequest $request, CatalogImportProposal $proposal)
    {
        $this->workflow->saveDraft($proposal, $request->validated());

        return redirect()->route('admin.ai-import.show', $proposal, 303)->with('status', 'Draft and selections saved. No catalog records changed.');
    }

    public function review(CatalogAiImportRequest $request, CatalogImportProposal $proposal)
    {
        if ($proposal->status === 'imported') {
            return redirect()->route('admin.ai-import.success', $proposal);
        }

        return $this->draftView($request, $proposal);
    }

    public function rename(CatalogAiImportRequest $request, CatalogImportProposal $proposal)
    {
        $this->workflow->rename($proposal, (string) $request->validated('draft_name'));

        return back()->with('status', 'Draft name updated.');
    }

    public function archive(CatalogAiImportRequest $request, CatalogImportProposal $proposal)
    {
        $this->workflow->archive($proposal);

        return redirect()->route('admin.ai-import.history', ['status' => 'archived'])->with('status', 'Draft archived.');
    }

    public function restore(CatalogAiImportRequest $request, CatalogImportProposal $proposal)
    {
        $this->workflow->restoreLastGood($proposal);

        return redirect()->route('admin.ai-import.show', $proposal)->with('status', 'The previous valid draft version was restored.');
    }

    public function resetExtracted(CatalogAiImportRequest $request, CatalogImportProposal $proposal)
    {
        $this->workflow->resetExtractedRecords($proposal);

        return redirect()->route('admin.ai-import.show', $proposal)->with('status', 'Invalid extracted Stall and Food records were reset. Market identity and sources were retained.');
    }

    public function removeInvalid(CatalogAiImportRequest $request, CatalogImportProposal $proposal)
    {
        $this->workflow->removeInvalidItem($proposal, (string) $request->validated('repair_item'), $request->validated('revision'));

        return redirect()->route('admin.ai-import.show', $proposal)->with('status', 'The invalid draft item was removed. Other saved work was retained.');
    }

    public function destroy(CatalogAiImportRequest $request, CatalogImportProposal $proposal)
    {
        $this->workflow->deleteDraft($proposal);

        return redirect()->route('admin.ai-import.history')->with('status', 'Draft deleted. Source history and catalog records were not deleted.');
    }

    private function draftView(CatalogAiImportRequest $request, CatalogImportProposal $proposal)
    {
        try {
            return view('admin.ai-import.draft', [...$this->proposals->formOptions(), 'proposal' => $proposal,
                'review' => $this->workflow->review($proposal), 'revision' => $this->workflow->revision($proposal)]);
        } catch (\Throwable $exception) {
            Log::error('Catalog AI Import draft could not be rendered.', [
                'draft_id' => $proposal->id, 'admin_id' => $request->user()?->id, 'request_url' => $request->fullUrl(),
                'invalid_items' => $this->workflow->repairDiagnostics($proposal),
                'exception' => $exception::class, 'message' => $exception->getMessage(),
            ]);

            return response()->view('admin.ai-import.recovery', [
                'proposal' => $proposal, 'hasLastGoodVersion' => $this->workflow->hasLastGoodVersion($proposal),
            ], 422);
        }
    }

    public function complete(CatalogAiImportRequest $request, CatalogImportProposal $proposal)
    {
        $result = $this->workflow->complete($request->user(), $proposal, $request->validated());

        return redirect()->route($result ? 'admin.ai-import.success' : 'admin.ai-import.review', $proposal, 303)
            ->with('status', $result ? 'Import complete. New records are inactive.' : 'Saved for later. No catalog records were created.');
    }

    public function success(CatalogAiImportRequest $request, CatalogImportProposal $proposal)
    {
        $result = $this->workflow->data($proposal)['import_result'] ?? null;
        abort_unless($proposal->status === 'imported' && $result, 404);

        return view('admin.ai-import.success', compact('proposal', 'result'));
    }

    public function destroyEmpty(CatalogAiImportRequest $request, CatalogImportProposal $proposal)
    {
        $this->workflow->deleteEmpty($proposal);

        return redirect()->route('admin.ai-import.history', ['status' => 'active'], 303)->with('status', 'Unused empty draft removed. Source history was retained.');
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
