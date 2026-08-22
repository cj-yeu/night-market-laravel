<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function __construct(private readonly AuthService $authService) {}

    /**
     * Display the login form.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an authentication attempt.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $user = $this->authService->login($request->validated(), $request->throttleKey());

        $request->session()->regenerate();

        if ($user->role === User::ROLE_CLIENT && ! $user->hasVerifiedEmail()) {
            return redirect()
                ->route('verification.notice')
                ->with('status', 'Verify your email address to continue to trusted Client features.');
        }

        return redirect()
            ->intended(route($this->authService->homeRouteFor($user)))
            ->with('status', 'Welcome back, '.$user->name.'.');
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $this->authService->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('login')
            ->with('status', 'You have been logged out successfully.');
    }
}
