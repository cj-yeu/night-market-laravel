<?php

namespace App\Console\Commands;

use App\Services\SuperAdminPromotionService;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PromoteSuperAdmin extends Command
{
    protected $signature = 'admin:promote-superadmin {--force : Required in production in addition to confirmation}';

    protected $description = 'Interactively promote one existing verified, active Admin to Super Admin';

    public function handle(SuperAdminPromotionService $superAdminPromotionService): int
    {
        if ($this->laravel->environment('production') && ! $this->option('force')) {
            $this->error('Refusing to run in production without --force. No account was changed.');

            return self::FAILURE;
        }

        try {
            $email = Validator::make(['email' => $this->ask('Existing Admin email')], ['email' => ['required', 'email', 'max:255']])->validate()['email'];
        } catch (ValidationException) {
            $this->error('The email address is invalid. No account was changed.');

            return self::INVALID;
        }

        if (! $this->confirm('Promote this existing Admin account to Super Admin?', false)) {
            $this->warn('Cancelled. No account was changed.');

            return self::FAILURE;
        }

        try {
            $user = $superAdminPromotionService->promoteExistingAdmin($email);
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Super Admin promotion completed.');

        return self::SUCCESS;
    }
}
