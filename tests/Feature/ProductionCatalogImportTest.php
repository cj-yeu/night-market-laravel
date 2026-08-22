<?php

namespace Tests\Feature;

use App\Models\Food;
use App\Models\MarketOperatingDay;
use App\Models\NightMarket;
use App\Models\Stall;
use App\Services\CatalogWorkbookImportService;
use Database\Seeders\ProductionCatalogSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProductionCatalogImportTest extends TestCase
{
    use DatabaseTransactions;

    public function test_bundled_workbook_matches_the_reviewed_integrity_and_record_contract(): void
    {
        $path = database_path(CatalogWorkbookImportService::PRODUCTION_FILE);

        $this->assertFileExists($path);
        $this->assertSame(
            CatalogWorkbookImportService::PRODUCTION_SHA256,
            hash_file('sha256', $path),
        );

        $report = app(CatalogWorkbookImportService::class)->runProduction(false);

        $this->assertSame([], $report['errors']);
        $this->assertFalse($report['applied']);
        $this->assertSame(CatalogWorkbookImportService::PRODUCTION_COUNTS, $report['records']);
        $this->assertSame(17, $report['counts']['markets']['validated']);
        $this->assertSame(19, $report['counts']['schedules']['validated']);
        $this->assertSame(21, $report['counts']['stalls']['validated']);
        $this->assertSame(21, $report['counts']['foods']['validated']);
    }

    public function test_production_command_defaults_to_a_non_writing_dry_run(): void
    {
        $before = $this->databaseCounts();

        $this->artisan('catalog:import-production')
            ->expectsOutputToContain('Mode: dry-run')
            ->expectsOutputToContain('SHA-256 verified')
            ->expectsOutputToContain('No database records were changed.')
            ->assertSuccessful();

        $this->assertSame($before, $this->databaseCounts());

        $this->artisan('catalog:import-production', ['--dry-run' => true, '--apply' => true])
            ->expectsOutputToContain('Choose either --dry-run or --apply')
            ->assertExitCode(2);
        $this->artisan('catalog:import-production', ['--force' => true])
            ->expectsOutputToContain('--force is valid only together with --apply')
            ->assertExitCode(2);
    }

    public function test_apply_imports_only_the_active_reviewed_catalog_and_preserves_unknown_halal(): void
    {
        $unrelated = NightMarket::factory()->create([
            'name' => 'Existing Unrelated Market',
            'catalog_code' => null,
        ]);

        $this->artisan('catalog:import-production', ['--apply' => true, '--force' => true])
            ->expectsOutputToContain('Production catalog applied successfully.')
            ->assertSuccessful();

        $this->assertSame(17, NightMarket::query()->whereNotNull('catalog_code')->count());
        $this->assertSame(19, MarketOperatingDay::query()
            ->whereHas('nightMarket', fn ($query) => $query->whereNotNull('catalog_code'))
            ->count());
        $this->assertSame(21, Stall::query()->whereNotNull('catalog_code')->count());
        $this->assertSame(21, Food::query()->whereNotNull('catalog_code')->count());

        $this->assertSame(17, NightMarket::query()->whereNotNull('catalog_code')->where('status', NightMarket::STATUS_ACTIVE)->count());
        $this->assertSame(21, Stall::query()->whereNotNull('catalog_code')->where('status', Stall::STATUS_ACTIVE)->count());
        $this->assertSame(21, Food::query()->whereNotNull('catalog_code')->where('status', Food::STATUS_ACTIVE)->count());
        $this->assertSame(21, Stall::query()->whereNotNull('catalog_code')->where('halal_status', Stall::HALAL_UNKNOWN)->count());
        $this->assertSame(0, Stall::query()->whereNotNull('catalog_code')->whereNotNull('halal_evidence_url')->count());

        $this->assertDatabaseHas('night_markets', ['id' => $unrelated->id, 'name' => 'Existing Unrelated Market', 'catalog_code' => null]);
        $this->assertDatabaseMissing('night_markets', ['name' => 'Pasar Malam Petaling Garden']);
        $this->assertDatabaseMissing('night_markets', ['catalog_code' => 'MBPJ-PETALING-GARDEN']);
        $this->assertSame(0, NightMarket::query()->whereNotNull('catalog_code')->whereNotNull('image_path')->count());
        $this->assertSame(0, Stall::query()->whereNotNull('catalog_code')->whereNotNull('image_path')->count());
        $this->assertSame(0, Food::query()->whereNotNull('catalog_code')->whereNotNull('image_path')->count());

        $this->assertTrue(Stall::query()->whereNotNull('catalog_code')->get()->every(
            fn (Stall $stall): bool => $stall->nightMarket?->catalog_code !== null,
        ));
        $this->assertTrue(Food::query()->whereNotNull('catalog_code')->get()->every(
            fn (Food $food): bool => $food->stall?->catalog_code !== null,
        ));
    }

    public function test_production_command_and_seeder_are_idempotent(): void
    {
        $this->artisan('catalog:import-production', ['--apply' => true, '--force' => true])->assertSuccessful();
        $firstIds = [
            'markets' => NightMarket::query()->whereNotNull('catalog_code')->orderBy('catalog_code')->pluck('id')->all(),
            'stalls' => Stall::query()->whereNotNull('catalog_code')->orderBy('catalog_code')->pluck('id')->all(),
            'foods' => Food::query()->whereNotNull('catalog_code')->orderBy('catalog_code')->pluck('id')->all(),
        ];

        $report = app(CatalogWorkbookImportService::class)->runProduction(false);
        foreach (['markets', 'schedules', 'stalls', 'foods'] as $entity) {
            $this->assertSame(0, $report['counts'][$entity]['created']);
            $this->assertSame(0, $report['counts'][$entity]['updated']);
            $this->assertSame(CatalogWorkbookImportService::PRODUCTION_COUNTS[
                match ($entity) {
                    'markets' => 'NightMarkets',
                    'schedules' => 'MarketSchedules',
                    'stalls' => 'Stalls',
                    'foods' => 'Foods',
                }
                ], $report['counts'][$entity]['unchanged']);
        }

        $this->seed(ProductionCatalogSeeder::class);

        $this->assertSame($firstIds['markets'], NightMarket::query()->whereNotNull('catalog_code')->orderBy('catalog_code')->pluck('id')->all());
        $this->assertSame($firstIds['stalls'], Stall::query()->whereNotNull('catalog_code')->orderBy('catalog_code')->pluck('id')->all());
        $this->assertSame($firstIds['foods'], Food::query()->whereNotNull('catalog_code')->orderBy('catalog_code')->pluck('id')->all());
        $this->assertSame(19, MarketOperatingDay::query()->whereHas('nightMarket', fn ($query) => $query->whereNotNull('catalog_code'))->count());
    }

    /** @return array{markets: int, schedules: int, stalls: int, foods: int} */
    private function databaseCounts(): array
    {
        return [
            'markets' => NightMarket::query()->count(),
            'schedules' => MarketOperatingDay::query()->count(),
            'stalls' => Stall::query()->count(),
            'foods' => Food::query()->count(),
        ];
    }
}
