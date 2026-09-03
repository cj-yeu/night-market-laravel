<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class LoginThrottlingTest extends TestCase
{
    use DatabaseTransactions;

    private const FIRST_FAILURE = 'The provided credentials are incorrect. You have 2 attempts remaining.';

    private const SECOND_FAILURE = 'The provided credentials are incorrect. You have 1 attempt remaining.';

    private const COOLDOWN = 'Too many unsuccessful login attempts. Please try again in 15 minutes.';

    public function test_first_failed_attempt_reports_two_attempts_remaining(): void
    {
        $user = $this->activeClient();

        $this->login($user->email, 'incorrect-password')
            ->assertSessionHasErrors(['email' => self::FIRST_FAILURE]);
    }

    public function test_second_failed_attempt_reports_one_attempt_remaining(): void
    {
        $user = $this->activeClient();

        $this->login($user->email, 'incorrect-password');

        $this->login($user->email, 'incorrect-password')
            ->assertSessionHasErrors(['email' => self::SECOND_FAILURE]);
    }

    public function test_third_failure_activates_the_fifteen_minute_cooldown(): void
    {
        $user = $this->activeClient();

        $this->login($user->email, 'incorrect-password');
        $this->login($user->email, 'incorrect-password');

        $this->login($user->email, 'incorrect-password')
            ->assertSessionHasErrors(['email' => self::COOLDOWN]);
    }

    public function test_fourth_attempt_during_cooldown_is_blocked(): void
    {
        $user = $this->activeClient();

        $this->reachCooldown($user);

        $this->login($user->email, 'incorrect-password')
            ->assertSessionHasErrors(['email' => self::COOLDOWN]);
    }

    public function test_correct_credentials_are_blocked_during_an_active_cooldown(): void
    {
        $user = $this->activeClient();

        $this->reachCooldown($user);

        $this->login($user->email, 'password')
            ->assertSessionHasErrors(['email' => self::COOLDOWN]);

        $this->assertGuest();
    }

    public function test_successful_login_before_the_limit_clears_previous_failures(): void
    {
        $user = $this->activeClient();

        $this->login($user->email, 'incorrect-password');

        $this->login($user->email, 'password')
            ->assertRedirect(route('client.home'));
        $this->assertAuthenticatedAs($user);

        $this->post(route('logout'));

        $this->login($user->email, 'incorrect-password')
            ->assertSessionHasErrors(['email' => self::FIRST_FAILURE]);
    }

    public function test_login_succeeds_after_the_cooldown_expires(): void
    {
        $user = $this->activeClient();

        $this->reachCooldown($user);
        $this->travel(16)->minutes();

        $this->login($user->email, 'password')
            ->assertRedirect(route('client.home'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_separate_email_and_ip_keys_do_not_share_counters(): void
    {
        $firstUser = $this->activeClient();
        $secondUser = $this->activeClient();

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10']);
        $this->login($firstUser->email, 'incorrect-password');
        $this->login($firstUser->email, 'incorrect-password');

        $this->login($secondUser->email, 'incorrect-password')
            ->assertSessionHasErrors(['email' => self::FIRST_FAILURE]);

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.11']);
        $this->login($firstUser->email, 'incorrect-password')
            ->assertSessionHasErrors(['email' => self::FIRST_FAILURE]);
    }

    public function test_validation_failures_do_not_consume_login_attempts(): void
    {
        $user = $this->activeClient();

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->post(route('login.store'), [
                'email' => 'not-an-email',
            ])->assertSessionHasErrors(['email', 'password']);
        }

        $this->login($user->email, 'incorrect-password')
            ->assertSessionHasErrors(['email' => self::FIRST_FAILURE]);
    }

    public function test_responses_do_not_reveal_whether_an_email_exists_or_is_inactive(): void
    {
        $activeUser = $this->activeClient();
        $inactiveUser = User::factory()->create([
            'is_active' => false,
            'password' => 'password',
        ]);

        $this->login($activeUser->email, 'incorrect-password')
            ->assertSessionHasErrors(['email' => self::FIRST_FAILURE]);
        $this->login('missing-account@example.test', 'incorrect-password')
            ->assertSessionHasErrors(['email' => self::FIRST_FAILURE]);
        $this->login($inactiveUser->email, 'password')
            ->assertSessionHasErrors(['email' => self::FIRST_FAILURE]);

        $this->assertGuest();
    }

    public function test_intended_destination_is_preserved_after_successful_login(): void
    {
        $user = $this->activeClient();
        $intendedUrl = route('client.visit-plans.create');

        $this->get($intendedUrl)->assertRedirect(route('login'));

        $this->login($user->email, 'password')->assertRedirect($intendedUrl);
    }

    public function test_external_intended_destination_is_rejected_after_client_login(): void
    {
        $user = $this->activeClient();

        $this->withSession(['url.intended' => 'https://attacker.example/redirect'])
            ->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'password',
            ])
            ->assertRedirect(route('client.home'));

        $this->assertAuthenticatedAs($user);
        $this->assertNull(session('url.intended'));
    }

    public function test_admin_roles_ignore_intended_urls_and_enter_the_admin_dashboard(): void
    {
        foreach ([User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN] as $role) {
            $user = User::factory()->create([
                'role' => $role,
                'is_active' => true,
                'password' => 'password',
            ]);

            $this->withSession(['url.intended' => route('client.visit-plans.create')])
                ->post(route('login.store'), [
                    'email' => $user->email,
                    'password' => 'password',
                ])
                ->assertRedirect(route('admin.dashboard'));

            $this->assertAuthenticatedAs($user);
            $this->assertNull(session('url.intended'));
            $this->post(route('logout'));
        }
    }

    public function test_successful_password_login_regenerates_the_session(): void
    {
        $user = $this->activeClient();
        $this->app['session']->start();
        $sessionIdBeforeLogin = $this->app['session']->getId();

        $this->login($user->email, 'password')->assertRedirect(route('client.home'));

        $this->assertNotSame($sessionIdBeforeLogin, $this->app['session']->getId());
    }

    public function test_logout_invalidates_session_data_and_rotates_the_csrf_token(): void
    {
        $user = $this->activeClient();
        $this->actingAs($user);
        $this->app['session']->start();
        $this->app['session']->put('authentication-test-value', 'sensitive');
        $tokenBeforeLogout = $this->app['session']->token();

        $this->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNull($this->app['session']->get('authentication-test-value'));
        $this->assertNotSame($tokenBeforeLogout, $this->app['session']->token());
    }

    public function test_authenticated_super_admin_is_redirected_away_from_guest_auth_pages(): void
    {
        $superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $this->actingAs($superAdmin)->get(route('login'))
            ->assertRedirect(route('admin.dashboard'));
        $this->actingAs($superAdmin)->get(route('register'))
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_unsupported_database_role_cannot_authenticate(): void
    {
        $user = User::factory()->create([
            'role' => 'unsupported-role',
            'password' => 'password',
            'is_active' => true,
        ]);

        $this->login($user->email, 'password')
            ->assertSessionHasErrors(['email' => self::FIRST_FAILURE]);

        $this->assertGuest();
    }

    public function test_ordinary_client_and_admin_login_and_logout_still_work(): void
    {
        $users = [
            User::factory()->create([
                'role' => User::ROLE_CLIENT,
                'is_active' => true,
                'password' => 'password',
            ]),
            User::factory()->create([
                'role' => User::ROLE_ADMIN,
                'is_active' => true,
                'password' => 'password',
            ]),
        ];

        foreach ($users as $user) {
            $expectedRoute = $user->role === User::ROLE_ADMIN ? 'admin.dashboard' : 'client.home';

            $this->login($user->email, 'password')->assertRedirect(route($expectedRoute));
            $this->assertAuthenticatedAs($user);

            $this->post(route('logout'))->assertRedirect(route('login'));
            $this->assertGuest();
        }
    }

    private function activeClient(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_CLIENT,
            'is_active' => true,
            'password' => 'password',
        ]);
    }

    private function login(string $email, string $password)
    {
        return $this->post(route('login.store'), [
            'email' => $email,
            'password' => $password,
        ]);
    }

    private function reachCooldown(User $user): void
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->login($user->email, 'incorrect-password');
        }
    }
}
