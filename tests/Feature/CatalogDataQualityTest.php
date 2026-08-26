<?php

namespace Tests\Feature;

use App\Models\Food;
use App\Models\MarketOperatingDay;
use App\Models\NightMarket;
use App\Models\Stall;
use App\Models\User;
use App\Services\AdminReturnUrlService;
use App\Services\CatalogDataQualityService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Tests\TestCase;

class CatalogDataQualityTest extends TestCase
{
    use DatabaseTransactions;

    public function test_quality_dashboard_requires_an_admin(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);

        $this->get(route('admin.catalog-data-quality.index'))->assertRedirect(route('login'));
        $this->actingAs($client)->get(route('admin.catalog-data-quality.index'))->assertForbidden();
        $this->actingAs($client)->get(route('admin.catalog-data-quality.records', 'food_missing_image'))->assertForbidden();
    }

    public function test_dashboard_counts_missing_fields_and_review_lists_are_paginated(): void
    {
        $market = NightMarket::factory()->create(['image_path' => null, 'source_url' => null, 'verified_at' => null]);
        $stall = Stall::factory()->create([
            'night_market_id' => $market->id,
            'category' => null,
            'halal_status' => Stall::HALAL_UNKNOWN,
            'source_url' => null,
            'verified_at' => null,
        ]);
        $food = Food::factory()->create([
            'stall_id' => $stall->id,
            'image_path' => null,
            'price_min' => null,
            'price_max' => null,
            'price_display' => null,
            'is_must_try' => true,
            'recommendation_reason' => null,
            'source_url' => null,
            'price_checked_at' => null,
            'verified_at' => null,
        ]);

        $summary = app(CatalogDataQualityService::class)->summary();

        $this->assertSame(1, $summary['market_missing_image']['count']);
        $this->assertSame(1, $summary['market_missing_schedule']['count']);
        $this->assertSame(1, $summary['stall_unknown_halal']['count']);
        $this->assertSame(1, $summary['food_missing_price']['count']);
        $this->assertSame(1, $summary['food_must_try_missing_reason']['count']);

        $response = $this->actingAs($this->admin())->get(route('admin.catalog-data-quality.records', 'food_missing_image'));
        $response->assertOk()->assertSee($food->name)->assertSee(route('admin.foods.edit', $food), false);
        $response->assertSee('return_to=%2Fadmin%2Fcatalog-data-quality%2Ffood_missing_image', false);
    }

    public function test_public_market_page_shows_only_available_verification_date(): void
    {
        $market = NightMarket::factory()->create(['verified_at' => '2026-08-20']);
        MarketOperatingDay::factory()->create(['night_market_id' => $market->id]);

        $this->get(route('night-markets.show', $market))
            ->assertOk()
            ->assertSee('Last verified')
            ->assertSee('Aug 20, 2026');
    }

    public function test_return_url_accepts_only_catalog_quality_paths(): void
    {
        $service = app(AdminReturnUrlService::class);

        $this->assertSame(
            '/admin/catalog-data-quality/food_missing_image?page=2',
            $service->catalogQualityUrl(Request::create('/', 'GET', ['return_to' => '/admin/catalog-data-quality/food_missing_image?page=2'])),
        );
        $this->assertNull($service->catalogQualityUrl(Request::create('/', 'GET', ['return_to' => 'https://example.test/admin/catalog-data-quality/food_missing_image'])));
        $this->assertNull($service->catalogQualityUrl(Request::create('/', 'GET', ['return_to' => '/admin/foods'])));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }
}
