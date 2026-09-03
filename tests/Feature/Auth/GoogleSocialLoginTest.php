<?php

namespace Tests\Feature\Auth;

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\GoogleAuthenticationService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use RuntimeException;
use Tests\TestCase;

class GoogleSocialLoginTest extends TestCase
{
    use DatabaseTransactions;

    public function test_login_page_shows_continue_with_google(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Continue with Google')
            ->assertSee(route('auth.google.redirect'));
    }

    public function test_google_redirect_uses_socialite_and_records_login_intent(): void
    {
        $this->fakeGoogle();

        $this->get(route('auth.google.redirect'))
            ->assertRedirect('https://socialite.fake/google/authorize')
            ->assertSessionHas(GoogleAuthenticationService::SESSION_INTENT, [
                'purpose' => 'login',
            ]);
    }

    public function test_new_google_identity_creates_and_logs_in_one_active_client(): void
    {
        Notification::fake();
        $this->fakeGoogle([
            'id' => 'google-new-client',
            'name' => 'Google New Client',
            'email' => 'google-new@example.test',
            'avatar' => 'https://google.example/private-avatar.jpg',
        ]);

        $response = $this->googleLoginCallback();

        $response->assertRedirect(route('client.home'));

        $user = User::where('email', 'google-new@example.test')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertSame(User::ROLE_CLIENT, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertNull($user->password);
        $this->assertNull($user->avatar_path);
        $this->assertDatabaseCount('social_accounts', 1);
        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $user->id,
            'provider' => SocialAccount::PROVIDER_GOOGLE,
            'provider_user_id' => 'google-new-client',
            'provider_email' => 'google-new@example.test',
        ]);
        $this->assertFalse(Schema::hasColumn('social_accounts', 'access_token'));
        $this->assertFalse(Schema::hasColumn('social_accounts', 'refresh_token'));
        Notification::assertNotSentTo($user, VerifyEmail::class);
    }

    public function test_google_user_receives_safe_fallback_name_when_provider_name_is_missing(): void
    {
        $this->fakeGoogle([
            'id' => 'google-fallback-name',
            'name' => null,
            'email' => 'missing.name-user@example.test',
        ]);

        $this->googleLoginCallback()->assertRedirect(route('client.home'));

        $this->assertSame(
            'Missing Name User',
            User::where('email', 'missing.name-user@example.test')->firstOrFail()->name,
        );
    }

    public function test_intended_destination_is_preserved_through_google_login(): void
    {
        $this->fakeGoogle([
            'id' => 'google-intended-client',
            'email' => 'google-intended@example.test',
        ]);
        $intended = route('client.visit-plans.create');

        $this->get($intended)->assertRedirect(route('login'));
        $this->get(route('auth.google.redirect'))->assertRedirect();
        $this->get(route('auth.google.callback'))->assertRedirect($intended);
    }

    public function test_successful_google_login_regenerates_the_session(): void
    {
        $this->fakeGoogle([
            'id' => 'google-session-client',
            'email' => 'google-session@example.test',
        ]);
        $this->app['session']->start();
        $sessionIdBeforeLogin = $this->app['session']->getId();

        $this->googleLoginCallback()->assertRedirect(route('client.home'));

        $this->assertNotSame($sessionIdBeforeLogin, $this->app['session']->getId());
    }

    public function test_returning_linked_client_logs_into_the_same_user_without_duplicates(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_CLIENT,
            'is_active' => true,
        ]);
        $user->socialAccounts()->create([
            'provider' => SocialAccount::PROVIDER_GOOGLE,
            'provider_user_id' => 'google-returning-client',
            'provider_email' => $user->email,
        ]);
        $this->fakeGoogle([
            'id' => 'google-returning-client',
            'email' => $user->email,
        ]);

        $this->googleLoginCallback()->assertRedirect(route('client.home'));

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('social_accounts', 1);
    }

    public function test_verified_google_identity_verifies_the_matching_linked_client(): void
    {
        $user = User::factory()->unverified()->create([
            'role' => User::ROLE_CLIENT,
            'is_active' => true,
        ]);
        $user->socialAccounts()->create([
            'provider' => SocialAccount::PROVIDER_GOOGLE,
            'provider_user_id' => 'google-preserve-verification',
            'provider_email' => $user->email,
        ]);
        $this->fakeGoogle([
            'id' => 'google-preserve-verification',
            'email' => $user->email,
        ]);

        $this->googleLoginCallback()->assertRedirect(route('client.home'));

        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->refresh()->hasVerifiedEmail());
    }

    public function test_repeated_callbacks_do_not_create_duplicate_users_or_social_accounts(): void
    {
        $this->fakeGoogle([
            'id' => 'google-repeat-client',
            'email' => 'google-repeat@example.test',
        ]);

        $this->googleLoginCallback()->assertRedirect(route('client.home'));
        $this->post(route('logout'));
        $this->googleLoginCallback()->assertRedirect(route('client.home'));

        $this->assertSame(1, User::where('email', 'google-repeat@example.test')->count());
        $this->assertSame(1, SocialAccount::where('provider_user_id', 'google-repeat-client')->count());
    }

    public function test_existing_password_client_is_safely_linked_by_verified_matching_google_email(): void
    {
        $existing = User::factory()->unverified()->create([
            'email' => 'existing-local@example.test',
            'role' => User::ROLE_CLIENT,
            'is_active' => true,
            'name' => 'Existing Password Client',
            'password' => 'OriginalPassword123!',
        ]);
        $originalPasswordHash = $existing->password;
        $this->fakeGoogle([
            'id' => 'google-email-collision',
            'email' => '  '.mb_strtoupper($existing->email).'  ',
            'name' => 'Provider Name Must Not Replace Local Name',
        ]);

        $this->googleLoginCallback()->assertRedirect(route('client.home'));

        $this->assertAuthenticatedAs($existing);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $existing->id,
            'provider' => SocialAccount::PROVIDER_GOOGLE,
            'provider_user_id' => 'google-email-collision',
        ]);
        $existing->refresh();
        $this->assertSame('Existing Password Client', $existing->name);
        $this->assertSame(User::ROLE_CLIENT, $existing->role);
        $this->assertTrue($existing->is_active);
        $this->assertTrue($existing->hasVerifiedEmail());
        $this->assertSame($originalPasswordHash, $existing->password);
        $this->assertTrue(Hash::check('OriginalPassword123!', $existing->password));
    }

    public function test_missing_provider_email_or_id_is_rejected(): void
    {
        foreach ([
            ['id' => 'google-missing-email', 'email' => null],
            ['id' => null, 'email' => 'missing-id@example.test'],
        ] as $identity) {
            $this->fakeGoogle($identity);

            $this->googleLoginCallback()
                ->assertRedirect(route('login'))
                ->assertSessionHas(
                    'error',
                    'Google did not provide the account information required to continue.',
                );
        }

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_unverified_provider_email_is_rejected_when_verification_is_exposed(): void
    {
        $this->fakeGoogle([
            'id' => 'google-unverified',
            'email' => 'unverified@example.test',
            'email_verified' => false,
        ]);

        $this->googleLoginCallback()
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Google authentication requires a verified email address.');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_provider_email_without_an_explicit_verified_claim_is_rejected(): void
    {
        $providerUser = SocialiteUser::fake([
            'id' => 'google-missing-verification-claim',
            'name' => 'Missing Verification Claim',
            'email' => 'missing-verification@example.test',
        ]);
        Socialite::fake('google', $providerUser);

        $this->googleLoginCallback()
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Google authentication requires a verified email address.');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_provider_cancellation_is_handled_without_exposing_raw_exception_data(): void
    {
        Socialite::fake('google', function (): never {
            throw new RuntimeException('secret-token=raw-provider-token');
        });

        $response = $this->googleLoginCallback();

        $response
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Google authentication could not be completed. Please try again.')
            ->assertSessionMissing('secret-token');
        $this->followRedirects($response)->assertDontSee('raw-provider-token');
    }

    public function test_google_access_denial_is_reported_as_cancellation_without_echoing_provider_details(): void
    {
        $response = $this->withSession([
            GoogleAuthenticationService::SESSION_INTENT => ['purpose' => 'login'],
        ])->get(route('auth.google.callback', [
            'error' => 'access_denied',
            'error_description' => 'secret-provider-description',
        ]));

        $response
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Google sign-in was cancelled. No changes were made.');
        $this->followRedirects($response)->assertDontSee('secret-provider-description');
    }

    public function test_invalid_google_oauth_state_has_a_specific_safe_error(): void
    {
        Socialite::fake('google', function (): never {
            throw new InvalidStateException;
        });

        $this->googleLoginCallback()
            ->assertRedirect(route('login'))
            ->assertSessionHas(
                'error',
                'The Google authentication session is invalid or expired. Please try again.',
            );

        $this->assertGuest();
    }

    public function test_callback_requires_a_valid_server_side_intent(): void
    {
        $this->fakeGoogle();

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'The Google authentication session expired. Please try again.');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_inactive_linked_client_cannot_log_in_with_google(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_CLIENT,
            'is_active' => false,
        ]);
        $user->socialAccounts()->create([
            'provider' => SocialAccount::PROVIDER_GOOGLE,
            'provider_user_id' => 'google-inactive-client',
            'provider_email' => $user->email,
        ]);
        $this->fakeGoogle([
            'id' => 'google-inactive-client',
            'email' => $user->email,
        ]);

        $this->googleLoginCallback()
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'This account is inactive. Please contact an administrator.');

        $this->assertGuest();
    }

    public function test_existing_unlinked_admin_is_not_auto_linked_by_matching_email(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'email' => 'admin-google-collision@example.test',
        ]);
        $this->fakeGoogle([
            'id' => 'google-admin-collision',
            'email' => $admin->email,
        ]);

        $this->googleLoginCallback()
            ->assertRedirect(route('login'))
            ->assertSessionHas(
                'error',
                'This Admin account is not connected to Google. Sign in with your password instead.',
            );
        $this->assertGuest();
        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_previously_linked_client_promoted_to_admin_keeps_google_login_and_admin_redirect(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_CLIENT,
            'email' => 'promoted-google-admin@example.test',
            'is_active' => true,
        ]);

        $admin->socialAccounts()->create([
            'provider' => SocialAccount::PROVIDER_GOOGLE,
            'provider_user_id' => 'google-admin-linked',
            'provider_email' => $admin->email,
        ]);
        $admin->forceFill(['role' => User::ROLE_ADMIN])->save();
        $this->fakeGoogle([
            'id' => 'google-admin-linked',
            'email' => $admin->email,
        ]);

        $this->withSession([
            GoogleAuthenticationService::SESSION_INTENT => ['purpose' => 'login'],
            'url.intended' => route('client.visit-plans.create'),
        ])->get(route('auth.google.callback'))
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin);
        $this->assertSame(User::ROLE_ADMIN, $admin->refresh()->role);
        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $admin->id,
            'provider_user_id' => 'google-admin-linked',
        ]);
    }

    public function test_provider_id_conflicting_with_another_local_email_is_rejected(): void
    {
        $linkedUser = User::factory()->create(['email' => 'provider-owner@example.test']);
        $linkedUser->socialAccounts()->create([
            'provider' => SocialAccount::PROVIDER_GOOGLE,
            'provider_user_id' => 'google-provider-conflict',
            'provider_email' => $linkedUser->email,
        ]);
        $this->fakeGoogle([
            'id' => 'google-provider-conflict',
            'email' => 'different-provider-email@example.test',
        ]);

        $this->googleLoginCallback()
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'This Google identity conflicts with another account.');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('social_accounts', 1);
    }

    public function test_request_input_cannot_create_an_admin(): void
    {
        $this->fakeGoogle([
            'id' => 'google-role-injection',
            'email' => 'role-injection@example.test',
        ]);

        $this->withSession([
            GoogleAuthenticationService::SESSION_INTENT => [
                'purpose' => 'login',
                'role' => User::ROLE_ADMIN,
            ],
        ])->get(route('auth.google.callback', ['role' => User::ROLE_ADMIN]));

        $user = User::where('email', 'role-injection@example.test')->firstOrFail();

        $this->assertSame(User::ROLE_CLIENT, $user->role);
    }

    public function test_null_password_google_client_cannot_use_arbitrary_password_login(): void
    {
        $user = User::factory()->create([
            'password' => null,
            'role' => User::ROLE_CLIENT,
            'is_active' => true,
        ]);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'any-arbitrary-password',
        ])->assertSessionHasErrors([
            'email' => 'The provided credentials are incorrect. You have 2 attempts remaining.',
        ]);

        $this->assertGuest();
    }

    public function test_traditional_registration_still_requires_a_password(): void
    {
        $this->post(route('register.store'), [
            'name' => 'Password Required Client',
            'email' => 'password-required@example.test',
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', [
            'email' => 'password-required@example.test',
        ]);
        $this->assertGuest();
    }

    public function test_google_only_client_can_use_password_recovery_to_establish_local_password(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'password' => null,
            'role' => User::ROLE_CLIENT,
        ]);

        $this->post(route('password.email'), ['email' => $user->email]);

        $token = null;
        Notification::assertSentTo(
            $user,
            ResetPassword::class,
            function (ResetPassword $notification) use (&$token): bool {
                $token = $notification->token;

                return true;
            },
        );

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'EstablishedPassword123!',
            'password_confirmation' => 'EstablishedPassword123!',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('EstablishedPassword123!', $user->refresh()->password));
        $this->assertSame(User::ROLE_CLIENT, $user->role);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function fakeGoogle(array $attributes = []): void
    {
        Socialite::fake('google', SocialiteUser::fake(array_merge([
            'id' => 'google-default-id',
            'name' => 'Google Test User',
            'email' => 'google-user@example.test',
            'email_verified' => true,
        ], $attributes)));
    }

    private function googleLoginCallback()
    {
        return $this->withSession([
            GoogleAuthenticationService::SESSION_INTENT => ['purpose' => 'login'],
        ])->get(route('auth.google.callback'));
    }
}
