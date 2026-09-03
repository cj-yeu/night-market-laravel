<?php

namespace Tests\Feature;

use App\Models\CatalogAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SuperAdminRoleManagementTest extends TestCase
{
    use DatabaseTransactions;

    public function test_super_admin_inherits_existing_admin_access_while_clients_and_guests_are_denied(): void
    {
        $superAdmin = $this->superAdmin();
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);

        $this->get(route('admin.users.index'))->assertRedirect(route('login'));
        $this->actingAs($superAdmin)->get(route('admin.dashboard'))->assertOk();
        $this->actingAs($superAdmin)->get(route('admin.users.index'))->assertOk();
        $this->actingAs($client)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($client)->patch(route('admin.users.promote', $client))->assertForbidden();
    }

    public function test_only_super_admin_can_promote_an_active_verified_client_and_audit_it(): void
    {
        $superAdmin = $this->superAdmin();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT, 'is_active' => true]);

        $this->actingAs($admin)->patch(route('admin.users.promote', $client))->assertForbidden();
        $this->actingAs($superAdmin)->patch(route('admin.users.promote', $client))->assertRedirect(route('admin.users.show', $client));
        $this->assertSame(User::ROLE_ADMIN, $client->fresh()->role);
        $this->assertDatabaseHas('catalog_audit_logs', ['user_id' => $superAdmin->id, 'entity_type' => 'user', 'entity_id' => $client->id, 'action' => 'role_promoted']);
    }

    public function test_ineligible_clients_forged_roles_self_demotion_and_super_admin_targets_are_protected(): void
    {
        $superAdmin = $this->superAdmin();
        $inactive = User::factory()->create(['role' => User::ROLE_CLIENT, 'is_active' => false]);
        $pending = User::factory()->unverified()->create(['role' => User::ROLE_CLIENT]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $otherSuperAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $this->actingAs($superAdmin)->patch(route('admin.users.promote', $inactive))->assertSessionHasErrors('role');
        $this->actingAs($superAdmin)->patch(route('admin.users.promote', $pending))->assertSessionHasErrors('role');
        $this->actingAs($superAdmin)->patch(route('admin.users.promote', $admin), ['role' => User::ROLE_SUPER_ADMIN])->assertSessionHasErrors('role');
        $this->actingAs($superAdmin)->patch(route('admin.users.demote', $superAdmin))->assertSessionHasErrors('role');
        $this->actingAs($superAdmin)->patch(route('admin.users.demote', $otherSuperAdmin))->assertSessionHasErrors('role');
        $this->assertSame(User::ROLE_SUPER_ADMIN, $superAdmin->fresh()->role);
        $this->assertSame(0, CatalogAuditLog::query()->count());
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => User::ROLE_SUPER_ADMIN, 'is_active' => true]);
    }
}
