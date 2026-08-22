<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Services\AuthService;
use App\Services\EmailVerificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly EmailVerificationService $emailVerificationService,
    ) {}

    /**
     * Display the registration form.
     */
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Handle a client registration request.
     */
    public function store(RegisterRequest $request): RedirectResponse
    {
        $user = $this->authService->registerClient($request->validated());

        $request->session()->regenerate();

        $sent = $this->emailVerificationService->send($user);

        return redirect()
            ->route('verification.notice')
            ->with(
                $sent ? 'status' : 'error',
                $sent
                    ? 'Your client account has been created. Check your email for the verification link.'
                    : 'Your client account has been created, but the verification email could not be sent. Please resend it.',
            );
    }
}
