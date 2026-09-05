<?php

namespace Tests\Feature;

use App\Models\CatalogCategory;
use App\Models\Food;
use App\Models\NightMarket;
use App\Models\Review;
use App\Models\SocialMediaRecord;
use App\Models\Stall;
use App\Models\User;
use App\Services\CatalogCategoryService;
use App\Services\ReviewService;
use App\Services\SmartVisitPlannerService;
use App\Services\SocialMediaDataService;
use App\Support\CatalogCategory as CategoryLabel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PlannerCatalogUxTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        Mail::fake();
        Notification::fake();
    }

    public function test_only_explicit_type_specific_aliases_are_collapsed(): void
    {
        $this->assertSame('Beverage', CategoryLabel::canonical('  BEVERAGE  /  drink ', 'food'));
        $this->assertSame('Dessert', CategoryLabel::canonical('Dessert Stall', 'stall'));
        $this->assertSame('Dessert Stall', CategoryLabel::canonical('Dessert Stall', 'food'));
        $this->assertSame('Dessert / Ice Cream', CategoryLabel::canonical('Dessert / Ice Cream', 'stall'));
        $this->assertSame('Main / Salad', CategoryLabel::canonical('main / salad', 'food'));
    }

    public function test_canonical_public_filter_matches_legacy_values_without_rewriting_them(): void
    {
        $stall = Stall::factory()->create();
        $food = Food::factory()->create(['stall_id' => $stall->id, 'category' => ' Beverage  / Drink ', 'name' => 'Alias Match Food']);
        $other = Food::factory()->create(['stall_id' => $stall->id, 'category' => 'Dessert / Ice Cream', 'name' => 'Distinct Food']);
        $this->get(route('foods.index', ['stall_id' => $stall->id, 'category' => 'Beverage']))
            ->assertOk()->assertSee($food->name)->assertDontSee($other->name)
            ->assertSee('Explore Foods')->assertDontSee('Show Must-Try Foods');
        $this->assertSame(' Beverage  / Drink ', $food->fresh()->category);
    }

    public function test_managed_aliases_deduplicate_and_unchanged_legacy_category_is_preserved(): void
    {
        foreach (['Beverage', 'Beverage / Drink'] as $name) {
            CatalogCategory::query()->updateOrCreate(['category_type' => 'food', 'normalized_name' => strtolower($name)], ['name' => $name, 'is_active' => true]);
        }
        $service = app(CatalogCategoryService::class);
        $this->assertCount(1, $service->activeForType('food')->where('name', 'Beverage'));
        $count = CatalogCategory::query()->count();
        $service->resolveForCatalog('food', null, 'beverage / drink', null, null);
        $this->assertSame($count, CatalogCategory::query()->count());
        $this->assertSame('Beverage / Drink', $service->resolveForCatalog('food', 'Beverage', null, 'Beverage / Drink', null));
        CatalogCategory::query()->where('category_type', 'food')->where('normalized_name', 'beverage / drink')->update(['is_active' => false]);
        $this->assertFalse($service->isPermittedSelection('food', 'Beverage / Drink'));
        $this->assertFalse($service->isPermittedSelection('food', 'Beverage', 'Beverage / Drink'));
        $this->assertFalse($service->isPermittedSelection('food', 'Forged category'));
        Http::assertNothingSent();
    }

    public function test_active_alias_cannot_reenable_an_inactive_canonical_category(): void
    {
        CatalogCategory::query()->updateOrCreate(['category_type' => 'food', 'normalized_name' => 'beverage'], ['name' => 'Beverage', 'is_active' => false]);
        CatalogCategory::query()->updateOrCreate(['category_type' => 'food', 'normalized_name' => 'beverage / drink'], ['name' => 'Beverage / Drink', 'is_active' => true]);
        $service = app(CatalogCategoryService::class);
        $this->assertFalse($service->activeForType('food')->contains('name', 'Beverage'));
        $this->assertFalse($service->isPermittedSelection('food', 'Beverage / Drink'));
        $this->expectException(ValidationException::class);
        $service->resolveForCatalog('food', null, 'Beverage / Drink', null, null);
    }

    public function test_review_options_include_food_review_market_and_historical_parents(): void
    {
        $market = NightMarket::factory()->inactive()->create();
        $stall = Stall::factory()->inactive()->create(['night_market_id' => $market->id]);
        $food = Food::factory()->inactive()->create(['stall_id' => $stall->id]);
        $review = Review::factory()->approved()->forFood($food)->create();
        $options = app(ReviewService::class)->managementFilterOptions();
        $this->assertTrue($options['markets']->contains('id', $market->id));
        $this->assertTrue($options['stalls']->contains('id', $stall->id));
        $this->assertTrue($options['foods']->contains('id', $food->id));
        $this->assertTrue(app(ReviewService::class)->reviewsForManagement(['market_id' => $market->id])->contains('id', $review->id));
    }

    public function test_public_and_admin_filters_reject_incompatible_parent_selections(): void
    {
        $market = NightMarket::factory()->create();
        $stall = Stall::factory()->create();
        $food = Food::factory()->create(['stall_id' => $stall->id]);
        $this->get(route('foods.index', ['night_market_id' => $market->id, 'stall_id' => $stall->id]))->assertSessionHasErrors('stall_id');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->get(route('admin.foods.index', ['night_market_id' => $market->id, 'stall_id' => $stall->id]))->assertSessionHasErrors('stall_id');
        $this->actingAs($admin)->get(route('admin.reviews.index', ['market_id' => $market->id, 'food_id' => $food->id]))->assertSessionHasErrors('food_id');
    }

    public function test_city_market_mismatch_is_rejected_by_request_and_planner_service(): void
    {
        $market = NightMarket::factory()->create(['city' => 'Kajang']);
        $market->operatingDays()->create(['day_of_week' => 'Monday', 'opening_time' => '18:00', 'closing_time' => '22:00']);
        Food::factory()->create(['stall_id' => Stall::factory()->create(['night_market_id' => $market->id])->id]);
        $other = NightMarket::factory()->create(['city' => 'Shah Alam']);
        $other->operatingDays()->create(['day_of_week' => 'Tuesday', 'opening_time' => '18:00', 'closing_time' => '22:00']);
        Food::factory()->create(['stall_id' => Stall::factory()->create(['night_market_id' => $other->id])->id]);
        $data = ['visit_date' => now()->addDay()->toDateString(), 'city' => 'Shah Alam', 'night_market_id' => $market->id, 'halal_preference' => 'any', 'must_try' => false, 'max_markets' => 1];
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $this->actingAs($client)->post(route('client.visit-plans.smart-planner.recommend'), $data)->assertSessionHasErrors('night_market_id');
        $this->actingAs($client)->post(route('client.visit-plans.store'), [...$data, 'title' => 'City mismatch'])->assertSessionHasErrors('night_market_id');
        $this->expectException(ValidationException::class);
        app(SmartVisitPlannerService::class)->recommendDateAware($data);
    }

    public function test_completed_moderation_cannot_be_changed_from_a_stale_page(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $record = SocialMediaRecord::factory()->create(['status' => 'pending']);
        $service = app(SocialMediaDataService::class);
        $stale = $record->replicate();
        $stale->id = $record->id;
        $service->moderate($record, $admin, 'rejected', 'Requires clearer evidence');
        $before = $record->fresh()->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']);
        $this->actingAs($admin)->patch(route('admin.social-media-records.moderate', $record), ['status' => 'approved'])->assertSessionHasErrors('status');
        $this->assertEquals($before, $record->fresh()->only(array_keys($before)));
        $this->assertSame(['approved' => 0, 'rejected' => 0, 'skipped' => 1], $service->bulkModerate([$record->id], $admin, 'approved'));
        try {
            $service->moderate($stale, $admin, 'approved');
            $this->fail('Stale moderation must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }
        Http::assertNothingSent();
    }

    public function test_planner_and_admin_views_render_the_shared_controls_once(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $market = NightMarket::factory()->create();
        $market->operatingDays()->create(['day_of_week' => 'Friday', 'opening_time' => '18:00', 'closing_time' => '22:00']);
        Food::factory()->create(['stall_id' => Stall::factory()->create(['night_market_id' => $market->id])->id]);
        $this->actingAs($client)->get(route('client.visit-plans.create'))->assertOk()->assertSee('Manual Plan:')->assertSee('data-schedule-select', false)->assertSee('Your visit at a glance');
        $this->actingAs($client)->get(route('client.visit-plans.smart-planner.index'))->assertOk()->assertDontSee('City/District')->assertSee('data-parent-select="city"', false);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->get(route('admin.catalog-activity.index'))->assertOk()->assertSee('Reset Filters');
        $response = $this->actingAs($admin)->get(route('admin.social-media-records.index'))->assertOk();
        $this->assertSame(1, substr_count($response->getContent(), "const toggle = document.getElementById('select-pending-records')"));
    }
}
