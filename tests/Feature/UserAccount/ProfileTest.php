<?php

namespace Tests\Feature\UserAccount;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use DatabaseTransactions;

    public function test_guest_cannot_access_the_profile_page(): void
    {
        $this->get(route('profile.edit'))
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_client_can_view_their_profile_page(): void
    {
        $client = User::factory()->create([
            'role' => User::ROLE_CLIENT,
            'is_active' => true,
        ]);

        $this->actingAs($client)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('My Profile')
            ->assertSee($client->name)
            ->assertSee($client->email);
    }

    public function test_authenticated_client_can_update_their_own_name_and_email(): void
    {
        $client = User::factory()->create([
            'role' => User::ROLE_CLIENT,
            'is_active' => true,
        ]);

        $response = $this->actingAs($client)->patch(route('profile.update'), [
            'name' => 'Updated Client Name',
            'email' => 'updated.client@example.com',
        ]);

        $response
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('status', 'Your profile has been updated successfully.');

        $this->assertDatabaseHas('users', [
            'id' => $client->id,
            'name' => 'Updated Client Name',
            'email' => 'updated.client@example.com',
        ]);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        $existingUser = User::factory()->create();
        $client = User::factory()->create([
            'role' => User::ROLE_CLIENT,
            'is_active' => true,
        ]);

        $response = $this->actingAs($client)
            ->from(route('profile.edit'))
            ->patch(route('profile.update'), [
                'name' => 'Client Name',
                'email' => $existingUser->email,
            ]);

        $response
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHasErrors('email');

        $this->assertSame($client->email, $client->refresh()->email);
    }

    public function test_role_and_active_status_cannot_be_changed_through_profile_update(): void
    {
        $client = User::factory()->create([
            'role' => User::ROLE_CLIENT,
            'is_active' => true,
        ]);

        $this->actingAs($client)
            ->patch(route('profile.update'), [
                'name' => 'Safe Client Name',
                'email' => 'safe.client@example.com',
                'role' => User::ROLE_ADMIN,
                'is_active' => false,
            ])
            ->assertRedirect(route('profile.edit'));

        $client->refresh();

        $this->assertSame('Safe Client Name', $client->name);
        $this->assertSame('safe.client@example.com', $client->email);
        $this->assertSame(User::ROLE_CLIENT, $client->role);
        $this->assertTrue($client->is_active);
    }
}