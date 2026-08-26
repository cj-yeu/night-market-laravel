<?php

namespace Tests\Feature\Auth;

use App\Models\Food;
use App\Models\NightMarket;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\VisitPlan;
use App\Models\VisitPlanItem;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_verification_email_uses_the_project_name_in_its_salutation(): void
    {
        $message = (new VerifyEmail)->toMail(User::factory()->create());

        $this->assertInstanceOf(MailMessage::class, $message);
        $this->assertSame("Regards,\nNight Market Selangor", $message->salutation);
    }

    public function test_password_registration_creates_only_an_unverified_client_and_sends_verification(): void
    {
        Notification::fake();

        $this->post(route('register.store'), [
            'name' => 'New Verification Client',
            'email' => 'new-verification@example.test',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => User::ROLE_ADMIN,
            'email_verified_at' => now(),
        ])->assertRedirect(route('verification.notice'));

        $user = User::where('email', 'new-verification@example.test')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertSame(User::ROLE_CLIENT, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertFalse($user->hasVerifiedEmail());
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_verification_notice_is_authenticated_and_shows_safe_account_controls(): void
    {
        $user = User::factory()->unverified()->create();

        $this->get(route('verification.notice'))->assertRedirect(route('login'));

        $this->actingAs($user)
            ->get(route('verification.notice'))
            ->assertOk()
            ->assertSee('Verify your email address')
            ->assertSee($user->email)
            ->assertSee(route('verification.send'))
            ->assertSee(route('profile.edit'))
            ->assertSee(route('logout'));

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('Verification pending');
    }

    public function test_valid_signed_link_verifies_current_user_and_reuse_is_harmless(): void
    {
        $user = User::factory()->unverified()->create();
        $verificationUrl = $this->verificationUrl($user);

        $this->actingAs($user)
            ->get($verificationUrl)
            ->assertRedirect(route('client.home'))
            ->assertSessionHas('status', 'Your email address has been verified successfully.');

        $this->assertTrue($user->refresh()->hasVerifiedEmail());

        $this->actingAs($user)
            ->get($verificationUrl)
            ->assertRedirect(route('client.home'));
        $this->assertTrue($user->refresh()->hasVerifiedEmail());
    }

    public function test_unsigned_tampered_and_expired_verification_links_are_rejected(): void
    {
        $user = User::factory()->unverified()->create();
        $other = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get(route('verification.verify', ['id' => $user->id, 'hash' => sha1($user->email)]))
            ->assertForbidden();

        $this->actingAs($user)
            ->get($this->verificationUrl($user, ['id' => $other->id]))
            ->assertForbidden();

        $this->actingAs($user)
            ->get($this->verificationUrl($user, ['hash' => sha1('tampered@example.test')]))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(URL::temporarySignedRoute(
                'verification.verify',
                now()->subMinute(),
                ['id' => $user->id, 'hash' => sha1($user->email)],
            ))
            ->assertForbidden();

        $this->assertFalse($user->refresh()->hasVerifiedEmail());
        $this->assertFalse($other->refresh()->hasVerifiedEmail());
    }

    public function test_link_for_another_account_cannot_verify_the_authenticated_user(): void
    {
        $user = User::factory()->unverified()->create();
        $other = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get($this->verificationUrl($other))
            ->assertForbidden();

        $this->assertFalse($user->refresh()->hasVerifiedEmail());
        $this->assertFalse($other->refresh()->hasVerifiedEmail());
    }

    public function test_resend_targets_only_current_user_and_ignores_request_targets(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $other = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->post(route('verification.send'), [
                'user_id' => $other->id,
                'email' => $other->email,
            ])
            ->assertRedirect(route('verification.notice'))
            ->assertSessionHas('status', 'A new verification email has been sent to your current email address.');

        Notification::assertSentTo($user, VerifyEmail::class);
        Notification::assertNotSentTo($other, VerifyEmail::class);
    }

    public function test_guest_cannot_resend_and_verified_user_is_handled_safely(): void
    {
        Notification::fake();
        $verified = User::factory()->create();

        $this->post(route('verification.send'))->assertRedirect(route('login'));

        $this->actingAs($verified)
            ->post(route('verification.send'))
            ->assertRedirect(route('verification.notice'))
            ->assertSessionHas('status', 'Your email address is already verified.');

        Notification::assertNothingSent();
    }

    public function test_resend_is_limited_to_once_per_minute(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->post(route('verification.send'))->assertRedirect();
        $this->actingAs($user)
            ->post(route('verification.send'))
            ->assertRedirect(route('verification.notice'))
            ->assertSessionHas('error', 'Please wait 60 seconds before requesting another verification email.');

        Notification::assertSentToTimes($user, VerifyEmail::class, 1);
    }

    public function test_unverified_client_can_browse_public_pages_but_not_trusted_actions(): void
    {
        $user = User::factory()->unverified()->create(['role' => User::ROLE_CLIENT]);
        $market = NightMarket::factory()->create();
        $food = Food::factory()->create();
        $plan = VisitPlan::factory()->create(['user_id' => $user->id, 'night_market_id' => $market->id]);
        $item = VisitPlanItem::factory()->create(['visit_plan_id' => $plan->id]);

        $this->actingAs($user)->get(route('home'))->assertOk();
        $this->actingAs($user)->get(route('night-markets.index'))->assertOk();
        $this->actingAs($user)->get(route('night-markets.show', $market))->assertOk();
        $this->actingAs($user)->get(route('profile.edit'))->assertOk();

        $protectedRequests = [
            fn () => $this->get(route('client.home')),
            fn () => $this->get(route('client.foods.reviews.create', $food)),
            fn () => $this->post(route('client.foods.reviews.store', $food)),
            fn () => $this->get(route('client.visit-plans.index')),
            fn () => $this->post(route('client.visit-plans.store')),
            fn () => $this->patch(route('client.visit-plans.update', $plan)),
            fn () => $this->delete(route('client.visit-plans.destroy', $plan)),
            fn () => $this->post(route('client.visit-plans.items.store', $plan)),
            fn () => $this->delete(route('client.visit-plans.items.destroy', [$plan, $item])),
        ];

        foreach ($protectedRequests as $request) {
            $request()->assertRedirect(route('verification.notice'));
        }

        $this->assertDatabaseCount('reviews', 0);
        $this->assertDatabaseHas('visit_plans', ['id' => $plan->id]);
        $this->assertDatabaseHas('visit_plan_items', ['id' => $item->id]);
    }

    public function test_unverified_login_and_registration_preserve_intended_destination_until_verification(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create(['password' => 'Password123!']);
        $intended = route('client.visit-plans.create');

        $this->get($intended)->assertRedirect(route('login'));
        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'Password123!',
        ])->assertRedirect(route('verification.notice'));
        $this->assertSame($intended, session('url.intended'));

        $this->get($this->verificationUrl($user))->assertRedirect($intended);
    }

    public function test_inactive_client_cannot_verify_or_resend(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create([
            'role' => User::ROLE_CLIENT,
            'is_active' => false,
        ]);

        $this->actingAs($user)
            ->get($this->verificationUrl($user))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Your account is inactive. Please contact an administrator.');
        $this->assertGuest();

        $this->post(route('verification.send'))->assertRedirect(route('login'));

        $this->assertFalse($user->refresh()->hasVerifiedEmail());
        Notification::assertNothingSent();
    }

    public function test_verified_client_email_change_requires_password_resets_verification_and_notifies_new_email(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'role' => User::ROLE_CLIENT,
            'password' => 'Password123!',
        ]);

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => 'changed-email@example.test',
                'current_password' => 'Password123!',
            ])
            ->assertRedirect(route('verification.notice'));

        $user->refresh();
        $this->assertSame('changed-email@example.test', $user->email);
        $this->assertFalse($user->hasVerifiedEmail());
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_email_change_requires_current_password_input(): void
    {
        Notification::fake();
        $user = User::factory()->create(['password' => 'Password123!']);
        $originalEmail = $user->email;

        $this->actingAs($user)
            ->from(route('profile.edit'))
            ->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => 'missing-password@example.test',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertSame($originalEmail, $user->refresh()->email);
        $this->assertTrue($user->hasVerifiedEmail());
        Notification::assertNothingSent();
    }

    public function test_failed_email_changes_preserve_original_email_and_verification(): void
    {
        Notification::fake();
        $existing = User::factory()->create();
        $user = User::factory()->create(['password' => 'Password123!']);
        $originalEmail = $user->email;
        $originalVerifiedAt = $user->email_verified_at;

        $this->actingAs($user)
            ->from(route('profile.edit'))
            ->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => 'wrong-password@example.test',
                'current_password' => 'WrongPassword123!',
            ])
            ->assertSessionHasErrors('current_password');

        $this->actingAs($user)
            ->from(route('profile.edit'))
            ->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => $existing->email,
                'current_password' => 'Password123!',
            ])
            ->assertSessionHasErrors('email');

        $user->refresh();
        $this->assertSame($originalEmail, $user->email);
        $this->assertTrue($originalVerifiedAt->equalTo($user->email_verified_at));
        Notification::assertNothingSent();
    }

    public function test_google_linked_client_must_disconnect_before_changing_email(): void
    {
        Notification::fake();
        $user = User::factory()->create(['password' => 'Password123!']);
        $user->socialAccounts()->create([
            'provider' => SocialAccount::PROVIDER_GOOGLE,
            'provider_user_id' => 'google-email-policy',
            'provider_email' => $user->email,
        ]);

        $this->actingAs($user)
            ->from(route('profile.edit'))
            ->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => 'blocked-google-change@example.test',
                'current_password' => 'Password123!',
            ])
            ->assertSessionHasErrors([
                'email' => 'Disconnect Google before changing your account email address.',
            ]);

        $user->refresh();
        $this->assertNotSame('blocked-google-change@example.test', $user->email);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertDatabaseHas('social_accounts', ['user_id' => $user->id]);
        Notification::assertNothingSent();
    }

    public function test_password_reset_does_not_verify_email(): void
    {
        $user = User::factory()->unverified()->create();
        $token = Password::broker()->createToken($user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'ReplacementPassword123!',
            'password_confirmation' => 'ReplacementPassword123!',
        ])->assertRedirect(route('login'));

        $this->assertFalse($user->refresh()->hasVerifiedEmail());
    }

    public function test_compatibility_migration_grandfathers_existing_users_without_altering_account_state(): void
    {
        $client = User::factory()->unverified()->create([
            'role' => User::ROLE_CLIENT,
            'is_active' => false,
            'password' => 'CompatibilityPassword123!',
        ]);
        $admin = User::factory()->unverified()->create(['role' => User::ROLE_ADMIN]);
        $clientPassword = $client->password;

        $migration = require database_path('migrations/2026_08_19_000004_grandfather_existing_users_as_email_verified.php');
        $migration->up();

        $client->refresh();
        $admin->refresh();
        $this->assertTrue($client->hasVerifiedEmail());
        $this->assertTrue($admin->hasVerifiedEmail());
        $this->assertSame(User::ROLE_CLIENT, $client->role);
        $this->assertFalse($client->is_active);
        $this->assertSame($clientPassword, $client->password);

        $migration->down();
        $this->assertTrue($client->refresh()->hasVerifiedEmail());
        $this->assertTrue($admin->refresh()->hasVerifiedEmail());
    }

    /**
     * @param  array{id?: int, hash?: string}  $overrides
     */
    private function verificationUrl(User $user, array $overrides = []): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            now()->addHour(),
            array_merge([
                'id' => $user->id,
                'hash' => sha1($user->getEmailForVerification()),
            ], $overrides),
        );
    }
}
