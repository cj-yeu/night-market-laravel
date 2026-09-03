<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\SocialAuthenticationException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuthService;
use App\Services\GoogleAuthenticationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Throwable;

class GoogleAuthenticationController extends Controller
{
    public function __construct(
        private readonly GoogleAuthenticationService $googleAuthenticationService,
        private readonly AuthService $authService,
    ) {}

    public function redirect(Request $request): SymfonyRedirectResponse
    {
        $request->session()->put(GoogleAuthenticationService::SESSION_INTENT, [
            'purpose' => 'login',
        ]);

        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        $intent = $request->session()->pull(GoogleAuthenticationService::SESSION_INTENT);

        if (! is_array($intent) || ! in_array($intent['purpose'] ?? null, ['login', 'link'], true)) {
            return $this->failureResponse(
                $request,
                null,
                'The Google authentication session expired. Please try again.',
            );
        }

        $providerError = $request->query('error');

        if ($providerError !== null) {
            $cancelled = is_string($providerError) && hash_equals('access_denied', $providerError);

            Log::notice('Google OAuth callback returned a provider error.', [
                'purpose' => $intent['purpose'],
                'result' => $cancelled ? 'cancelled' : 'provider_error',
            ]);

            return $this->failureResponse(
                $request,
                $intent,
                $cancelled
                    ? 'Google sign-in was cancelled. No changes were made.'
                    : 'Google could not authorize this request. Please try again.',
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
            $request->session()->regenerate();

            return redirect()
                ->to($this->authService->postAuthenticationUrl($request, $user))
                ->with('status', 'Welcome back, '.$user->name.'.');
        } catch (SocialAuthenticationException $exception) {
            Log::notice('Google authentication was rejected by account policy.', [
                'exception_class' => $exception::class,
                'purpose' => $intent['purpose'],
                'link_user_id' => $intent['purpose'] === 'link' ? (int) ($intent['user_id'] ?? 0) : null,
            ]);

            return $this->failureResponse($request, $intent, $exception->getMessage());
        } catch (InvalidStateException $exception) {
            Log::warning('Google authentication state validation failed.', [
                'exception_class' => $exception::class,
                'purpose' => $intent['purpose'],
            ]);

            return $this->failureResponse(
                $request,
                $intent,
                'The Google authentication session is invalid or expired. Please try again.',
            );
        } catch (Throwable $exception) {
            Log::warning('Google authentication callback failed.', [
                'exception_class' => $exception::class,
                'purpose' => $intent['purpose'],
            ]);

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

        $route = ($intent['purpose'] ?? null) === 'link' && $request->user() instanceof User
            ? 'profile.edit'
            : 'login';

        return redirect()->route($route)->with('error', $message);
    }
}
