<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UserAccountService
{
    public function __construct(private readonly EmailVerificationService $emailVerificationService) {}

    /**
     * @param  array{name: string, email: string, current_password?: string|null}  $data
     * @return array{email_changed: bool, notification_sent: bool}
     */
    public function updateProfile(User $user, array $data): array
    {
        $emailChanged = ! hash_equals(mb_strtolower($user->email), $data['email']);

        if ($emailChanged && $user->googleAccount()->exists()) {
            throw ValidationException::withMessages([
                'email' => 'Disconnect Google before changing your account email address.',
            ]);
        }

        if ($emailChanged && ($user->password === null
            || ! Hash::check((string) ($data['current_password'] ?? ''), $user->password))) {
            throw ValidationException::withMessages([
                'current_password' => $user->password === null
                    ? 'Use Forgot Password to establish a local password before changing your email.'
                    : 'The current password is incorrect.',
            ]);
        }

        $user->forceFill([
            'name' => $data['name'],
            'email' => $data['email'],
            'email_verified_at' => $emailChanged ? null : $user->email_verified_at,
        ])->save();

        return [
            'email_changed' => $emailChanged,
            'notification_sent' => $emailChanged && $this->emailVerificationService->send($user),
        ];
    }

    /**
     * @param  array{current_password: string, password: string}  $data
     *
     * @throws ValidationException
     */
    public function changePassword(User $user, array $data): void
    {
        if ($user->password === null) {
            throw ValidationException::withMessages([
                'current_password' => 'Use Forgot Password to establish a local password first.',
            ]);
        }

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'The current password is incorrect.',
            ]);
        }

        $user->update([
            'password' => $data['password'],
        ]);
    }
}
