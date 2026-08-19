<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Services\AuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function __construct(private readonly AuthService $authService) {}

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

        return redirect()
            ->intended(route($this->authService->homeRouteFor($user)))
            ->with('status', 'Your client account has been created successfully.');
    }
}
