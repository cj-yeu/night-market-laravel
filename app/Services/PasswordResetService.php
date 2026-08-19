<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Passwords\PasswordBrokerManager;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordResetService
{
    public function __construct(private readonly PasswordBrokerManager $passwords) {}

    /**
     * Request a broker-managed reset link without exposing account existence or status.
     *
     * @param  array{email: string}  $credentials
     */
    public function sendResetLink(array $credentials): void
    {
        $this->passwords->broker()->sendResetLink($credentials);
    }

    /**
     * Reset a password through Laravel's password broker.
     *
     * @param  array{token: string, email: string, password: string, password_confirmation: string}  $credentials
     *
     * @throws ValidationException
     */
    public function reset(array $credentials): void
    {
        $status = $this->passwords->broker()->reset(
            $credentials,
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            },
        );

        if ($status !== $this->passwords->broker()::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => 'The password reset link is invalid or has expired.',
            ]);
        }
    }
}
