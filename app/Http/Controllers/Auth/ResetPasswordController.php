<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Services\PasswordResetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ResetPasswordController extends Controller
{
    public function __construct(private readonly PasswordResetService $passwordResetService) {}

    public function create(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->string('email')->value(),
        ]);
    }

    public function store(ResetPasswordRequest $request): RedirectResponse
    {
        $this->passwordResetService->reset($request->validated());

        return redirect()
            ->route('login')
            ->with('status', 'Your password has been reset successfully. You can now log in.');
    }
}
