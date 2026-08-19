<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\SocialMediaExtractionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SocialMedia\ExtractSocialMediaMetadataRequest;
use App\Http\Requests\SocialMedia\StoreExtractedSocialMediaRecordRequest;
use App\Models\SocialMediaRecord;
use App\Services\SocialMediaDataService;
use App\Services\SocialMediaMetadataExtractor;
use App\Services\SocialMediaUrlPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SocialMediaExtractionController extends Controller
{
    public function __construct(
        private readonly SocialMediaMetadataExtractor $metadataExtractor,
        private readonly SocialMediaUrlPolicy $urlPolicy,
        private readonly SocialMediaDataService $socialMediaDataService,
    ) {}

    public function create(): View
    {
        return view('admin.social-media-records.extract');
    }

    public function extract(ExtractSocialMediaMetadataRequest $request): RedirectResponse
    {
        $sourceUrl = $request->validated('source_url');
        $error = null;

        try {
            $draft = $this->metadataExtractor->extract($sourceUrl);
        } catch (SocialMediaExtractionException $exception) {
            $source = $this->urlPolicy->inspectSourceUrl($sourceUrl);
            $draft = [
                'platform' => $source['platform'],
                'original_post_url' => $source['url'],
                'extraction_status' => SocialMediaRecord::EXTRACTION_FAILED,
            ];
            $error = $exception->getMessage();
        }

        return redirect()
            ->route('admin.social-media.extract.review')
            ->with('social_media_extraction_draft', $draft)
            ->with('social_media_extraction_error', $error);
    }

    public function review(): View
    {
        $draft = session('social_media_extraction_draft', []);
        $socialMediaRecord = new SocialMediaRecord([
            'likes' => 0,
            'comments' => 0,
            'shares' => 0,
            ...$draft,
        ]);

        return view('admin.social-media-records.extract-review', [
            'socialMediaRecord' => $socialMediaRecord,
            'extractionStatus' => $draft['extraction_status'] ?? SocialMediaRecord::EXTRACTION_MANUAL,
            'extractionError' => session('social_media_extraction_error'),
            ...$this->socialMediaDataService->formOptions(),
        ]);
    }

    public function store(StoreExtractedSocialMediaRecordRequest $request): RedirectResponse
    {
        $this->socialMediaDataService->create($request->validated());

        return redirect()
            ->route('admin.social-media-records.index')
            ->with('status', 'The extracted social media record was saved as pending review.');
    }
}
