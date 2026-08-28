<?php

namespace Tests\Feature\UserAccount;

use App\Models\NightMarket;
use App\Models\Review;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\GoogleAuthenticationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class GoogleAccountConnectionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_guest_cannot_access_connect_or_disconnect_actions(): void
    {
        $this->post(route('profile.google.connect'))
            ->assertRedirect(route('login'));
        $this->delete(route('profile.google.disconnect'))
            ->assertRedirect(route('login'));
    }

    public function test_admin_cannot_access_client_connect_or_disconnect_actions(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->post(route('profile.google.connect'), ['current_password' => 'password'])
            ->assertForbidden();
        $this->actingAs($admin)
            ->delete(route('profile.google.disconnect'), ['current_password' => 'password'])
            ->assertForbidden();
    }

    public function test_client_must_provide_correct_current_password_to_start_linking(): void
    {
        $client = $this->client();

        $this->actingAs($client)
            ->post(route('profile.google.connect'), ['current_password' => 'wrong-password'])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHasErrors(['current_password' => 'The current password is incorrect.']);

        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_correct_password_starts_stateful_google_linking_intent(): void
    {
        $client = $this->client();
        $this->fakeGoogle($client->email);

        $this->actingAs($client)
            ->post(route('profile.google.connect'), ['current_password' => 'password'])
            ->assertRedirect('https://socialite.fake/google/authorize')
            ->assertSessionHas(GoogleAuthenticationService::SESSION_INTENT, [
                'purpose' => 'link',
                'user_id' => $client->id,
            ]);
    }

    public function test_link_callback_requires_a_valid_session_intent(): void
    {
        $client = $this->client();
        $this->fakeGoogle($client->email);

        $this->actingAs($client)
            ->get(route('auth.google.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'The Google authentication session expired. Please try again.');

        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_google_email_must_match_authenticated_client_email(): void
    {
        $client = $this->client();
        $this->fakeGoogle('different-google-email@example.test');

        $this->linkCallback($client)
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('error', 'The Google email address must match your current account email.');

        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_client_can_link_one_matching_google_identity(): void
    {
        $client = $this->client(['email_verified_at' => null]);
        $this->assertFalse($client->hasVerifiedEmail());
        $this->fakeGoogle($client->email, 'google-link-client');

        $this->linkCallback($client)
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('status', 'Your Google account has been connected successfully.');

        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $client->id,
            'provider' => SocialAccount::PROVIDER_GOOGLE,
            'provider_user_id' => 'google-link-client',
            'provider_email' => $client->email,
        ]);
        $this->assertTrue($client->refresh()->hasVerifiedEmail());
    }

    public function test_google_identity_linked_to_another_user_is_rejected(): void
    {
        $client = $this->client();
        $other = User::factory()->create();
        $other->socialAccounts()->create([
            'provider' => SocialAccount::PROVIDER_GOOGLE,
            'provider_user_id' => 'google-already-owned',
            'provider_email' => $client->email,
        ]);
        $this->fakeGoogle($client->email, 'google-already-owned');

        $this->linkCallback($client)
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('error', 'This Google account is already connected to another profile.');

        $this->assertDatabaseCount('social_accounts', 1);
    }

    public function test_client_cannot_link_a_second_google_identity(): void
    {
        $client = $this->client();
        $client->socialAccounts()->create([
            'provider' => SocialAccount::PROVIDER_GOOGLE,
            'provider_user_id' => 'google-first-link',
            'provider_email' => $client->email,
        ]);
        $this->fakeGoogle($client->email, 'google-second-link');

        $this->linkCallback($client)
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('error', 'A Google account is already connected to this profile.');

        $this->assertDatabaseCount('social_accounts', 1);
    }

    public function test_linking_does_not_alter_role_status_password_or_avatar(): void
    {
        $client = $this->client([
            'avatar_path' => 'avatars/existing-profile-image.jpg',
        ]);
        $password = $client->password;
        $this->fakeGoogle($client->email, 'google-preserve-client');

        $this->linkCallback($client)->assertRedirect(route('profile.edit'));
        $client->refresh();

        $this->assertSame(User::ROLE_CLIENT, $client->role);
        $this->assertTrue($client->is_active);
        $this->assertSame($password, $client->password);
        $this->assertSame('avatars/existing-profile-image.jpg', $client->avatar_path);
    }

    public function test_callback_query_cannot_select_another_target_user(): void
    {
        $client = $this->client();
        $other = User::factory()->create();
        $this->fakeGoogle($client->email, 'google-query-target');

        $this->actingAs($client)
            ->withSession([
                GoogleAuthenticationService::SESSION_INTENT => [
                    'purpose' => 'link',
                    'user_id' => $client->id,
                ],
            ])
            ->get(route('auth.google.callback', ['user_id' => $other->id]))
            ->assertRedirect(route('profile.edit'));

        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $client->id,
            'provider_user_id' => 'google-query-target',
        ]);
        $this->assertDatabaseMissing('social_accounts', ['user_id' => $other->id]);
    }

    public function test_link_callback_confirms_same_authenticated_session_user(): void
    {
        $client = $this->client();
        $other = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $this->fakeGoogle($other->email);

        $this->actingAs($other)
            ->withSession([
                GoogleAuthenticationService::SESSION_INTENT => [
                    'purpose' => 'link',
                    'user_id' => $client->id,
                ],
            ])
            ->get(route('auth.google.callback'))
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('error', 'The Google connection session expired. Please try again from Profile.');

        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_client_with_local_password_can_disconnect_google(): void
    {
        $client = $this->linkedClient();

        $this->actingAs($client)
            ->delete(route('profile.google.disconnect'), ['current_password' => 'password'])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('status', 'Your Google account has been disconnected.');

        $this->assertDatabaseMissing('social_accounts', ['user_id' => $client->id]);
        $this->assertDatabaseHas('users', ['id' => $client->id]);
    }

    public function test_incorrect_password_cannot_disconnect_google(): void
    {
        $client = $this->linkedClient();

        $this->actingAs($client)
            ->delete(route('profile.google.disconnect'), ['current_password' => 'wrong-password'])
            ->assertSessionHasErrors(['current_password' => 'The current password is incorrect.']);

        $this->assertDatabaseHas('social_accounts', ['user_id' => $client->id]);
    }

    public function test_google_only_client_cannot_disconnect_their_only_login_method(): void
    {
        $client = $this->linkedClient(['password' => null]);

        $this->actingAs($client)
            ->delete(route('profile.google.disconnect'), ['current_password' => 'anything'])
            ->assertSessionHasErrors([
                'current_password' => 'Set a local password using Forgot Password before disconnecting Google.',
            ]);

        $this->assertDatabaseHas('social_accounts', ['user_id' => $client->id]);
    }

    public function test_disconnect_deletes_only_authenticated_clients_google_account(): void
    {
        $client = $this->linkedClient();
        $other = $this->linkedClient([
            'email' => 'other-linked-client@example.test',
        ], 'google-other-client');

        $this->actingAs($client)
            ->delete(route('profile.google.disconnect'), ['current_password' => 'password']);

        $this->assertDatabaseMissing('social_accounts', ['user_id' => $client->id]);
        $this->assertDatabaseHas('social_accounts', ['user_id' => $other->id]);
    }

    public function test_disconnect_preserves_user_domain_data_and_avatar(): void
    {
        $client = $this->linkedClient([
            'avatar_path' => 'avatars/preserved-avatar.jpg',
            'is_active' => true,
        ]);
        $password = $client->password;
        $market = NightMarket::factory()->create();
        $review = Review::factory()->create([
            'user_id' => $client->id,
            'night_market_id' => $market->id,
        ]);

        $this->actingAs($client)
            ->delete(route('profile.google.disconnect'), ['current_password' => 'password']);

        $client->refresh();
        $this->assertSame(User::ROLE_CLIENT, $client->role);
        $this->assertTrue($client->is_active);
        $this->assertSame($password, $client->password);
        $this->assertSame('avatars/preserved-avatar.jpg', $client->avatar_path);
        $this->assertDatabaseHas('reviews', ['id' => $review->id, 'user_id' => $client->id]);
    }

    public function test_disconnect_when_not_linked_is_safe(): void
    {
        $client = $this->client();

        $this->actingAs($client)
            ->delete(route('profile.google.disconnect'), ['current_password' => 'password'])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('status', 'No Google account was connected.');

        $this->assertDatabaseHas('users', ['id' => $client->id]);
    }

    public function test_profile_shows_connected_account_status_without_provider_id_or_tokens(): void
    {
        $client = $this->linkedClient();

        $this->actingAs($client)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('Google: Connected')
            ->assertSee($client->email)
            ->assertSee('Disconnect Google')
            ->assertDontSee('google-linked-client')
            ->assertDontSee('access_token')
            ->assertDontSee('refresh_token');
    }

    public function test_null_password_change_flow_fails_safely(): void
    {
        $client = User::factory()->create([
            'role' => User::ROLE_CLIENT,
            'password' => null,
        ]);

        $this->actingAs($client)
            ->patch(route('profile.password.update'), [
                'current_password' => 'anything',
                'password' => 'NewPassword123!',
                'password_confirmation' => 'NewPassword123!',
            ])
            ->assertSessionHasErrors([
                'current_password' => 'Use Forgot Password to establish a local password first.',
            ]);

        $this->assertNull($client->refresh()->password);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function client(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => User::ROLE_CLIENT,
            'is_active' => true,
            'password' => 'password',
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function linkedClient(array $attributes = [], string $providerId = 'google-linked-client'): User
    {
        $client = $this->client($attributes);
        $client->socialAccounts()->create([
            'provider' => SocialAccount::PROVIDER_GOOGLE,
            'provider_user_id' => $providerId,
            'provider_email' => $client->email,
        ]);

        return $client;
    }

    private function fakeGoogle(string $email, string $providerId = 'google-link-id'): void
    {
        Socialite::fake('google', SocialiteUser::fake([
            'id' => $providerId,
            'name' => 'Google Link User',
            'email' => $email,
            'email_verified' => true,
        ]));
    }

    private function linkCallback(User $client)
    {
        return $this->actingAs($client)
            ->withSession([
                GoogleAuthenticationService::SESSION_INTENT => [
                    'purpose' => 'link',
                    'user_id' => $client->id,
                ],
            ])
            ->get(route('auth.google.callback'));
    }
}
