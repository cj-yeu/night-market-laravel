<?php

namespace App\Console\Commands;

use App\Services\AdminBootstrapService;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BootstrapAdmin extends Command
{
    protected $signature = 'admin:bootstrap
        {--force : Required in production in addition to the final interactive confirmation}';

    protected $description = 'Interactively create one verified, active administrator without changing existing accounts';

    public function handle(AdminBootstrapService $adminBootstrapService): int
    {
        if ($this->laravel->environment('production') && ! $this->option('force')) {
            $this->error('Refusing to run in production without --force. No account was created.');

            return self::FAILURE;
        }

        $data = [
            'name' => (string) $this->ask('Administrator name'),
            'email' => (string) $this->ask('Administrator email'),
            'password' => (string) $this->secret('Administrator password'),
        ];

        try {
            $data = Validator::make($data, [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', 'string', 'min:8'],
            ])->validate();
        } catch (ValidationException) {
            $this->error('The administrator details are invalid. No account was created.');

            return self::INVALID;
        }

        if (! $this->confirm('Create this verified, active administrator account?', false)) {
            $this->warn('Cancelled. No account was created.');

            return self::FAILURE;
        }

        try {
            $adminBootstrapService->create($data);
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Administrator account created successfully.');

        return self::SUCCESS;
    }
}
