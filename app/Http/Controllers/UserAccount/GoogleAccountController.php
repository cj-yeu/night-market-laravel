<?php

namespace App\Http\Controllers\UserAccount;

use App\Exceptions\SocialAuthenticationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\UserAccount\ConnectGoogleAccountRequest;
use App\Http\Requests\UserAccount\DisconnectGoogleAccountRequest;
use App\Services\GoogleAuthenticationService;
use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

class GoogleAccountController extends Controller
{
    public function __construct(private readonly GoogleAuthenticationService $googleAuthenticationService) {}

    public function store(ConnectGoogleAccountRequest $request): SymfonyRedirectResponse|RedirectResponse
    {
        try {
            $this->googleAuthenticationService->confirmLinkStart(
                $request->user(),
                $request->validated('current_password'),
            );
        } catch (SocialAuthenticationException $exception) {
            return redirect()
                ->route('profile.edit')
                ->withErrors(['current_password' => $exception->getMessage()]);
        }

        $request->session()->put(GoogleAuthenticationService::SESSION_INTENT, [
            'purpose' => 'link',
            'user_id' => $request->user()->id,
        ]);

        return Socialite::driver('google')->redirect();
    }

    public function destroy(DisconnectGoogleAccountRequest $request): RedirectResponse
    {
        try {
            $disconnected = $this->googleAuthenticationService->disconnect(
                $request->user(),
                $request->validated('current_password'),
            );
        } catch (SocialAuthenticationException $exception) {
            return redirect()
                ->route('profile.edit')
                ->withErrors(['current_password' => $exception->getMessage()]);
        }

        return redirect()
            ->route('profile.edit')
            ->with(
                'status',
                $disconnected
                    ? 'Your Google account has been disconnected.'
                    : 'No Google account was connected.',
            );
    }
}
