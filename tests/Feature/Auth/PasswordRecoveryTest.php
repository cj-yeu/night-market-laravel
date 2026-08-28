<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordRecoveryTest extends TestCase
{
    use DatabaseTransactions;

    private const GENERIC_RESPONSE = 'If an account exists for this email address, a password reset link has been sent.';

    public function test_forgot_password_page_is_available_to_guests_and_linked_from_login(): void
    {
        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee('Forgot Your Password?')
            ->assertSee(route('password.email'));

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Forgot your password?')
            ->assertSee(route('password.request'));
    }

    public function test_existing_account_can_request_a_reset_notification(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email])
            ->assertRedirect()
            ->assertSessionHas('status', self::GENERIC_RESPONSE);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_nonexistent_account_receives_the_same_generic_browser_response(): void
    {
        Notification::fake();

        $response = $this->post(route('password.email'), [
            'email' => 'missing-account@example.test',
        ]);

        $response->assertRedirect()->assertSessionHas('status', self::GENERIC_RESPONSE);
        Notification::assertNothingSent();
    }

    public function test_production_log_mailer_does_not_claim_password_reset_delivery(): void
    {
        Notification::fake();
        $this->app['session']->start();
        $csrfToken = $this->app['session']->token();
        $this->app->detectEnvironment(fn () => 'production');
        config()->set('mail.default', 'log');
        $user = User::factory()->create();

        $this->withSession(['_token' => $csrfToken])->post(route('password.email'), [
            '_token' => $csrfToken,
            'email' => $user->email,
        ])
            ->assertRedirect()
            ->assertSessionHas(
                'error',
                'The password reset email could not be sent. Please try again later.',
            );

        Notification::assertNothingSent();
    }

    public function test_valid_token_resets_password_and_redirects_to_login(): void
    {
        $user = User::factory()->create(['password' => 'old-password']);
        $token = $this->requestToken($user);

        $this->resetPassword($user, $token, 'NewPassword123!')
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Your password has been reset successfully. You can now log in.');

        $this->assertTrue(Hash::check('NewPassword123!', $user->refresh()->password));
        $this->assertFalse(Hash::check('old-password', $user->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_invalid_token_is_rejected(): void
    {
        $user = User::factory()->create(['password' => 'old-password']);

        $this->resetPassword($user, 'invalid-token', 'NewPassword123!')
            ->assertSessionHasErrors([
                'email' => 'The password reset link is invalid or has expired.',
            ]);

        $this->assertTrue(Hash::check('old-password', $user->refresh()->password));
    }

    public function test_expired_token_is_rejected(): void
    {
        $user = User::factory()->create(['password' => 'old-password']);
        $token = $this->requestToken($user);

        $this->travel(61)->minutes();

        $this->resetPassword($user, $token, 'NewPassword123!')
            ->assertSessionHasErrors([
                'email' => 'The password reset link is invalid or has expired.',
            ]);

        $this->assertTrue(Hash::check('old-password', $user->refresh()->password));
    }

    public function test_used_token_cannot_be_reused(): void
    {
        $user = User::factory()->create(['password' => 'old-password']);
        $token = $this->requestToken($user);

        $this->resetPassword($user, $token, 'FirstNewPassword123!')
            ->assertRedirect(route('login'));

        $this->resetPassword($user, $token, 'SecondNewPassword123!')
            ->assertSessionHasErrors([
                'email' => 'The password reset link is invalid or has expired.',
            ]);

        $this->assertTrue(Hash::check('FirstNewPassword123!', $user->refresh()->password));
    }

    public function test_password_confirmation_is_required(): void
    {
        $user = User::factory()->create();
        $token = $this->requestToken($user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NewPassword123!',
        ])->assertSessionHasErrors(['password']);
    }

    public function test_new_password_is_securely_hashed(): void
    {
        $user = User::factory()->create();
        $token = $this->requestToken($user);

        $this->resetPassword($user, $token, 'SecurePassword123!');

        $storedPassword = $user->refresh()->password;

        $this->assertNotSame('SecurePassword123!', $storedPassword);
        $this->assertTrue(Hash::check('SecurePassword123!', $storedPassword));
    }

    public function test_password_reset_preserves_client_and_admin_roles_and_statuses(): void
    {
        $users = [
            User::factory()->create(['role' => User::ROLE_CLIENT, 'is_active' => true]),
            User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]),
            User::factory()->create(['role' => User::ROLE_CLIENT, 'is_active' => false]),
        ];

        foreach ($users as $user) {
            $originalRole = $user->role;
            $originalStatus = $user->is_active;
            $token = $this->requestToken($user);

            $this->resetPassword($user, $token, 'PreservedPassword123!')
                ->assertRedirect(route('login'));

            $user->refresh();

            $this->assertSame($originalRole, $user->role);
            $this->assertSame($originalStatus, $user->is_active);
        }
    }

    public function test_inactive_account_remains_unable_to_log_in_after_reset(): void
    {
        $user = User::factory()->create([
            'is_active' => false,
            'password' => 'old-password',
        ]);
        $token = $this->requestToken($user);

        $this->resetPassword($user, $token, 'NewPassword123!');

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'NewPassword123!',
        ])->assertSessionHasErrors([
            'email' => 'The provided credentials are incorrect. You have 2 attempts remaining.',
        ]);

        $this->assertGuest();
        $this->assertFalse($user->refresh()->is_active);
    }

    private function requestToken(User $user): string
    {
        Notification::fake();

        $this->post(route('password.email'), ['email' => $user->email])
            ->assertSessionHas('status', self::GENERIC_RESPONSE);

        $token = null;

        Notification::assertSentTo(
            $user,
            ResetPassword::class,
            function (ResetPassword $notification) use (&$token): bool {
                $token = $notification->token;

                return true;
            },
        );

        $this->assertIsString($token);

        return $token;
    }

    private function resetPassword(User $user, string $token, string $password)
    {
        return $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => $password,
            'password_confirmation' => $password,
        ]);
    }
}
