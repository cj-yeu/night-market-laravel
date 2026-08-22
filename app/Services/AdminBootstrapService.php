<?php

namespace App\Services;

use App\Models\User;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Hash;

class AdminBootstrapService
{
    /**
     * Create a verified, active administrator without changing any existing account.
     *
     * @param  array{name: string, email: string, password: string}  $data
     *
     * @throws DomainException
     */
    public function create(array $data): User
    {
        $email = mb_strtolower(trim($data['email']));

        if (User::query()->where('email', $email)->exists()) {
            throw new DomainException('An account already exists for that email address. No account was changed.');
        }

        try {
            $admin = new User([
                'name' => trim($data['name']),
                'email' => $email,
                'password' => Hash::make($data['password']),
                'role' => User::ROLE_ADMIN,
                'is_active' => true,
            ]);
            $admin->email_verified_at = now();
            $admin->save();

            return $admin;
        } catch (UniqueConstraintViolationException) {
            throw new DomainException('An account already exists for that email address. No account was changed.');
        }
    }
}
