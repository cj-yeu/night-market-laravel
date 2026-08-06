<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

class AuthService
{
    public function __construct(private readonly AuthManager $auth) {}

    /**
     * Register and authenticate a new client account.
     *
     * @param  array{name: string, email: string, password: string}  $data
     */
    public function registerClient(array $data): User
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => User::ROLE_CLIENT,
            'is_active' => true,
        ]);

        $this->guard()->login($user);

        return $user;
    }

    /**
     * Validate credentials and authenticate an active user.
     *
     * @param  array{email: string, password: string}  $credentials
     *
     * @throws ValidationException
     */
    public function login(array $credentials): User
    {
        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'Incorrect email or password. Please try again.',
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => 'Your account is inactive. Please contact the administrator.',
            ]);
        }

        $this->guard()->login($user);

        return $user;
    }

    /**
     * Resolve the named home route for an authenticated role.
     */
    public function homeRouteFor(User $user): string
    {
        return match ($user->role) {
            User::ROLE_CLIENT => 'client.home',
            User::ROLE_ADMIN => 'admin.dashboard',
            default => throw new UnexpectedValueException('The authenticated user has an unsupported role.'),
        };
    }

    /**
     * Log the current user out of the web guard.
     */
    public function logout(): void
    {
        $this->guard()->logout();
    }

    private function guard(): StatefulGuard
    {
        /** @var StatefulGuard&Guard $guard */
        $guard = $this->auth->guard('web');

        return $guard;
    }
}
