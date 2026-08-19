<?php

namespace Tests\Feature;

use App\Models\Food;
use App\Models\NightMarket;
use App\Models\Review;
use App\Models\SocialMediaRecord;
use App\Models\Stall;
use App\Models\User;
use App\Models\VisitPlan;
use App\Models\VisitPlanItem;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class GuestPublicBrowsingTest extends TestCase
{
    use DatabaseTransactions;

    private const AUTH_MESSAGE = 'Please log in or register to continue. You will return to your requested page after login.';

    public function test_guest_can_open_every_public_browsing_page_and_follow_record_links(): void
    {
        $market = NightMarket::factory()->create(['name' => 'Public Link Market']);
        $stall = Stall::factory()->create([
            'night_market_id' => $market->id,
            'name' => 'Public Link Stall',
        ]);
        $food = Food::factory()->create([
            'stall_id' => $stall->id,
            'name' => 'Public Link Food',
        ]);
        SocialMediaRecord::factory()->approved()->create([
            'night_market_id' => $market->id,
            'food_id' => $food->id,
            'content_summary' => 'Public approved highlight.',
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Discover Markets')
            ->assertSee(route('night-markets.index'));

        $this->get(route('night-markets.index'))
            ->assertOk()
            ->assertSee($market->name)
            ->assertSee('href="'.route('night-markets.show', $market).'"', false);

        $this->get(route('night-markets.show', $market))
            ->assertOk()
            ->assertSee($market->name)
            ->assertSee($stall->name)
            ->assertSee('href="'.route('night-markets.stalls.index', $market).'"', false);

        $this->get(route('night-markets.stalls.index', $market))
            ->assertOk()
            ->assertSee($stall->name)
            ->assertSee($food->name)
            ->assertSee('href="'.route('foods.show', $food).'"', false);

        $this->get(route('foods.show', $food))
            ->assertOk()
            ->assertSee($food->name)
            ->assertSee($stall->name)
            ->assertSee($market->name)
            ->assertSee('Add to Visit Plan');

        $this->get(route('social-media-highlights.index'))
            ->assertOk()
            ->assertSee('Public approved highlight.')
            ->assertSee('href="'.route('night-markets.show', $market).'"', false)
            ->assertSee('href="'.route('foods.show', $food).'"', false);
    }

    public function test_guest_sees_only_public_markets_stalls_and_foods(): void
    {
        $activeMarket = NightMarket::factory()->create(['name' => 'Visible Active Market']);
        $inactiveMarket = NightMarket::factory()->inactive()->create(['name' => 'Hidden Inactive Market']);
        $inactiveParentStall = Stall::factory()->create([
            'night_market_id' => $inactiveMarket->id,
            'name' => 'Hidden Parent Stall',
        ]);
        $inactiveParentFood = Food::factory()->create([
            'stall_id' => $inactiveParentStall->id,
            'name' => 'Hidden Parent Food',
        ]);

        $this->get(route('night-markets.index'))
            ->assertOk()
            ->assertSee($activeMarket->name)
            ->assertDontSee($inactiveMarket->name);

        $this->get(route('night-markets.show', $inactiveMarket))->assertNotFound();
        $this->get(route('night-markets.stalls.index', $inactiveMarket))->assertNotFound();
        $this->get(route('foods.show', $inactiveParentFood))->assertNotFound();
    }

    public function test_guest_sees_approved_reviews_only(): void
    {
        $market = NightMarket::factory()->create();
        Review::factory()->approved()->create([
            'night_market_id' => $market->id,
            'comment' => 'Approved public review.',
        ]);
        Review::factory()->create([
            'night_market_id' => $market->id,
            'comment' => 'Pending private review.',
        ]);
        Review::factory()->rejected()->create([
            'night_market_id' => $market->id,
            'comment' => 'Rejected private review.',
        ]);

        $this->get(route('night-markets.show', $market))
            ->assertOk()
            ->assertSee('Approved public review.')
            ->assertDontSee('Pending private review.')
            ->assertDontSee('Rejected private review.');
    }

    public function test_guest_sees_only_approved_social_media_for_public_markets(): void
    {
        $publicMarket = NightMarket::factory()->create();
        $inactiveMarket = NightMarket::factory()->inactive()->create();
        SocialMediaRecord::factory()->approved()->create([
            'night_market_id' => $publicMarket->id,
            'content_summary' => 'Visible approved social post.',
        ]);
        SocialMediaRecord::factory()->create([
            'night_market_id' => $publicMarket->id,
            'content_summary' => 'Hidden pending social post.',
        ]);
        SocialMediaRecord::factory()->rejected()->create([
            'night_market_id' => $publicMarket->id,
            'content_summary' => 'Hidden rejected social post.',
        ]);
        SocialMediaRecord::factory()->approved()->create([
            'night_market_id' => $inactiveMarket->id,
            'content_summary' => 'Hidden inactive-market social post.',
        ]);

        $this->get(route('social-media-highlights.index'))
            ->assertOk()
            ->assertSee('Visible approved social post.')
            ->assertDontSee('Hidden pending social post.')
            ->assertDontSee('Hidden rejected social post.')
            ->assertDontSee('Hidden inactive-market social post.');
    }

    public function test_guest_protected_pages_and_actions_redirect_with_a_central_message(): void
    {
        $market = NightMarket::factory()->create();
        $owner = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $visitPlan = VisitPlan::factory()->create([
            'user_id' => $owner->id,
            'night_market_id' => $market->id,
            'title' => 'Private Owner Visit Plan',
        ]);
        $item = VisitPlanItem::factory()->create(['visit_plan_id' => $visitPlan->id]);

        $protectedGets = [
            route('client.night-markets.reviews.create', $market),
            route('client.visit-plans.index'),
            route('client.visit-plans.create'),
            route('client.visit-plans.show', $visitPlan),
            route('profile.edit'),
            route('admin.dashboard'),
        ];

        foreach ($protectedGets as $url) {
            $this->get($url)
                ->assertRedirect(route('login'))
                ->assertSessionHas('error', self::AUTH_MESSAGE);
        }

        $this->get(route('home'))->assertDontSee($visitPlan->title);
        $this->get(route('night-markets.show', $market))->assertDontSee($visitPlan->title);

        $this->post(route('client.night-markets.reviews.store', $market))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', self::AUTH_MESSAGE);
        $this->post(route('client.visit-plans.store'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', self::AUTH_MESSAGE);
        $this->patch(route('client.visit-plans.update', $visitPlan))
            ->assertRedirect(route('login'));
        $this->delete(route('client.visit-plans.destroy', $visitPlan))
            ->assertRedirect(route('login'));
        $this->post(route('client.visit-plans.items.store', $visitPlan))
            ->assertRedirect(route('login'));
        $this->delete(route('client.visit-plans.items.destroy', [$visitPlan, $item]))
            ->assertRedirect(route('login'));
    }

    public function test_login_returns_client_to_the_intended_protected_page(): void
    {
        $client = User::factory()->create([
            'role' => User::ROLE_CLIENT,
            'is_active' => true,
        ]);
        $intendedUrl = route('client.visit-plans.create');

        $this->get($intendedUrl)->assertRedirect(route('login'));

        $this->post(route('login.store'), [
            'email' => $client->email,
            'password' => 'password',
        ])->assertRedirect($intendedUrl);
    }

    public function test_registration_preserves_the_intended_protected_page_until_verification(): void
    {
        $intendedUrl = route('client.visit-plans.create');

        $this->get($intendedUrl)->assertRedirect(route('login'));

        $this->post(route('register.store'), [
            'name' => 'New Public Browser',
            'email' => 'new-public-browser@example.test',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertRedirect(route('verification.notice'));

        $this->assertSame($intendedUrl, session('url.intended'));
    }

    public function test_authenticated_client_can_browse_and_use_protected_action_pages(): void
    {
        $client = User::factory()->create([
            'role' => User::ROLE_CLIENT,
            'is_active' => true,
        ]);
        $market = NightMarket::factory()->create();

        $this->actingAs($client)
            ->get(route('night-markets.index'))
            ->assertOk()
            ->assertSee($market->name);
        $this->actingAs($client)
            ->get(route('client.night-markets.reviews.create', $market))
            ->assertOk()
            ->assertSee('Submit Review');
        $this->actingAs($client)
            ->get(route('client.visit-plans.create'))
            ->assertOk()
            ->assertSee('Create Visit Plan');
    }

    public function test_admin_routes_and_sidebar_remain_admin_only(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(route('admin.users.index'))
            ->assertSee(route('admin.reviews.index'))
            ->assertSee(route('admin.social-media-records.index'));

        $this->actingAs($client)->get(route('admin.dashboard'))->assertForbidden();
    }
}
