<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuthService;
use App\Services\EmailVerificationService;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmailVerificationController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly EmailVerificationService $emailVerificationService,
    ) {}

    public function notice(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->is_active, 403);

        if ($user->hasVerifiedEmail()) {
            return redirect()->intended(route($this->authService->homeRouteFor($user)));
        }

        return view('auth.verify-email', ['user' => $user]);
    }

    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        abort_unless($request->user()->is_active, 403);

        $request->fulfill();

        return redirect()
            ->intended(route($this->authService->homeRouteFor($request->user())))
            ->with('status', 'Your email address has been verified successfully.');
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->is_active, 403);

        if ($user->hasVerifiedEmail()) {
            return redirect()
                ->route('verification.notice')
                ->with('status', 'Your email address is already verified.');
        }

        $sent = $this->emailVerificationService->send($user);

        return redirect()
            ->route('verification.notice')
            ->with(
                $sent ? 'status' : 'error',
                $sent
                    ? 'A new verification email has been sent to your current email address.'
                    : 'The verification email could not be sent. Please try again later.',
            );
    }
}
