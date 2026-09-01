<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SocialMedia\BulkModerateSocialMediaRecordsRequest;
use App\Http\Requests\SocialMedia\SocialMediaRecordFilterRequest;
use App\Http\Requests\SocialMedia\ModerateSocialMediaRecordRequest;
use App\Http\Requests\SocialMedia\StoreSocialMediaRecordRequest;
use App\Http\Requests\SocialMedia\UpdateSocialMediaRecordRequest;
use App\Models\SocialMediaRecord;
use App\Services\SocialMediaDataService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SocialMediaRecordController extends Controller
{
    public function __construct(private readonly SocialMediaDataService $socialMediaDataService) {}

    public function index(SocialMediaRecordFilterRequest $request): View
    {
        return view('admin.social-media-records.index', [
            'records' => $this->socialMediaDataService->records($request->validated()),
            'nightMarkets' => $this->socialMediaDataService->activeSelangorMarkets(),
            'platforms' => SocialMediaRecord::PLATFORMS,
            'statuses' => SocialMediaRecord::STATUSES,
            'filters' => $request->validated(),
        ]);
    }

    public function create(): View
    {
        return view('admin.social-media-records.create', $this->socialMediaDataService->formOptions());
    }

    public function store(StoreSocialMediaRecordRequest $request): RedirectResponse
    {
        $this->socialMediaDataService->create($request->validated());

        return redirect()
            ->route('admin.social-media-records.index')
            ->with('status', 'The social media record was added successfully.');
    }

    public function edit(SocialMediaRecord $socialMediaRecord): View
    {
        return view('admin.social-media-records.edit', [
            'socialMediaRecord' => $socialMediaRecord,
            ...$this->socialMediaDataService->formOptions(),
        ]);
    }

    public function update(
        UpdateSocialMediaRecordRequest $request,
        SocialMediaRecord $socialMediaRecord,
    ): RedirectResponse {
        $this->socialMediaDataService->update($socialMediaRecord, $request->validated());

        return redirect()
            ->route('admin.social-media-records.index')
            ->with('status', 'The social media record was updated successfully.');
    }

    public function destroy(SocialMediaRecord $socialMediaRecord): RedirectResponse
    {
        $this->socialMediaDataService->delete($socialMediaRecord);

        return redirect()
            ->route('admin.social-media-records.index')
            ->with('status', 'The social media record was deleted successfully.');
    }

    public function moderate(
        ModerateSocialMediaRecordRequest $request,
        SocialMediaRecord $socialMediaRecord,
    ): RedirectResponse {
        $status = $request->validated('status');
        $this->socialMediaDataService->moderate(
            $socialMediaRecord,
            $request->user(),
            $status,
            $request->validated('rejection_reason'),
        );

        return redirect()
            ->route('admin.social-media-records.index')
            ->with(
                'status',
                $status === SocialMediaRecord::STATUS_APPROVED
                    ? 'The social media record was approved successfully.'
                    : 'The social media record was rejected successfully.',
            );
    }

    public function bulkModerate(BulkModerateSocialMediaRecordsRequest $request): RedirectResponse
    {
        $status = $request->validated('status');

        $summary = $this->socialMediaDataService->bulkModerate(
            $request->validated('ids'),
            $request->user(),
            $status,
            $request->validated('rejection_reason'),
        );

        $outcome = $status === SocialMediaRecord::STATUS_APPROVED ? 'approved' : 'rejected';

        $message = match (true) {
            $summary['moderated'] === 0 => "No social media records were {$outcome}.",
            $summary['moderated'] === 1 => "1 social media record was {$outcome} successfully.",
            default => "{$summary['moderated']} social media records were {$outcome} successfully.",
        };

        if ($summary['skipped'] !== []) {
            $message .= ' Skipped '.count($summary['skipped']).': '.implode('; ', $summary['skipped']).'.';
        }

        return redirect()
            ->route('admin.social-media-records.index')
            ->with('status', $message);
    }
}
