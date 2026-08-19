<?php

namespace App\Http\Controllers\UserAccount;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserAccount\ChangePasswordRequest;
use App\Http\Requests\UserAccount\UpdateProfileRequest;
use App\Services\UserAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function __construct(private readonly UserAccountService $userAccountService) {}

    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user()->load('googleAccount'),
        ]);
    }

    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        $this->userAccountService->updateProfile($request->user(), $request->validated());

        return redirect()
            ->route('profile.edit')
            ->with('status', 'Your profile has been updated successfully.');
    }

    public function editPassword(): View
    {
        return view('profile.change-password');
    }

    public function updatePassword(ChangePasswordRequest $request): RedirectResponse
    {
        $this->userAccountService->changePassword($request->user(), $request->validated());

        return redirect()
            ->route('profile.password.edit')
            ->with('status', 'Your password has been changed successfully.');
    }
}
