<?php

namespace Tests\Feature;

use App\Models\Food;
use App\Models\MarketOperatingDay;
use App\Models\NightMarket;
use App\Models\Stall;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminMarketCatalogManagementTest extends TestCase
{
    use DatabaseTransactions;

    public function test_management_routes_enforce_admin_authorization(): void
    {
        $market = $this->marketWithSchedule();
        $stall = Stall::factory()->create(['night_market_id' => $market->id]);
        $food = Food::factory()->create(['stall_id' => $stall->id]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);

        $getRoutes = [
            route('admin.night-markets.index'),
            route('admin.night-markets.create'),
            route('admin.night-markets.show', $market),
            route('admin.night-markets.edit', $market),
            route('admin.stalls.index'),
            route('admin.stalls.create'),
            route('admin.stalls.show', $stall),
            route('admin.stalls.edit', $stall),
            route('admin.foods.index'),
            route('admin.foods.create'),
            route('admin.foods.show', $food),
            route('admin.foods.edit', $food),
        ];

        foreach ($getRoutes as $url) {
            auth()->logout();
            $this->get($url)->assertRedirect(route('login'));
            $this->actingAs($client)->get($url)->assertForbidden();
        }

        $mutationRoutes = [
            ['POST', route('admin.night-markets.store')],
            ['PATCH', route('admin.night-markets.update', $market)],
            ['PATCH', route('admin.night-markets.activate', $market)],
            ['PATCH', route('admin.night-markets.deactivate', $market)],
            ['POST', route('admin.stalls.store')],
            ['PATCH', route('admin.stalls.update', $stall)],
            ['PATCH', route('admin.stalls.activate', $stall)],
            ['PATCH', route('admin.stalls.deactivate', $stall)],
            ['POST', route('admin.foods.store')],
            ['PATCH', route('admin.foods.update', $food)],
            ['PATCH', route('admin.foods.activate', $food)],
            ['PATCH', route('admin.foods.deactivate', $food)],
        ];

        foreach ($mutationRoutes as [$method, $url]) {
            auth()->logout();
            $this->call($method, $url)->assertRedirect(route('login'));
            $this->actingAs($client)->call($method, $url)->assertForbidden();
        }
    }

    public function test_admin_can_access_all_management_pages(): void
    {
        $admin = $this->admin();
        $market = $this->marketWithSchedule();
        $stall = Stall::factory()->create(['night_market_id' => $market->id]);
        $food = Food::factory()->create(['stall_id' => $stall->id]);

        foreach ([
            route('admin.night-markets.index'), route('admin.night-markets.create'),
            route('admin.night-markets.show', $market), route('admin.night-markets.edit', $market),
            route('admin.stalls.index'), route('admin.stalls.create'),
            route('admin.stalls.show', $stall), route('admin.stalls.edit', $stall),
            route('admin.foods.index'), route('admin.foods.create'),
            route('admin.foods.show', $food), route('admin.foods.edit', $food),
        ] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
    }

    public function test_existing_create_flows_still_create_market_stall_and_food(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.night-markets.store'), [
            'name' => 'Created Market',
            'address' => '1 Creation Road',
            'city' => 'Petaling Jaya',
            'description' => 'Created through the existing flow.',
            'status' => NightMarket::STATUS_ACTIVE,
            'operating_days' => [
                ['day_of_week' => 'Wednesday', 'opening_time' => '17:00', 'closing_time' => '22:00'],
            ],
        ])->assertRedirect(route('admin.night-markets.create'));

        $market = NightMarket::query()->where('name', 'Created Market')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.stalls.store'), [
            'night_market_id' => $market->id,
            'name' => 'Created Stall',
            'description' => 'Created stall.',
            'status' => Stall::STATUS_ACTIVE,
        ])->assertRedirect(route('admin.stalls.create'));

        $stall = Stall::query()->where('name', 'Created Stall')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.foods.store'), [
            'stall_id' => $stall->id,
            'name' => 'Created Food',
            'description' => 'Created food.',
            'category' => 'Snack',
            'is_must_try' => '1',
            'status' => Food::STATUS_ACTIVE,
        ])->assertRedirect(route('admin.foods.create'));

        $this->assertDatabaseHas('market_operating_days', [
            'night_market_id' => $market->id,
            'day_of_week' => 'Wednesday',
        ]);
        $this->assertDatabaseHas('stalls', ['id' => $stall->id, 'night_market_id' => $market->id]);
        $this->assertDatabaseHas('foods', ['stall_id' => $stall->id, 'name' => 'Created Food']);
    }

    public function test_admin_updates_only_the_route_bound_market_and_its_schedule(): void
    {
        $admin = $this->admin();
        $market = $this->marketWithSchedule(['name' => 'Original Market']);
        $other = $this->marketWithSchedule(['name' => 'Unrelated Market']);

        $this->actingAs($admin)->patch(route('admin.night-markets.update', $market), [
            'name' => '  Updated   Market  ',
            'address' => '  10   Updated Road ',
            'city' => ' Shah Alam ',
            'description' => 'Updated description.',
            'status' => NightMarket::STATUS_INACTIVE,
            'state' => 'Outside Selangor',
            'operating_days' => [
                ['day_of_week' => 'Tuesday', 'opening_time' => '17:30', 'closing_time' => '22:30'],
                ['day_of_week' => 'Saturday', 'opening_time' => '18:00', 'closing_time' => '23:00'],
            ],
        ])->assertRedirect(route('admin.night-markets.show', $market));

        $market->refresh();
        $this->assertSame('Updated Market', $market->name);
        $this->assertSame('10 Updated Road', $market->address);
        $this->assertSame('Shah Alam', $market->city);
        $this->assertSame('Selangor', $market->state);
        $this->assertSame(NightMarket::STATUS_ACTIVE, $market->status);
        $this->assertDatabaseHas('market_operating_days', [
            'night_market_id' => $market->id,
            'day_of_week' => 'Saturday',
            'opening_time' => '18:00:00',
        ]);
        $this->assertSame(2, $market->operatingDays()->count());
        $this->assertSame('Unrelated Market', $other->fresh()->name);
    }

    public function test_invalid_market_update_preserves_the_market_and_schedule(): void
    {
        $market = $this->marketWithSchedule(['name' => 'Preserved Market']);
        $originalDay = $market->operatingDays()->first();
        $originalSchedule = [
            $originalDay->day_of_week,
            $originalDay->opening_time->format('H:i'),
            $originalDay->closing_time->format('H:i'),
        ];

        $this->actingAs($this->admin())
            ->from(route('admin.night-markets.edit', $market))
            ->patch(route('admin.night-markets.update', $market), [
                'name' => '',
                'address' => '',
                'city' => '',
                'operating_days' => [
                    ['day_of_week' => 'Funday', 'opening_time' => '25:00', 'closing_time' => 'bad'],
                ],
            ])
            ->assertRedirect(route('admin.night-markets.edit', $market))
            ->assertSessionHasErrors(['name', 'address', 'city', 'operating_days.0.day_of_week']);

        $this->assertSame('Preserved Market', $market->fresh()->name);
        $preservedDay = $market->operatingDays()->first();
        $this->assertSame($originalSchedule, [
            $preservedDay->day_of_week,
            $preservedDay->opening_time->format('H:i'),
            $preservedDay->closing_time->format('H:i'),
        ]);
    }

    public function test_market_status_is_idempotent_and_parent_visibility_does_not_overwrite_children(): void
    {
        $market = $this->marketWithSchedule();
        $stall = Stall::factory()->create(['night_market_id' => $market->id, 'status' => Stall::STATUS_ACTIVE]);
        $food = Food::factory()->create(['stall_id' => $stall->id, 'status' => Food::STATUS_ACTIVE]);
        $other = NightMarket::factory()->create(['status' => NightMarket::STATUS_ACTIVE]);
        $admin = $this->admin();

        $this->actingAs($admin)->patch(route('admin.night-markets.deactivate', $market))->assertRedirect(route('admin.night-markets.index'));
        $this->actingAs($admin)->patch(route('admin.night-markets.deactivate', $market))->assertRedirect(route('admin.night-markets.index'));

        $this->assertSame(NightMarket::STATUS_INACTIVE, $market->fresh()->status);
        $this->assertSame(Stall::STATUS_ACTIVE, $stall->fresh()->status);
        $this->assertSame(Food::STATUS_ACTIVE, $food->fresh()->status);
        $this->assertSame(NightMarket::STATUS_ACTIVE, $other->fresh()->status);
        $this->get(route('night-markets.show', $market))->assertNotFound();
        $this->get(route('night-markets.stalls.index', $market))->assertNotFound();
        $this->get(route('foods.show', $food))->assertNotFound();

        $this->actingAs($admin)->patch(route('admin.night-markets.activate', $market));
        $this->get(route('night-markets.show', $market))->assertOk()->assertSee($stall->name);
        $this->get(route('foods.show', $food))->assertOk();
    }

    public function test_stall_update_validates_parent_and_preserves_unrelated_records(): void
    {
        $firstMarket = NightMarket::factory()->create();
        $secondMarket = NightMarket::factory()->create();
        $stall = Stall::factory()->create(['night_market_id' => $firstMarket->id, 'name' => 'Original Stall']);
        $unrelatedStall = Stall::factory()->create(['night_market_id' => $firstMarket->id, 'name' => 'Unrelated Stall']);
        $food = Food::factory()->create(['stall_id' => $stall->id, 'name' => 'Preserved Food']);

        $this->actingAs($this->admin())->patch(route('admin.stalls.update', $stall), [
            'night_market_id' => $secondMarket->id,
            'name' => ' Updated Stall ',
            'description' => 'Updated.',
            'status' => Stall::STATUS_INACTIVE,
        ])->assertRedirect(route('admin.stalls.show', $stall));

        $stall->refresh();
        $this->assertSame($secondMarket->id, $stall->night_market_id);
        $this->assertSame('Updated Stall', $stall->name);
        $this->assertSame(Stall::STATUS_ACTIVE, $stall->status);
        $this->assertSame($stall->id, $food->fresh()->stall_id);
        $this->assertSame('Unrelated Stall', $unrelatedStall->fresh()->name);

        $this->actingAs($this->admin())->patch(route('admin.stalls.update', $stall), [
            'night_market_id' => 999999999,
            'name' => 'Malicious Reassignment',
        ])->assertSessionHasErrors('night_market_id');
        $this->assertSame($secondMarket->id, $stall->fresh()->night_market_id);
        $this->assertSame('Updated Stall', $stall->fresh()->name);
    }

    public function test_stall_status_hides_and_restores_eligible_foods_without_changing_food_status(): void
    {
        $market = NightMarket::factory()->create();
        $stall = Stall::factory()->create(['night_market_id' => $market->id]);
        $food = Food::factory()->create(['stall_id' => $stall->id]);
        $otherFood = Food::factory()->create();
        $admin = $this->admin();

        $this->actingAs($admin)->patch(route('admin.stalls.deactivate', $stall));
        $this->actingAs($admin)->patch(route('admin.stalls.deactivate', $stall));
        $this->get(route('foods.show', $food))->assertNotFound();
        $this->assertSame(Food::STATUS_ACTIVE, $food->fresh()->status);
        $this->assertSame(Food::STATUS_ACTIVE, $otherFood->fresh()->status);

        $this->actingAs($admin)->patch(route('admin.stalls.activate', $stall));
        $this->get(route('foods.show', $food))->assertOk();
    }

    public function test_food_update_validates_parent_normalizes_boolean_and_preserves_other_foods(): void
    {
        $firstStall = Stall::factory()->create();
        $secondStall = Stall::factory()->create();
        $food = Food::factory()->create(['stall_id' => $firstStall->id, 'name' => 'Original Food', 'is_must_try' => false]);
        $otherFood = Food::factory()->create(['name' => 'Unrelated Food']);

        $this->actingAs($this->admin())->patch(route('admin.foods.update', $food), [
            'stall_id' => $secondStall->id,
            'name' => ' Updated Food ',
            'description' => 'Updated.',
            'category' => ' Local Snack ',
            'is_must_try' => 'on',
            'status' => Food::STATUS_INACTIVE,
        ])->assertRedirect(route('admin.foods.show', $food));

        $food->refresh();
        $this->assertSame($secondStall->id, $food->stall_id);
        $this->assertSame('Updated Food', $food->name);
        $this->assertSame('Local Snack', $food->category);
        $this->assertTrue($food->is_must_try);
        $this->assertSame(Food::STATUS_ACTIVE, $food->status);
        $this->assertSame('Unrelated Food', $otherFood->fresh()->name);

        $this->actingAs($this->admin())->patch(route('admin.foods.update', $food), [
            'stall_id' => 999999999,
            'name' => 'Invalid Food',
            'is_must_try' => '0',
        ])->assertSessionHasErrors('stall_id');
        $this->assertSame($secondStall->id, $food->fresh()->stall_id);
        $this->assertSame('Updated Food', $food->fresh()->name);
    }

    public function test_food_status_is_idempotent_and_targets_only_the_bound_food(): void
    {
        $food = Food::factory()->create();
        $otherFood = Food::factory()->create();
        $admin = $this->admin();

        $this->actingAs($admin)->patch(route('admin.foods.deactivate', $food));
        $this->actingAs($admin)->patch(route('admin.foods.deactivate', $food));
        $this->assertSame(Food::STATUS_INACTIVE, $food->fresh()->status);
        $this->assertSame(Food::STATUS_ACTIVE, $otherFood->fresh()->status);
        $this->get(route('foods.show', $food))->assertNotFound();

        $this->actingAs($admin)->patch(route('admin.foods.activate', $food));
        $this->get(route('foods.show', $food))->assertOk();
    }

    public function test_admin_list_filters_treat_percent_and_underscore_as_literals(): void
    {
        $admin = $this->admin();
        $literalMarket = $this->marketWithSchedule(['name' => 'Market 100%_Safe', 'status' => NightMarket::STATUS_INACTIVE]);
        $ordinaryMarket = $this->marketWithSchedule(['name' => 'Market 100X Safe']);
        $literalStall = Stall::factory()->create(['night_market_id' => $literalMarket->id, 'name' => 'Stall %_ Match']);
        Stall::factory()->create(['night_market_id' => $ordinaryMarket->id, 'name' => 'Stall XY Match']);
        $literalFood = Food::factory()->create(['stall_id' => $literalStall->id, 'name' => 'Food %_ Match', 'is_must_try' => true]);
        Food::factory()->create(['name' => 'Food XY Match']);

        $this->actingAs($admin)->get(route('admin.night-markets.index', ['search' => '%_', 'status' => 'inactive']))
            ->assertOk()->assertSee($literalMarket->name)->assertDontSee($ordinaryMarket->name);
        $this->actingAs($admin)->get(route('admin.stalls.index', ['search' => '%_', 'night_market_id' => $literalMarket->id]))
            ->assertOk()->assertSee($literalStall->name)->assertDontSee('Stall XY Match');
        $this->actingAs($admin)->get(route('admin.foods.index', [
            'search' => '%_', 'night_market_id' => $literalMarket->id, 'stall_id' => $literalStall->id,
            'is_must_try' => '1', 'status' => Food::STATUS_ACTIVE,
        ]))->assertOk()->assertSee($literalFood->name)->assertDontSee('Food XY Match');
    }

    public function test_admin_lists_paginate_preserve_filters_and_do_not_add_relationship_queries_per_row(): void
    {
        $admin = $this->admin();
        $market = NightMarket::factory()->create();
        $stalls = Stall::factory()->count(16)->create(['night_market_id' => $market->id]);
        foreach ($stalls as $stall) {
            Food::factory()->create(['stall_id' => $stall->id, 'category' => 'Query Safe']);
        }

        $response = $this->actingAs($admin)->get(route('admin.stalls.index', [
            'night_market_id' => $market->id,
            'status' => Stall::STATUS_ACTIVE,
        ]));
        $response->assertOk()
            ->assertSee('Page 1 of 2')
            ->assertSee('night_market_id='.$market->id, false)
            ->assertSee('status=active', false);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get(route('admin.foods.index'))->assertOk();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(10, $queryCount);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function marketWithSchedule(array $attributes = []): NightMarket
    {
        $market = NightMarket::factory()->create($attributes);
        MarketOperatingDay::factory()->create([
            'night_market_id' => $market->id,
            'day_of_week' => 'Monday',
            'opening_time' => '18:00',
            'closing_time' => '22:00',
        ]);

        return $market;
    }
}
