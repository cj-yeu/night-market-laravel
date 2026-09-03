<?php

namespace Tests\Feature;

use App\Models\CatalogCategory;
use App\Models\Food;
use App\Models\NightMarket;
use App\Models\Stall;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CatalogCategoryManagementTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private NightMarket $market;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->market = NightMarket::factory()->create(['state' => 'Selangor']);
    }

    public function test_admin_can_add_a_managed_stall_or_food_category_without_cross_type_leakage(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.stalls.store'), $this->stallPayload(['new_category' => '  Grill Corner  ']))
            ->assertRedirect(route('admin.stalls.create'));

        $stall = Stall::query()->where('name', 'Managed Category Stall')->firstOrFail();
        $this->assertSame('Grill Corner', $stall->category);
        $this->assertDatabaseHas('catalog_categories', [
            'category_type' => CatalogCategory::TYPE_STALL,
            'name' => 'Grill Corner',
            'created_by' => $this->admin->id,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.foods.store'), $this->foodPayload($stall, ['category' => 'Grill Corner']))
            ->assertSessionHasErrors('category');

        $this->actingAs($this->admin)
            ->post(route('admin.foods.store'), $this->foodPayload($stall, ['new_category' => 'Noodles']))
            ->assertRedirect(route('admin.foods.create'));

        $this->assertDatabaseHas('catalog_categories', [
            'category_type' => CatalogCategory::TYPE_FOOD,
            'name' => 'Noodles',
            'created_by' => $this->admin->id,
        ]);
        $this->assertDatabaseHas('foods', ['name' => 'Managed Category Food', 'category' => 'Noodles']);
    }

    public function test_unregistered_legacy_category_can_be_retained_but_unknown_new_selection_is_rejected(): void
    {
        $stall = Stall::factory()->create([
            'night_market_id' => $this->market->id,
            'category' => 'Legacy only category',
        ]);

        $this->actingAs($this->admin)
            ->patch(route('admin.stalls.update', $stall), $this->stallPayload([
                'name' => $stall->name,
                'category' => 'Legacy only category',
            ], $this->market->id))
            ->assertRedirect(route('admin.stalls.show', $stall));

        $this->actingAs($this->admin)
            ->patch(route('admin.stalls.update', $stall), $this->stallPayload([
                'name' => $stall->name,
                'category' => 'Forged Unknown Category',
            ], $this->market->id))
            ->assertSessionHasErrors('category');

        CatalogCategory::query()->create([
            'category_type' => CatalogCategory::TYPE_FOOD,
            'name' => 'Inactive Food Category',
            'normalized_name' => 'inactive food category',
            'is_active' => false,
        ]);
        $this->actingAs($this->admin)
            ->post(route('admin.foods.store'), $this->foodPayload($stall, [
                'new_category' => 'Inactive Food Category',
            ]))
            ->assertSessionHasErrors('new_category');
    }

    public function test_non_admins_cannot_create_managed_categories_through_catalog_forms(): void
    {
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);

        $this->actingAs($client)
            ->post(route('admin.stalls.store'), $this->stallPayload(['new_category' => 'No access']))
            ->assertForbidden();
        $this->assertDatabaseMissing('catalog_categories', ['name' => 'No access']);
    }

    /** @param array<string, mixed> $overrides */
    private function stallPayload(array $overrides = [], ?int $marketId = null): array
    {
        return array_merge([
            'night_market_id' => $marketId ?? $this->market->id,
            'name' => 'Managed Category Stall',
            'description' => 'Category test stall.',
            'category' => null,
            'halal_status' => Stall::HALAL_UNKNOWN,
            'status' => Stall::STATUS_ACTIVE,
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function foodPayload(Stall $stall, array $overrides = []): array
    {
        return array_merge([
            'stall_id' => $stall->id,
            'name' => 'Managed Category Food',
            'description' => 'Category test food.',
            'category' => null,
            'is_must_try' => false,
            'status' => Food::STATUS_ACTIVE,
        ], $overrides);
    }
}
