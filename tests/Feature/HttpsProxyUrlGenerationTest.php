<?php

namespace Tests\Feature;

use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class HttpsProxyUrlGenerationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_https_app_url_forces_secure_logout_form_action(): void
    {
        config()->set('app.url', 'https://example.test');
        (new AppServiceProvider($this->app))->boot();
        $user = User::factory()->create(['role' => User::ROLE_CLIENT]);

        $this->actingAs($user)->get(route('client.home'))
            ->assertOk()
            ->assertSee('action="https://localhost/logout"', false);

        URL::forceScheme(null);
    }
}
