<?php

namespace Tests\Feature\UserAccount;

use App\Models\Review;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\VisitPlan;
use App\Services\UserManagementService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
    }

    public function test_user_management_routes_enforce_admin_authorization(): void
    {
        $client = User::factory()->create();

        $this->get(route('admin.users.index'))->assertRedirect(route('login'));
        $this->get(route('admin.users.show', $client))->assertRedirect(route('login'));
        $this->patch(route('admin.users.status.update', $client), ['is_active' => false])
            ->assertRedirect(route('login'));

        $this->actingAs($client)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($client)->get(route('admin.users.show', $this->admin))->assertForbidden();
        $this->actingAs($client)
            ->patch(route('admin.users.status.update', $client), ['is_active' => false])
            ->assertForbidden();

        $this->actingAs($this->admin)->get(route('admin.users.index'))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.users.show', $client))->assertOk();
    }

    public function test_user_list_is_paginated_and_preserves_filter_query_parameters(): void
    {
        User::factory()->count(20)->create([
            'role' => User::ROLE_CLIENT,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.users.index', [
            'role' => User::ROLE_CLIENT,
            'status' => 'active',
            'verification' => 'verified',
        ]));

        $response
            ->assertOk()
            ->assertSee('Page 1 of 2')
            ->assertSee('role=client', false)
            ->assertSee('status=active', false)
            ->assertSee('verification=verified', false);
    }

    public function test_partial_name_and_email_search_are_safe_and_normalized(): void
    {
        $nameMatch = User::factory()->create(['name' => 'Aina Discovery']);
        $emailMatch = User::factory()->create(['email' => 'special.search@example.test']);
        $literalWildcards = User::factory()->create(['name' => 'Percent % Under_score']);
        $other = User::factory()->create(['name' => 'Unrelated Person']);

        $this->actingAs($this->admin)
            ->get(route('admin.users.index', ['search' => '  Aina   Discovery  ']))
            ->assertOk()
            ->assertSee($nameMatch->email)
            ->assertDontSee($other->email);

        $this->actingAs($this->admin)
            ->get(route('admin.users.index', ['search' => 'special.search']))
            ->assertOk()
            ->assertSee($emailMatch->email)
            ->assertDontSee($other->email);

        $this->actingAs($this->admin)
            ->get(route('admin.users.index', ['search' => '% Under_']))
            ->assertOk()
            ->assertSee($literalWildcards->email)
            ->assertDontSee($other->email);

        $this->actingAs($this->admin)
            ->get(route('admin.users.index', ['search' => "%' OR 1=1 --"]))
            ->assertOk()
            ->assertDontSee($other->email);
    }

    public function test_role_status_and_verification_filters_work_individually(): void
    {
        $activeVerifiedClient = User::factory()->create([
            'role' => User::ROLE_CLIENT,
            'is_active' => true,
        ]);
        $inactivePendingClient = User::factory()->unverified()->create([
            'role' => User::ROLE_CLIENT,
            'is_active' => false,
        ]);
        $otherAdmin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($this->admin)
            ->get(route('admin.users.index', ['role' => User::ROLE_CLIENT]))
            ->assertSee($activeVerifiedClient->email)
            ->assertSee($inactivePendingClient->email)
            ->assertDontSee($otherAdmin->email);

        $this->actingAs($this->admin)
            ->get(route('admin.users.index', ['status' => 'inactive']))
            ->assertSee($inactivePendingClient->email)
            ->assertDontSee($activeVerifiedClient->email);

        $this->actingAs($this->admin)
            ->get(route('admin.users.index', ['verification' => 'pending']))
            ->assertSee($inactivePendingClient->email)
            ->assertDontSee($activeVerifiedClient->email);
    }

    public function test_authentication_method_filters_use_the_documented_definitions(): void
    {
        $passwordOnly = User::factory()->create(['email' => 'password-only@example.test']);
        $googleOnly = User::factory()->create([
            'email' => 'google-only@example.test',
            'password' => null,
        ]);
        $both = User::factory()->create(['email' => 'both-methods@example.test']);

        foreach ([[$googleOnly, 'google-only-id'], [$both, 'both-methods-id']] as [$user, $providerId]) {
            $user->socialAccounts()->create([
                'provider' => SocialAccount::PROVIDER_GOOGLE,
                'provider_user_id' => $providerId,
                'provider_email' => $user->email,
            ]);
        }

        $this->actingAs($this->admin)
            ->get(route('admin.users.index', ['auth_method' => User::AUTH_PASSWORD]))
            ->assertSee($passwordOnly->email)
            ->assertDontSee($googleOnly->email)
            ->assertDontSee($both->email);

        $this->actingAs($this->admin)
            ->get(route('admin.users.index', ['auth_method' => User::AUTH_GOOGLE]))
            ->assertSee($googleOnly->email)
            ->assertDontSee($passwordOnly->email)
            ->assertDontSee($both->email);

        $this->actingAs($this->admin)
            ->get(route('admin.users.index', ['auth_method' => User::AUTH_PASSWORD_AND_GOOGLE]))
            ->assertSee($both->email)
            ->assertDontSee($passwordOnly->email)
            ->assertDontSee($googleOnly->email);
    }

    public function test_multiple_filters_combine_and_invalid_values_are_rejected(): void
    {
        $match = User::factory()->unverified()->create([
            'name' => 'Combined Match',
            'role' => User::ROLE_CLIENT,
            'is_active' => false,
        ]);
        $wrongStatus = User::factory()->unverified()->create([
            'name' => 'Combined Active',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.users.index', [
                'search' => 'Combined',
                'role' => User::ROLE_CLIENT,
                'status' => 'inactive',
                'verification' => 'pending',
                'auth_method' => User::AUTH_PASSWORD,
            ]))
            ->assertOk()
            ->assertSee($match->email)
            ->assertDontSee($wrongStatus->email);

        foreach ([
            ['role' => 'superadmin'],
            ['status' => 'deleted'],
            ['verification' => 'unknown'],
            ['auth_method' => 'facebook'],
            ['search' => str_repeat('x', 101)],
        ] as $invalidFilter) {
            $this->actingAs($this->admin)
                ->get(route('admin.users.index', $invalidFilter))
                ->assertRedirect()
                ->assertSessionHasErrors(array_key_first($invalidFilter));
        }
    }

    public function test_listing_eager_loads_only_minimal_google_account_fields(): void
    {
        $client = User::factory()->create();
        $client->socialAccounts()->create([
            'provider' => SocialAccount::PROVIDER_GOOGLE,
            'provider_user_id' => 'private-provider-id',
            'provider_email' => $client->email,
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $users = app(UserManagementService::class)->users([]);
        $listedClient = $users->firstWhere('id', $client->id);
        $socialQueries = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains($query['query'], 'social_accounts'));

        $this->assertTrue($listedClient->relationLoaded('googleAccount'));
        $this->assertSame(['id', 'user_id', 'provider'], array_keys($listedClient->googleAccount->getAttributes()));
        $this->assertCount(1, $socialQueries);
        $this->assertSame(User::AUTH_PASSWORD_AND_GOOGLE, $listedClient->authenticationMethod());
    }

    public function test_admin_details_show_real_counts_and_never_render_sensitive_values(): void
    {
        $client = User::factory()->unverified()->create([
            'remember_token' => 'private-remember-token',
        ]);
        $providerId = 'private-google-provider-id';
        $client->socialAccounts()->create([
            'provider' => SocialAccount::PROVIDER_GOOGLE,
            'provider_user_id' => $providerId,
            'provider_email' => $client->email,
        ]);
        Review::factory()->count(2)->create(['user_id' => $client->id]);
        VisitPlan::factory()->count(3)->create(['user_id' => $client->id]);
        DB::table('password_reset_tokens')->insert([
            'email' => $client->email,
            'token' => 'private-reset-token',
            'created_at' => now(),
        ]);
        DB::table('sessions')->insert([
            'id' => 'private-session-id',
            'user_id' => $client->id,
            'payload' => 'private-session-payload',
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.users.show', $client))
            ->assertOk()
            ->assertSee($client->name)
            ->assertSee($client->email)
            ->assertSee('Verification pending')
            ->assertSee('Password + Google')
            ->assertSee('Connected')
            ->assertSee('>2<', false)
            ->assertSee('>3<', false)
            ->assertDontSee($client->password)
            ->assertDontSee('private-remember-token')
            ->assertDontSee($providerId)
            ->assertDontSee('private-reset-token')
            ->assertDontSee('private-session-id')
            ->assertDontSee('private-session-payload');
    }

    public function test_status_updates_are_client_only_idempotent_and_preserve_account_data(): void
    {
        $client = User::factory()->create([
            'avatar_path' => 'avatars/preserved.jpg',
            'is_active' => true,
        ]);
        $password = $client->password;
        $verifiedAt = $client->email_verified_at;
        $social = $client->socialAccounts()->create([
            'provider' => SocialAccount::PROVIDER_GOOGLE,
            'provider_user_id' => 'preserved-google-id',
            'provider_email' => $client->email,
        ]);
        $review = Review::factory()->create(['user_id' => $client->id]);
        $plan = VisitPlan::factory()->create(['user_id' => $client->id]);

        foreach ([false, false, true, true] as $isActive) {
            $this->actingAs($this->admin)
                ->patch(route('admin.users.status.update', $client), [
                    'is_active' => $isActive,
                    'role' => User::ROLE_ADMIN,
                ])
                ->assertRedirect();
            $this->assertSame($isActive, $client->refresh()->is_active);
        }

        $client->refresh();
        $this->assertSame(User::ROLE_CLIENT, $client->role);
        $this->assertSame($password, $client->password);
        $this->assertTrue($verifiedAt->equalTo($client->email_verified_at));
        $this->assertSame('avatars/preserved.jpg', $client->avatar_path);
        $this->assertDatabaseHas('social_accounts', ['id' => $social->id]);
        $this->assertDatabaseHas('reviews', ['id' => $review->id]);
        $this->assertDatabaseHas('visit_plans', ['id' => $plan->id]);
    }

    public function test_invalid_status_self_deactivation_and_all_admin_targets_are_rejected(): void
    {
        $otherAdmin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($this->admin)
            ->patch(route('admin.users.status.update', $otherAdmin), ['is_active' => 'invalid'])
            ->assertSessionHasErrors('is_active');

        $this->actingAs($this->admin)
            ->from(route('admin.users.index'))
            ->patch(route('admin.users.status.update', $this->admin), ['is_active' => false])
            ->assertSessionHasErrors([
                'is_active' => 'You cannot deactivate your own account.',
            ]);

        $this->actingAs($this->admin)
            ->from(route('admin.users.index'))
            ->patch(route('admin.users.status.update', $otherAdmin), ['is_active' => false])
            ->assertSessionHasErrors([
                'is_active' => 'Only Client account status can be managed here.',
            ]);

        $this->assertTrue($this->admin->refresh()->is_active);
        $this->assertTrue($otherAdmin->refresh()->is_active);
    }

    public function test_deactivated_authenticated_client_is_logged_out_on_next_request_without_affecting_others(): void
    {
        $client = User::factory()->create(['is_active' => true]);
        $otherClient = User::factory()->create(['is_active' => true]);

        $this->actingAs($client);
        $this->app['session']->start();
        $sessionId = $this->app['session']->getId();
        $client->forceFill(['is_active' => false])->save();

        $this->get(route('profile.edit'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Your account is inactive. Please contact an administrator.');

        $this->assertGuest();
        $this->assertNotSame($sessionId, $this->app['session']->getId());

        $this->actingAs($otherClient)->get(route('profile.edit'))->assertOk();
        $this->assertAuthenticatedAs($otherClient);
    }

    public function test_database_session_revocation_deletes_only_deactivated_clients_sessions(): void
    {
        Config::set('session.driver', 'database');
        $client = User::factory()->create();
        $otherClient = User::factory()->create();

        foreach ([
            ['id' => 'client-session-one', 'user_id' => $client->id],
            ['id' => 'client-session-two', 'user_id' => $client->id],
            ['id' => 'other-session', 'user_id' => $otherClient->id],
        ] as $session) {
            DB::table('sessions')->insert($session + [
                'payload' => 'test-payload',
                'last_activity' => now()->timestamp,
            ]);
        }

        app(UserManagementService::class)->updateStatus($this->admin, $client, false);

        $this->assertDatabaseMissing('sessions', ['id' => 'client-session-one']);
        $this->assertDatabaseMissing('sessions', ['id' => 'client-session-two']);
        $this->assertDatabaseHas('sessions', ['id' => 'other-session', 'user_id' => $otherClient->id]);
    }

    public function test_reactivation_does_not_login_or_verify_client_but_allows_later_authentication(): void
    {
        $client = User::factory()->unverified()->create([
            'email' => 'reactivated-client@example.test',
            'password' => 'password',
            'is_active' => false,
        ]);

        $this->actingAs($this->admin)
            ->patch(route('admin.users.status.update', $client), ['is_active' => true])
            ->assertSessionHas('status', 'The Client account was activated successfully.');
        $this->post(route('logout'));

        $this->assertGuest();
        $this->assertFalse($client->refresh()->hasVerifiedEmail());

        $this->post(route('login.store'), [
            'email' => $client->email,
            'password' => 'password',
        ])->assertRedirect(route('verification.notice'));

        $this->assertAuthenticatedAs($client);
        $this->assertFalse($client->refresh()->hasVerifiedEmail());
    }

    public function test_public_pages_do_not_expose_admin_user_details(): void
    {
        $privateAdmin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'name' => 'Private Admin Identity',
            'email' => 'private-admin@example.test',
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee($privateAdmin->name)
            ->assertDontSee($privateAdmin->email);
        $this->get(route('night-markets.index'))
            ->assertOk()
            ->assertDontSee($privateAdmin->name)
            ->assertDontSee($privateAdmin->email);
    }
}
