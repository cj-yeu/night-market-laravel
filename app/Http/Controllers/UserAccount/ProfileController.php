<?php

namespace App\Http\Controllers\UserAccount;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserAccount\ChangePasswordRequest;
use App\Http\Requests\UserAccount\UpdateProfileRequest;
use App\Models\User;
use App\Services\ReviewService;
use App\Services\UserAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function __construct(
        private readonly UserAccountService $userAccountService,
        private readonly ReviewService $reviewService,
    ) {}

    public function edit(Request $request): View
    {
        $user = $request->user()->load('googleAccount');

        return view('profile.edit', [
            'user' => $user,
            ...($user->role === User::ROLE_CLIENT ? $this->reviewService->reviewsForProfile($user) : ['marketReviews' => collect(), 'foodReviews' => collect()]),
        ]);
    }

    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        $result = $this->userAccountService->updateProfile($request->user(), $request->validated());

        if ($result['email_changed']) {
            return redirect()
                ->route('verification.notice')
                ->with(
                    $result['notification_sent'] ? 'status' : 'error',
                    $result['notification_sent']
                        ? 'Your email address was updated. Check the new address for a verification link.'
                        : 'Your email address was updated, but the verification email could not be sent. Please resend it.',
                );
        }

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
