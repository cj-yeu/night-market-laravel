<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Log;
use Throwable;

class EmailVerificationService
{
    public function sendForRegistration(User $user): bool
    {
        return $this->deliver($user, function () use ($user): void {
            event(new Registered($user));
        }, 'registration_verification');
    }

    public function send(User $user): bool
    {
        return $this->deliver($user, function () use ($user): void {
            $user->sendEmailVerificationNotification();
        }, 'email_verification');
    }

    private function deliver(User $user, callable $send, string $operation): bool
    {
        if ($user->hasVerifiedEmail()) {
            return false;
        }

        if (app()->environment('production') && config('mail.default') === 'log') {
            Log::warning('Production email delivery is unavailable because the log mailer is configured.', [
                'operation' => $operation,
                'user_id' => $user->id,
            ]);

            return false;
        }

        try {
            $send();

            return true;
        } catch (Throwable $exception) {
            Log::warning('Email delivery failed.', [
                'operation' => $operation,
                'user_id' => $user->id,
                'exception_class' => $exception::class,
            ]);

            return false;
        }
    }
}
