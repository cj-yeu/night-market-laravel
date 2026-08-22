<?php

namespace App\Http\Controllers\UserAccount;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserAccount\UpdateProfileImageRequest;
use App\Services\ProfileImageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProfileImageController extends Controller
{
    public function __construct(private readonly ProfileImageService $profileImageService) {}

    public function update(UpdateProfileImageRequest $request): RedirectResponse
    {
        $this->profileImageService->replace($request->user(), $request->file('avatar'));

        return redirect()
            ->route('profile.edit')
            ->with('status', 'Your profile image has been updated successfully.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->profileImageService->remove($request->user());

        return redirect()
            ->route('profile.edit')
            ->with('status', 'Your profile image has been removed.');
    }
}
