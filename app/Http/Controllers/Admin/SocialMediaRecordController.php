<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SocialMedia\StoreSocialMediaRecordRequest;
use App\Services\SocialMediaDataService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SocialMediaRecordController extends Controller
{
    public function __construct(private readonly SocialMediaDataService $socialMediaDataService) {}

    public function create(): View
    {
        return view('admin.social-media-records.create');
    }

    public function store(StoreSocialMediaRecordRequest $request): RedirectResponse
    {
        $this->socialMediaDataService->create($request->validated());

        return redirect()
            ->route('admin.social-media-records.create')
            ->with('status', 'The social media record was added successfully.');
    }
}
