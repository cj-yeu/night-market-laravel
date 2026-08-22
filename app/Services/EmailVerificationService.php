<?php

namespace App\Services;

use App\Models\User;
use Throwable;

class EmailVerificationService
{
    public function send(User $user): bool
    {
        if ($user->hasVerifiedEmail()) {
            return false;
        }

        try {
            $user->sendEmailVerificationNotification();

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
