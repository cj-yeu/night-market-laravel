<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProductionMailReadinessTest extends TestCase
{
    use DatabaseTransactions;

    public function test_registration_verification_notification_is_transport_agnostic_when_smtp_is_configured(): void
    {
        config()->set('mail.default', 'smtp');
        Notification::fake();

        $this->post(route('register.store'), [
            'name' => 'SMTP Ready Client',
            'email' => 'smtp-ready-client@example.test',
            'password' => 'RegistrationPassword123!',
            'password_confirmation' => 'RegistrationPassword123!',
        ])->assertRedirect(route('verification.notice'));

        $user = User::query()->where('email', 'smtp-ready-client@example.test')->firstOrFail();
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_password_reset_notification_is_transport_agnostic_when_smtp_is_configured(): void
    {
        config()->set('mail.default', 'smtp');
        Notification::fake();
        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email])
            ->assertRedirect()
            ->assertSessionHas('status', 'If an account exists for this email address, a password reset link has been sent.');

        Notification::assertSentTo($user, ResetPassword::class);
    }
}
