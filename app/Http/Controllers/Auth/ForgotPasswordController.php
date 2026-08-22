<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Services\PasswordResetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ForgotPasswordController extends Controller
{
    public function __construct(private readonly PasswordResetService $passwordResetService) {}

    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(ForgotPasswordRequest $request): RedirectResponse
    {
        $this->passwordResetService->sendResetLink($request->validated());

        return back()->with(
            'status',
            'If an account exists for this email address, a password reset link has been sent.',
        );
    }
}
