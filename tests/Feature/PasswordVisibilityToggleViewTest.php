<?php

namespace Tests\Feature;

use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class PasswordVisibilityToggleViewTest extends TestCase
{
    public function test_password_visibility_toggle_controls_are_rendered_for_login_register_and_change_password(): void
    {
        view()->share('errors', new ViewErrorBag);

        foreach (['auth.login', 'auth.register', 'profile.change-password'] as $view) {
            $html = view($view)->render();

            $this->assertStringContainsString('data-password-toggle', $html);
            $this->assertStringContainsString('bi-eye', $html);
            $this->assertStringContainsString('aria-label="Show', $html);
        }
    }
}
