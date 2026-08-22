<?php

namespace App\Services;

use App\Models\User;
use DomainException;

class SuperAdminPromotionService
{
    public function promoteExistingAdmin(string $email): User
    {
        $user = User::query()->where('email', mb_strtolower(trim($email)))->first();

        if (! $user) {
            throw new DomainException('No account was found for that email address.');
        }

        if ($user->role === User::ROLE_SUPER_ADMIN) {
            return $user;
        }

        if ($user->role !== User::ROLE_ADMIN || ! $user->is_active || ! $user->hasVerifiedEmail()) {
            throw new DomainException('Only an active, email-verified Admin account can be promoted.');
        }

        $user->forceFill(['role' => User::ROLE_SUPER_ADMIN])->save();

        return $user->refresh();
    }
}
