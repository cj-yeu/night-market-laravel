<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PromoteSuperAdminCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        $this->app->detectEnvironment(fn () => 'testing');
        parent::tearDown();
    }

    public function test_command_promotes_only_an_existing_active_verified_admin_and_is_idempotent(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'email' => 'approved-admin@example.test']);

        foreach ([1, 2] as $attempt) {
            $this->artisan('admin:promote-superadmin')
                ->expectsQuestion('Existing Admin email', $admin->email)
                ->expectsConfirmation('Promote this existing Admin account to Super Admin?', 'yes')
                ->expectsOutput('Super Admin promotion completed.')
                ->assertSuccessful();
        }

        $this->assertSame(User::ROLE_SUPER_ADMIN, $admin->fresh()->role);
    }

    public function test_command_refuses_client_and_requires_force_in_production(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $this->artisan('admin:promote-superadmin')
            ->expectsQuestion('Existing Admin email', $client->email)
            ->expectsConfirmation('Promote this existing Admin account to Super Admin?', 'yes')
            ->expectsOutput('Only an active, email-verified Admin account can be promoted.')
            ->assertFailed();

        $this->app->detectEnvironment(fn () => 'production');
        $this->artisan('admin:promote-superadmin')
            ->expectsOutput('Refusing to run in production without --force. No account was changed.')
            ->assertFailed();
    }
}
