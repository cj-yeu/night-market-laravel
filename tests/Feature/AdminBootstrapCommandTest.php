<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminBootstrapCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        $this->app->detectEnvironment(fn () => 'testing');

        parent::tearDown();
    }

    public function test_development_bootstrap_creates_only_a_verified_active_admin_with_a_hashed_password(): void
    {
        $this->artisan('admin:bootstrap')
            ->expectsQuestion('Administrator name', 'Recovery Administrator')
            ->expectsQuestion('Administrator email', 'recovery-admin@example.test')
            ->expectsQuestion('Administrator password', 'RecoveryPassword123!')
            ->expectsConfirmation('Create this verified, active administrator account?', 'yes')
            ->expectsOutput('Administrator account created successfully.')
            ->assertSuccessful();

        $admin = User::query()->where('email', 'recovery-admin@example.test')->firstOrFail();

        $this->assertSame(User::ROLE_ADMIN, $admin->role);
        $this->assertTrue($admin->is_active);
        $this->assertNotNull($admin->email_verified_at);
        $this->assertNotSame('RecoveryPassword123!', $admin->password);
        $this->assertTrue(Hash::check('RecoveryPassword123!', $admin->password));
    }

    public function test_confirmation_decline_creates_nothing(): void
    {
        $this->artisan('admin:bootstrap')
            ->expectsQuestion('Administrator name', 'Cancelled Administrator')
            ->expectsQuestion('Administrator email', 'cancelled-admin@example.test')
            ->expectsQuestion('Administrator password', 'RecoveryPassword123!')
            ->expectsConfirmation('Create this verified, active administrator account?', 'no')
            ->expectsOutput('Cancelled. No account was created.')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'cancelled-admin@example.test']);
    }

    public function test_existing_email_is_refused_without_converting_or_overwriting_the_account(): void
    {
        $client = User::factory()->create([
            'email' => 'existing-client@example.test',
            'role' => User::ROLE_CLIENT,
            'is_active' => false,
            'password' => 'OriginalPassword123!',
        ]);

        $this->artisan('admin:bootstrap')
            ->expectsQuestion('Administrator name', 'Attempted Administrator')
            ->expectsQuestion('Administrator email', $client->email)
            ->expectsQuestion('Administrator password', 'ReplacementPassword123!')
            ->expectsConfirmation('Create this verified, active administrator account?', 'yes')
            ->expectsOutput('An account already exists for that email address. No account was changed.')
            ->assertFailed();

        $client->refresh();
        $this->assertSame(User::ROLE_CLIENT, $client->role);
        $this->assertFalse($client->is_active);
        $this->assertTrue(Hash::check('OriginalPassword123!', $client->password));
        $this->assertDatabaseCount('users', 1);
    }

    public function test_production_requires_force_before_any_interactive_input(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('admin:bootstrap')
            ->expectsOutput('Refusing to run in production without --force. No account was created.')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_production_force_still_requires_interactive_confirmation(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('admin:bootstrap', ['--force' => true])
            ->expectsQuestion('Administrator name', 'Production Recovery')
            ->expectsQuestion('Administrator email', 'production-recovery@example.test')
            ->expectsQuestion('Administrator password', 'RecoveryPassword123!')
            ->expectsConfirmation('Create this verified, active administrator account?', 'no')
            ->expectsOutput('Cancelled. No account was created.')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'production-recovery@example.test']);
    }
}
