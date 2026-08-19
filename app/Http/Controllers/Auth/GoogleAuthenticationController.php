<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\SocialAuthenticationException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\GoogleAuthenticationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Throwable;

class GoogleAuthenticationController extends Controller
{
    public function __construct(private readonly GoogleAuthenticationService $googleAuthenticationService) {}

    public function redirect(Request $request): SymfonyRedirectResponse
    {
        $request->session()->put(GoogleAuthenticationService::SESSION_INTENT, [
            'purpose' => 'login',
        ]);

        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        $intent = $request->session()->get(GoogleAuthenticationService::SESSION_INTENT);

        if (! is_array($intent) || ! in_array($intent['purpose'] ?? null, ['login', 'link'], true)) {
            return $this->failureResponse(
                $request,
                null,
                'The Google authentication session expired. Please try again.',
            );
        }

        try {
            $providerUser = Socialite::driver('google')->user();

            if ($intent['purpose'] === 'link') {
                $user = $request->user();

                if (! $user instanceof User
                    || $user->role !== User::ROLE_CLIENT
                    || (int) ($intent['user_id'] ?? 0) !== $user->id) {
                    throw new SocialAuthenticationException(
                        'The Google connection session expired. Please try again from Profile.',
                    );
                }

                $this->googleAuthenticationService->link($user, $providerUser);
                $request->session()->forget(GoogleAuthenticationService::SESSION_INTENT);

                return redirect()
                    ->route('profile.edit')
                    ->with('status', 'Your Google account has been connected successfully.');
            }

            if ($request->user() !== null) {
                throw new SocialAuthenticationException(
                    'Google login could not be completed while another account is signed in.',
                );
            }

            $user = $this->googleAuthenticationService->login($providerUser);
            $request->session()->forget(GoogleAuthenticationService::SESSION_INTENT);
            $request->session()->regenerate();

            if (! $user->hasVerifiedEmail()) {
                return redirect()
                    ->route('verification.notice')
                    ->with('status', 'Verify your email address to continue to trusted Client features.');
            }

            return redirect()
                ->intended(route('client.home'))
                ->with('status', 'Welcome back, '.$user->name.'.');
        } catch (SocialAuthenticationException $exception) {
            return $this->failureResponse($request, $intent, $exception->getMessage());
        } catch (Throwable) {
            return $this->failureResponse(
                $request,
                $intent,
                'Google authentication could not be completed. Please try again.',
            );
        }
    }

    /**
     * @param  array<string, mixed>|null  $intent
     */
    private function failureResponse(Request $request, ?array $intent, string $message): RedirectResponse
    {
        $request->session()->forget(GoogleAuthenticationService::SESSION_INTENT);

        $route = ($intent['purpose'] ?? null) === 'link' && $request->user()?->role === User::ROLE_CLIENT
            ? 'profile.edit'
            : 'login';

        return redirect()->route($route)->with('error', $message);
    }
}
