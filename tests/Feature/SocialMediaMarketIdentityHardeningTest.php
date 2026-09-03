<?php

namespace Tests\Feature;

use App\Models\NightMarket;
use App\Support\CatalogMarketIdentity;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SocialMediaMarketIdentityHardeningTest extends TestCase
{
    use DatabaseTransactions;

    public function test_identity_normalization_is_deterministic_and_unicode_safe(): void
    {
        $first = CatalogMarketIdentity::hash(
            '  PASAR   MALAM ŚEKSYEN 7 ',
            " Jalan  Plumbum\t7/102 ",
            '  SHAH   ALAM ',
            ' SELANGOR ',
        );
        $second = CatalogMarketIdentity::hash(
            'pasar malam śeksyen 7',
            'jalan plumbum 7/102',
            'shah alam',
            'selangor',
        );

        $this->assertSame($first, $second);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $first);
    }

    public function test_every_identity_field_contributes_to_the_hash(): void
    {
        $baseline = CatalogMarketIdentity::hash('Market', 'Address', 'City', 'Selangor');

        $this->assertNotSame($baseline, CatalogMarketIdentity::hash('Other Market', 'Address', 'City', 'Selangor'));
        $this->assertNotSame($baseline, CatalogMarketIdentity::hash('Market', 'Other Address', 'City', 'Selangor'));
        $this->assertNotSame($baseline, CatalogMarketIdentity::hash('Market', 'Address', 'Other City', 'Selangor'));
        $this->assertNotSame($baseline, CatalogMarketIdentity::hash('Market', 'Address', 'City', 'Johor'));
    }

    public function test_model_populates_and_recomputes_identity_hash_only_for_identity_changes(): void
    {
        $market = NightMarket::factory()->create([
            'name' => 'Identity Market',
            'address' => '1 Market Road',
            'city' => 'Shah Alam',
            'state' => 'Selangor',
        ]);

        $originalHash = CatalogMarketIdentity::hash(
            'Identity Market',
            '1 Market Road',
            'Shah Alam',
            'Selangor',
        );

        $this->assertSame($originalHash, $market->catalog_identity_hash);

        $market->update(['description' => 'An unrelated field changed.']);
        $this->assertSame($originalHash, $market->fresh()->catalog_identity_hash);

        $market->update(['city' => 'Petaling Jaya']);
        $this->assertSame(
            CatalogMarketIdentity::hash('Identity Market', '1 Market Road', 'Petaling Jaya', 'Selangor'),
            $market->fresh()->catalog_identity_hash,
        );
    }

    public function test_hardening_columns_exist_and_legacy_null_identities_can_coexist(): void
    {
        $this->assertTrue(Schema::hasColumns('catalog_import_proposals', [
            'review_metadata_snapshot',
            'review_input_hash',
            'extraction_attempt_token',
            'extraction_attempt_started_at',
        ]));
        $this->assertTrue(Schema::hasColumn('night_markets', 'catalog_identity_hash'));

        $legacy = [
            'name' => 'Legacy Duplicate',
            'address' => 'Legacy Address',
            'city' => 'Legacy City',
            'state' => 'Selangor',
            'status' => NightMarket::STATUS_INACTIVE,
            'catalog_identity_hash' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('night_markets')->insert([$legacy, $legacy]);

        $this->assertSame(2, DB::table('night_markets')->where('name', 'Legacy Duplicate')->count());
    }

    public function test_unique_index_rejects_duplicate_normalized_market_identities(): void
    {
        NightMarket::factory()->create([
            'name' => 'Duplicate Market',
            'address' => '8 Same Road',
            'city' => 'Klang',
            'state' => 'Selangor',
        ]);

        $this->expectException(QueryException::class);

        NightMarket::factory()->create([
            'name' => ' duplicate   market ',
            'address' => ' 8 same road ',
            'city' => ' KLANG ',
            'state' => ' selangor ',
        ]);
    }
}
