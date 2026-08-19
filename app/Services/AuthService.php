<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\AuthManager;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

class AuthService
{
    private const LOGIN_MAX_ATTEMPTS = 3;

    private const LOGIN_DECAY_SECONDS = 900;

    public function __construct(
        private readonly AuthManager $auth,
        private readonly RateLimiter $limiter,
    ) {}

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
    public function login(array $credentials, string $limiterKey): User
    {
        if ($this->limiter->tooManyAttempts($limiterKey, self::LOGIN_MAX_ATTEMPTS)) {
            $this->throwCooldownException($limiterKey);
        }

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password) || ! $user->is_active) {
            $this->limiter->hit($limiterKey, self::LOGIN_DECAY_SECONDS);

            if ($this->limiter->attempts($limiterKey) >= self::LOGIN_MAX_ATTEMPTS) {
                $this->throwCooldownException($limiterKey);
            }

            $remainingAttempts = self::LOGIN_MAX_ATTEMPTS - $this->limiter->attempts($limiterKey);
            $attemptLabel = $remainingAttempts === 1 ? 'attempt' : 'attempts';

            throw ValidationException::withMessages([
                'email' => "The provided credentials are incorrect. You have {$remainingAttempts} {$attemptLabel} remaining.",
            ]);
        }

        $this->limiter->clear($limiterKey);
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

    /**
     * @throws ValidationException
     */
    private function throwCooldownException(string $limiterKey): never
    {
        $minutes = max(1, (int) ceil($this->limiter->availableIn($limiterKey) / 60));
        $unit = $minutes === 1 ? 'minute' : 'minutes';

        throw ValidationException::withMessages([
            'email' => "Too many unsuccessful login attempts. Please try again in {$minutes} {$unit}.",
        ]);
    }
}
