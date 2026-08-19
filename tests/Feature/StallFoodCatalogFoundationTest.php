<?php

namespace Tests\Feature;

use App\Models\Food;
use App\Models\NightMarket;
use App\Models\Stall;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StallFoodCatalogFoundationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_legacy_compatible_records_receive_safe_nullable_defaults(): void
    {
        $market = NightMarket::factory()->create();
        $now = now();
        $stallId = DB::table('stalls')->insertGetId([
            'night_market_id' => $market->id,
            'name' => 'Legacy Compatible Stall',
            'description' => 'Existing description',
            'status' => Stall::STATUS_ACTIVE,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $foodId = DB::table('foods')->insertGetId([
            'stall_id' => $stallId,
            'name' => 'Legacy Compatible Food',
            'description' => 'Preserved description',
            'category' => 'Snack',
            'is_must_try' => true,
            'status' => Food::STATUS_ACTIVE,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $stall = Stall::findOrFail($stallId);
        $food = Food::findOrFail($foodId);
        $this->assertSame(Stall::HALAL_UNKNOWN, $stall->halal_status);
        $this->assertNull($stall->category);
        $this->assertSame('Legacy Compatible Food', $food->name);
        $this->assertSame('Snack', $food->category);
        $this->assertTrue($food->is_must_try);
        $this->assertNull($food->price_min);
        $this->assertSame('Price not available', $food->formattedPrice());
    }

    public function test_admin_can_create_each_evidence_compliant_halal_classification(): void
    {
        $market = NightMarket::factory()->create();
        $admin = $this->admin();
        $cases = [
            Stall::HALAL_CERTIFIED => ['halal_evidence_url' => 'https://verify.example/certified'],
            Stall::HALAL_MUSLIM_OWNED_OR_CLAIMED => ['halal_evidence_url' => 'https://vendor.example/claim'],
            Stall::HALAL_NON_HALAL => ['halal_notes' => 'Vendor menu explicitly lists pork.'],
            Stall::HALAL_UNKNOWN => [],
        ];

        foreach ($cases as $status => $evidence) {
            $name = 'Classification '.$status;
            $this->actingAs($admin)->post(route('admin.stalls.store'), [
                ...$this->stallPayload($market, $name),
                'halal_status' => $status,
                ...$evidence,
            ])->assertRedirect(route('admin.stalls.create'))->assertSessionHasNoErrors();

            $this->assertDatabaseHas('stalls', ['name' => $name, 'halal_status' => $status]);
        }
    }

    public function test_halal_evidence_rules_and_safe_url_schemes_are_enforced(): void
    {
        $market = NightMarket::factory()->create();
        $admin = $this->admin();
        $invalidCases = [
            ['halal_status' => Stall::HALAL_CERTIFIED],
            ['halal_status' => Stall::HALAL_MUSLIM_OWNED_OR_CLAIMED],
            ['halal_status' => Stall::HALAL_NON_HALAL],
            ['halal_status' => 'assumed_halal'],
            ['halal_status' => Stall::HALAL_CERTIFIED, 'halal_evidence_url' => 'javascript:alert(1)'],
            ['halal_status' => Stall::HALAL_UNKNOWN, 'source_url' => 'file:///C:/secret.txt'],
        ];

        foreach ($invalidCases as $index => $case) {
            $response = $this->actingAs($admin)->post(route('admin.stalls.store'), [
                ...$this->stallPayload($market, 'Invalid Evidence '.$index),
                ...$case,
            ]);
            $response->assertSessionHasErrors();
        }

        $this->assertSame(0, Stall::query()->where('name', 'like', 'Invalid Evidence %')->count());
    }

    public function test_invalid_stall_update_preserves_original_metadata_and_unrelated_records(): void
    {
        $market = NightMarket::factory()->create();
        $stall = Stall::factory()->create([
            'night_market_id' => $market->id,
            'name' => 'Preserved Metadata Stall',
            'category' => 'Grill',
            'halal_status' => Stall::HALAL_CERTIFIED,
            'halal_evidence_url' => 'https://verify.example/original',
        ]);
        $unrelated = Stall::factory()->create(['name' => 'Unrelated Metadata Stall']);

        $this->actingAs($this->admin())->patch(route('admin.stalls.update', $stall), [
            ...$this->stallPayload($market, 'Changed Name'),
            'halal_status' => Stall::HALAL_CERTIFIED,
            'halal_evidence_url' => 'data:text/html,bad',
            'role' => User::ROLE_ADMIN,
        ])->assertSessionHasErrors('halal_evidence_url');

        $stall->refresh();
        $this->assertSame('Preserved Metadata Stall', $stall->name);
        $this->assertSame('Grill', $stall->category);
        $this->assertSame('https://verify.example/original', $stall->halal_evidence_url);
        $this->assertSame('Unrelated Metadata Stall', $unrelated->fresh()->name);
    }

    public function test_admin_can_store_fixed_range_display_only_and_null_prices(): void
    {
        $stall = Stall::factory()->create();
        $admin = $this->admin();
        $cases = [
            'Fixed Price Food' => ['price_min' => '5', 'price_max' => '5.00', 'expected' => 'RM5.00'],
            'Range Price Food' => ['price_min' => '5.00', 'price_max' => '8.50', 'expected' => 'RM5.00–RM8.50'],
            'Display Price Food' => ['price_display' => 'RM5 for 4 pieces', 'expected' => 'RM5 for 4 pieces'],
            'Unknown Price Food' => ['expected' => 'Price not available'],
        ];

        foreach ($cases as $name => $price) {
            $expected = $price['expected'];
            unset($price['expected']);
            $this->actingAs($admin)->post(route('admin.foods.store'), [
                ...$this->foodPayload($stall, $name),
                ...$price,
            ])->assertRedirect(route('admin.foods.create'))->assertSessionHasNoErrors();

            $this->assertSame($expected, Food::query()->where('name', $name)->firstOrFail()->formattedPrice());
        }
    }

    public function test_invalid_prices_and_unsafe_food_urls_preserve_the_food(): void
    {
        $food = Food::factory()->create([
            'name' => 'Preserved Price Food',
            'price_min' => '6.00',
            'price_max' => '9.00',
        ]);
        $admin = $this->admin();
        $cases = [
            ['price_min' => '-1', 'price_max' => '5'],
            ['price_min' => '10', 'price_max' => '9'],
            ['price_min' => 'not-a-number', 'price_max' => '12'],
            ['source_url' => 'javascript:alert(1)'],
        ];

        foreach ($cases as $case) {
            $this->actingAs($admin)->patch(route('admin.foods.update', $food), [
                ...$this->foodPayload($food->stall, 'Malicious Change'),
                ...$case,
            ])->assertSessionHasErrors();
        }

        $food->refresh();
        $this->assertSame('Preserved Price Food', $food->name);
        $this->assertSame('6.00', $food->price_min);
        $this->assertSame('9.00', $food->price_max);
    }

    public function test_must_try_reason_is_shown_only_while_enabled(): void
    {
        $food = Food::factory()->mustTry()->create([
            'recommendation_reason' => 'Signature charcoal preparation.',
        ]);

        $this->get(route('foods.show', $food))
            ->assertOk()->assertSee('Signature charcoal preparation.');

        $this->actingAs($this->admin())->patch(route('admin.foods.update', $food), [
            ...$this->foodPayload($food->stall, $food->name),
            'is_must_try' => '0',
            'recommendation_reason' => 'Signature charcoal preparation.',
        ])->assertSessionHasNoErrors();

        $this->assertFalse($food->fresh()->is_must_try);
        $this->get(route('foods.show', $food))
            ->assertOk()->assertDontSee('Signature charcoal preparation.');
    }

    public function test_price_check_and_verification_dates_are_validated_and_presented(): void
    {
        $stall = Stall::factory()->create();
        $checkedDate = today()->subDay();

        $this->actingAs($this->admin())->post(route('admin.foods.store'), [
            ...$this->foodPayload($stall, 'Dated Price Food'),
            'price_display' => 'Market price',
            'price_checked_at' => $checkedDate->toDateString(),
            'verified_at' => $checkedDate->toDateString(),
        ])->assertSessionHasNoErrors();

        $food = Food::query()->where('name', 'Dated Price Food')->firstOrFail();
        $this->get(route('foods.show', $food))
            ->assertOk()
            ->assertSee('Market price')
            ->assertSee($checkedDate->format('M j, Y'));

        $this->actingAs($this->admin())->post(route('admin.foods.store'), [
            ...$this->foodPayload($stall, 'Future Dated Food'),
            'price_checked_at' => today()->addDay()->toDateString(),
            'verified_at' => today()->addDay()->toDateString(),
        ])->assertSessionHasErrors(['price_checked_at', 'verified_at']);

        $this->assertDatabaseMissing('foods', ['name' => 'Future Dated Food']);
    }

    public function test_public_halal_labels_are_distinct_and_unknown_hides_stale_evidence(): void
    {
        $market = NightMarket::factory()->create();
        $statuses = [
            Stall::HALAL_CERTIFIED => 'Halal-certified',
            Stall::HALAL_MUSLIM_OWNED_OR_CLAIMED => 'Muslim-owned/claimed (not certification)',
            Stall::HALAL_NON_HALAL => 'Non-halal',
            Stall::HALAL_UNKNOWN => 'Halal status not verified',
        ];
        foreach ($statuses as $status => $label) {
            Stall::factory()->create([
                'night_market_id' => $market->id,
                'name' => $label.' Stall',
                'halal_status' => $status,
                'halal_evidence_url' => $status === Stall::HALAL_UNKNOWN
                    ? 'https://stale.example/should-not-display'
                    : 'https://evidence.example/'.$status,
            ]);
        }

        $response = $this->get(route('night-markets.stalls.index', $market));
        foreach ($statuses as $label) {
            $response->assertSee($label);
        }
        $response->assertDontSee('https://stale.example/should-not-display', false);
    }

    public function test_admin_metadata_filters_combine_and_preserve_pagination_queries(): void
    {
        $market = NightMarket::factory()->create();
        $matching = Stall::factory()->create([
            'night_market_id' => $market->id,
            'name' => 'Matching Metadata Stall',
            'category' => 'Grill',
            'halal_status' => Stall::HALAL_CERTIFIED,
        ]);
        Stall::factory()->create([
            'night_market_id' => $market->id,
            'name' => 'Wrong Metadata Stall',
            'category' => 'Drinks',
            'halal_status' => Stall::HALAL_UNKNOWN,
        ]);
        Stall::factory()->count(15)->create([
            'night_market_id' => $market->id,
            'category' => 'Grill',
            'halal_status' => Stall::HALAL_CERTIFIED,
        ]);

        $this->actingAs($this->admin())->get(route('admin.stalls.index', [
            'night_market_id' => $market->id,
            'category' => 'Grill',
            'halal_status' => Stall::HALAL_CERTIFIED,
            'status' => Stall::STATUS_ACTIVE,
        ]))->assertOk()
            ->assertSee($matching->name)
            ->assertDontSee('Wrong Metadata Stall')
            ->assertSee('Page 1 of 2')
            ->assertSee('category=Grill', false)
            ->assertSee('halal_status=halal_certified', false);

        $matchingFood = Food::factory()->mustTry()->create([
            'stall_id' => $matching->id,
            'name' => 'Matching Filter Food',
            'category' => 'Snack',
        ]);
        Food::factory()->create([
            'stall_id' => $matching->id,
            'name' => 'Wrong Filter Food',
            'category' => 'Drink',
        ]);
        Food::factory()->count(15)->mustTry()->create([
            'stall_id' => $matching->id,
            'category' => 'Snack',
        ]);

        $this->actingAs($this->admin())->get(route('admin.foods.index', [
            'night_market_id' => $market->id,
            'stall_id' => $matching->id,
            'category' => 'Snack',
            'is_must_try' => '1',
            'status' => Food::STATUS_ACTIVE,
        ]))->assertOk()
            ->assertSee($matchingFood->name)
            ->assertDontSee('Wrong Filter Food')
            ->assertSee('Page 1 of 2')
            ->assertSee('category=Snack', false)
            ->assertSee('is_must_try=1', false);
    }

    public function test_guest_and_client_cannot_manage_metadata(): void
    {
        $stall = Stall::factory()->create();
        $food = Food::factory()->create(['stall_id' => $stall->id]);

        $this->patch(route('admin.stalls.update', $stall))->assertRedirect(route('login'));
        $this->patch(route('admin.foods.update', $food))->assertRedirect(route('login'));

        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $this->actingAs($client)->patch(route('admin.stalls.update', $stall))->assertForbidden();
        $this->actingAs($client)->patch(route('admin.foods.update', $food))->assertForbidden();
    }

    /** @return array<string, mixed> */
    private function stallPayload(NightMarket $market, string $name): array
    {
        return [
            'night_market_id' => $market->id,
            'name' => $name,
            'description' => 'Structured stall metadata.',
            'category' => 'Prepared Food',
            'halal_status' => Stall::HALAL_UNKNOWN,
            'status' => Stall::STATUS_ACTIVE,
        ];
    }

    /** @return array<string, mixed> */
    private function foodPayload(Stall $stall, string $name): array
    {
        return [
            'stall_id' => $stall->id,
            'name' => $name,
            'description' => 'Structured food metadata.',
            'category' => 'Snack',
            'is_must_try' => '1',
            'recommendation_reason' => 'A directly recorded recommendation.',
            'status' => Food::STATUS_ACTIVE,
        ];
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }
}
