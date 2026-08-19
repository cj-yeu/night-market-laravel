<?php

namespace Tests\Feature\UserAccount;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
    }

    public function test_admin_can_view_the_user_list(): void
    {
        $client = User::factory()->create([
            'name' => 'Visible Client',
            'role' => User::ROLE_CLIENT,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('User Management')
            ->assertSee($client->name)
            ->assertSee($client->email);
    }

    public function test_client_cannot_access_user_management(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);

        $this->actingAs($client)
            ->get(route('admin.users.index'))
            ->assertForbidden();

        $this->actingAs($client)
            ->patch(route('admin.users.status.update', $this->admin), ['is_active' => false])
            ->assertForbidden();
    }

    public function test_search_by_name_or_email_works(): void
    {
        $nameMatch = User::factory()->create(['name' => 'Aina Discovery']);
        $emailMatch = User::factory()->create(['email' => 'special.search@example.com']);
        $otherUser = User::factory()->create(['name' => 'Unrelated Person']);

        $this->actingAs($this->admin)
            ->get(route('admin.users.index', ['search' => 'Aina']))
            ->assertOk()
            ->assertSee($nameMatch->email)
            ->assertDontSee($otherUser->email);

        $this->actingAs($this->admin)
            ->get(route('admin.users.index', ['search' => 'special.search']))
            ->assertOk()
            ->assertSee($emailMatch->email)
            ->assertDontSee($otherUser->email);
    }

    public function test_role_filter_works(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $otherAdmin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($this->admin)
            ->get(route('admin.users.index', ['role' => User::ROLE_CLIENT]))
            ->assertOk()
            ->assertSee($client->email)
            ->assertDontSee($otherAdmin->email);
    }

    public function test_status_filter_works(): void
    {
        $activeUser = User::factory()->create(['is_active' => true]);
        $inactiveUser = User::factory()->create(['is_active' => false]);

        $this->actingAs($this->admin)
            ->get(route('admin.users.index', ['status' => 'inactive']))
            ->assertOk()
            ->assertSee($inactiveUser->email)
            ->assertDontSee($activeUser->email);
    }

    public function test_admin_can_deactivate_another_user(): void
    {
        $client = User::factory()->create(['is_active' => true]);

        $this->actingAs($this->admin)
            ->patch(route('admin.users.status.update', $client), ['is_active' => false])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('status', 'The user account was deactivated successfully.');

        $this->assertFalse($client->refresh()->is_active);
    }

    public function test_admin_can_activate_an_inactive_user(): void
    {
        $client = User::factory()->create(['is_active' => false]);

        $this->actingAs($this->admin)
            ->patch(route('admin.users.status.update', $client), ['is_active' => true])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('status', 'The user account was activated successfully.');

        $this->assertTrue($client->refresh()->is_active);
    }

    public function test_admin_cannot_deactivate_themselves(): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.users.index'))
            ->patch(route('admin.users.status.update', $this->admin), ['is_active' => false])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasErrors([
                'is_active' => 'You cannot deactivate your own account.',
            ]);

        $this->assertTrue($this->admin->refresh()->is_active);
    }

    public function test_deactivated_user_cannot_log_in(): void
    {
        $client = User::factory()->create([
            'email' => 'inactive.login@example.com',
            'password' => 'password',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->patch(route('admin.users.status.update', $client), ['is_active' => false]);

        $this->post(route('logout'));

        $this->post(route('login.store'), [
            'email' => $client->email,
            'password' => 'password',
        ])
            ->assertSessionHasErrors([
                'email' => 'The provided credentials are incorrect. You have 2 attempts remaining.',
            ]);

        $this->assertGuest();
    }

    public function test_active_user_can_log_in_again_after_reactivation(): void
    {
        $client = User::factory()->create([
            'email' => 'reactivated.login@example.com',
            'password' => 'password',
            'role' => User::ROLE_CLIENT,
            'is_active' => false,
        ]);

        $this->actingAs($this->admin)
            ->patch(route('admin.users.status.update', $client), ['is_active' => true]);

        $this->post(route('logout'));

        $this->post(route('login.store'), [
            'email' => $client->email,
            'password' => 'password',
        ])
            ->assertRedirect(route('client.home'));

        $this->assertAuthenticatedAs($client);
    }
}
