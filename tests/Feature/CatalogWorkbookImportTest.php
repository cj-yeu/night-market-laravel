<?php

namespace Tests\Feature;

use App\Models\Food;
use App\Models\NightMarket;
use App\Models\Stall;
use App\Services\CatalogWorkbookImportService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class CatalogWorkbookImportTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            File::delete($file);
        }

        parent::tearDown();
    }

    public function test_missing_sheet_and_invalid_headers_fail_without_writes(): void
    {
        $missing = $this->fixture([], ['Foods']);
        $this->artisan('catalog:import-cleaned-workbook', ['--file' => $missing, '--apply' => true])
            ->expectsOutputToContain('Required sheet Foods must exist exactly once.')
            ->assertFailed();

        $reordered = $this->fixture([], [], ['Stalls' => ['market_code', 'stall_code']]);
        $this->artisan('catalog:import-cleaned-workbook', ['--file' => $reordered, '--apply' => true])
            ->expectsOutputToContain('Stalls headers are invalid or out of order.')
            ->assertFailed();

        $this->assertDatabaseCount('night_markets', 0);
    }

    public function test_duplicate_codes_and_broken_relationships_fail_preflight(): void
    {
        $duplicate = $this->fixture([], [], [], ['Foods' => [$this->foodRow()]]);
        $this->artisan('catalog:import-cleaned-workbook', ['--file' => $duplicate, '--apply' => true])
            ->expectsOutputToContain('duplicate food_code FOOD-001')
            ->assertFailed();

        foreach ([
            ['MarketSchedules', 'market_code', 'MISSING', 'does not reference an imported Night Market'],
            ['Stalls', 'market_code', 'MISSING', 'does not reference an imported Night Market'],
            ['Foods', 'stall_code', 'MISSING', 'does not reference an imported Stall'],
        ] as [$sheet, $field, $value, $message]) {
            $path = $this->fixture([$sheet => [$field => $value]]);
            $this->artisan('catalog:import-cleaned-workbook', ['--file' => $path, '--apply' => true])
                ->expectsOutputToContain($message)
                ->assertFailed();
        }

        $this->assertDatabaseCount('night_markets', 0);
    }

    public function test_invalid_values_are_rejected_before_any_write(): void
    {
        $cases = [
            ['MarketSchedules', 'day_of_week', 'Funday', 'approved English day'],
            ['MarketSchedules', 'opening_time', '6pm', 'must use HH:MM'],
            ['NightMarkets', 'source_date', '20/08/2026', 'must use YYYY-MM-DD'],
            ['Stalls', 'source_url', 'javascript:alert(1)', 'valid HTTP/HTTPS URL'],
            ['Foods', 'status', 'Archived', 'status must be Active or Inactive'],
            ['Stalls', 'halal_status', 'Probably', 'not an approved Stall classification'],
            ['Foods', 'must_try', 'Maybe', 'must_try must be Yes or No'],
            ['Foods', 'price_min', '-1', 'non-negative decimal'],
            ['Foods', 'price_min', '12', 'price_max must be greater than or equal'],
            ['Foods', 'food_name', 'Not stated', 'must be a real entity name'],
        ];

        foreach ($cases as [$sheet, $field, $value, $message]) {
            $overrides = [$sheet => [$field => $value]];
            if ($field === 'price_min' && $value === '12') {
                $overrides[$sheet]['price_max'] = '10';
            }
            $path = $this->fixture($overrides);
            $this->artisan('catalog:import-cleaned-workbook', ['--file' => $path, '--apply' => true])
                ->expectsOutputToContain($message)
                ->assertFailed();
        }

        $this->assertDatabaseCount('night_markets', 0);
    }

    public function test_default_and_explicit_dry_runs_never_write(): void
    {
        $path = $this->fixture();

        $this->artisan('catalog:import-cleaned-workbook', ['--file' => $path])
            ->expectsOutputToContain('Mode: dry-run')
            ->expectsOutputToContain('No database records were changed.')
            ->assertSuccessful();
        $this->assertDatabaseCount('night_markets', 0);

        $this->artisan('catalog:import-cleaned-workbook', ['--file' => $path, '--dry-run' => true])
            ->assertSuccessful();
        $this->assertDatabaseCount('night_markets', 0);
    }

    public function test_catalog_codes_are_internal_and_not_browser_mass_assignable(): void
    {
        foreach ([new NightMarket, new Stall, new Food] as $model) {
            $model->fill(['catalog_code' => 'BROWSER-SUPPLIED']);

            $this->assertNull($model->catalog_code);
            $this->assertArrayNotHasKey('catalog_code', $model->forceFill(['catalog_code' => 'INTERNAL'])->toArray());
        }
    }

    public function test_path_controls_reject_traversal_absolute_remote_missing_and_conflicting_modes(): void
    {
        foreach (['../outside.xlsx', 'C:/outside.xlsx', 'https://example.test/file.xlsx', 'imports/missing.xlsx'] as $path) {
            $this->artisan('catalog:import-cleaned-workbook', ['--file' => $path])->assertFailed();
        }

        $this->artisan('catalog:import-cleaned-workbook', ['--file' => $this->fixture(), '--dry-run' => true, '--apply' => true])
            ->expectsOutputToContain('Choose either --dry-run or --apply')
            ->assertExitCode(2);

        $this->assertDatabaseCount('night_markets', 0);
    }

    public function test_apply_maps_supported_fields_ignores_other_sheets_and_is_idempotent(): void
    {
        $path = $this->fixture([], [], [], [
            'NeedsVerification' => [['NM-FAKE', 'must never import']],
            'RAW_MBPJ Night Market' => [['NM-RAW', 'must never import']],
        ]);

        $this->artisan('catalog:import-cleaned-workbook', ['--file' => $path, '--apply' => true])->assertSuccessful();

        $market = NightMarket::where('catalog_code', 'MKT-001')->firstOrFail();
        $stall = Stall::where('catalog_code', 'STALL-001')->firstOrFail();
        $food = Food::where('catalog_code', 'FOOD-001')->firstOrFail();

        $this->assertSame($market->id, $stall->night_market_id);
        $this->assertSame($stall->id, $food->stall_id);
        $this->assertSame(Stall::HALAL_UNKNOWN, $stall->halal_status);
        $this->assertTrue($food->is_must_try);
        $this->assertSame('5.00', $food->price_min);
        $this->assertSame('8.50', $food->price_max);
        $this->assertSame('RM5.00–RM8.50', $food->price_display);
        $this->assertSame('https://example.test/stall', $stall->source_url);
        $this->assertSame('2026-08-18', $stall->verified_at->format('Y-m-d'));
        $this->assertNull($market->image_path);
        $this->assertNull($stall->image_path);
        $this->assertNull($food->image_path);
        $this->assertDatabaseCount('market_operating_days', 1);
        $this->assertDatabaseMissing('night_markets', ['catalog_code' => 'NM-FAKE']);
        $this->assertDatabaseMissing('night_markets', ['catalog_code' => 'NM-RAW']);

        $this->artisan('catalog:import-cleaned-workbook', ['--file' => $path, '--apply' => true])
            ->expectsOutputToContain('Unchanged')
            ->assertSuccessful();
        $this->assertDatabaseCount('night_markets', 1);
        $this->assertDatabaseCount('stalls', 1);
        $this->assertDatabaseCount('foods', 1);
        $this->assertDatabaseCount('market_operating_days', 1);

        $report = app(CatalogWorkbookImportService::class)->run($path, false);
        foreach (['markets', 'schedules', 'stalls', 'foods'] as $entity) {
            $this->assertSame(1, $report['counts'][$entity]['unchanged'], $entity.': '.json_encode($report['counts'][$entity]));
            $this->assertSame(0, $report['counts'][$entity]['created']);
            $this->assertSame(0, $report['counts'][$entity]['updated']);
        }
    }

    public function test_existing_code_updates_only_workbook_owned_fields_and_preserves_unrelated_records(): void
    {
        $market = NightMarket::forceCreate([
            'catalog_code' => 'MKT-001', 'name' => 'Old Market', 'address' => 'Old address', 'city' => 'Old city',
            'state' => 'Selangor', 'description' => 'Old', 'status' => 'inactive', 'image_path' => 'night-markets/keep.jpg',
        ]);
        $unrelated = NightMarket::forceCreate([
            'name' => 'Unrelated', 'address' => 'Elsewhere', 'city' => 'Shah Alam', 'state' => 'Selangor', 'status' => 'active',
        ]);

        $this->artisan('catalog:import-cleaned-workbook', ['--file' => $this->fixture(), '--apply' => true])->assertSuccessful();

        $market->refresh();
        $this->assertSame('Test Market', $market->name);
        $this->assertSame('night-markets/keep.jpg', $market->image_path);
        $this->assertDatabaseHas('night_markets', ['id' => $unrelated->id, 'name' => 'Unrelated', 'catalog_code' => null]);
    }

    public function test_untagged_natural_identity_collision_is_rejected_without_adoption_or_duplicate(): void
    {
        $existing = NightMarket::create([
            'name' => '  TEST   MARKET ', 'address' => '1 TEST ROAD', 'city' => 'Petaling Jaya',
            'state' => 'Selangor', 'status' => 'active',
        ]);

        $this->artisan('catalog:import-cleaned-workbook', ['--file' => $this->fixture(), '--apply' => true])
            ->expectsOutputToContain('collides with an untagged existing record')
            ->assertFailed();

        $this->assertNull($existing->fresh()->catalog_code);
        $this->assertDatabaseCount('night_markets', 1);
        $this->assertDatabaseCount('stalls', 0);
    }

    public function test_failed_write_rolls_back_the_entire_apply(): void
    {
        $market = NightMarket::forceCreate([
            'catalog_code' => 'MKT-001', 'name' => 'Original Market', 'address' => 'Old address',
            'city' => 'Petaling Jaya', 'state' => 'Selangor', 'status' => 'inactive',
        ]);
        Stall::forceCreate([
            'catalog_code' => 'OTHER-STALL', 'night_market_id' => $market->id, 'name' => 'Test Stall',
            'status' => 'active', 'halal_status' => Stall::HALAL_UNKNOWN,
        ]);

        $this->artisan('catalog:import-cleaned-workbook', ['--file' => $this->fixture(), '--apply' => true])
            ->expectsOutputToContain('Every catalog change was rolled back.')
            ->assertFailed();

        $this->assertSame('Original Market', $market->fresh()->name);
        $this->assertDatabaseMissing('stalls', ['catalog_code' => 'STALL-001']);
        $this->assertDatabaseCount('foods', 0);
        $this->assertDatabaseCount('market_operating_days', 0);
    }

    /**
     * @param  array<string, array<string, mixed>>  $overrides
     * @param  list<string>  $omittedSheets
     * @param  array<string, list<string>>  $headerReplacements
     * @param  array<string, list<list<mixed>>>  $extraRows
     */
    private function fixture(array $overrides = [], array $omittedSheets = [], array $headerReplacements = [], array $extraRows = []): string
    {
        $definitions = [
            'NightMarkets' => [$this->nightMarketHeaders(), $this->marketRow()],
            'MarketSchedules' => [$this->scheduleHeaders(), $this->scheduleRow()],
            'Stalls' => [$this->stallHeaders(), $this->stallRow()],
            'Foods' => [$this->foodHeaders(), $this->foodRow()],
        ];
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        foreach ($definitions as $name => [$headers, $row]) {
            if (in_array($name, $omittedSheets, true)) {
                continue;
            }
            if (isset($headerReplacements[$name])) {
                foreach ($headerReplacements[$name] as $index => $replacement) {
                    $headers[$index] = $replacement;
                }
            }
            if (isset($overrides[$name])) {
                $row = array_replace($row, $overrides[$name]);
            }
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($name);
            $sheet->fromArray($headers, null, 'A1');
            $sheet->fromArray(array_values($row), null, 'A2');
            foreach ($extraRows[$name] ?? [] as $extraRow) {
                $sheet->fromArray(array_values($extraRow), null, 'A'.($sheet->getHighestDataRow() + 1));
            }
        }

        foreach ($extraRows as $name => $rows) {
            if (isset($definitions[$name])) {
                continue;
            }
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($name);
            $sheet->fromArray($rows, null, 'A1');
        }

        $directory = storage_path('app/imports/testing');
        File::ensureDirectoryExists($directory);
        $filename = Str::uuid().'.xlsx';
        $absolute = $directory.DIRECTORY_SEPARATOR.$filename;
        (new Xlsx($spreadsheet))->save($absolute);
        $spreadsheet->disconnectWorksheets();
        $this->temporaryFiles[] = $absolute;

        return 'imports/testing/'.$filename;
    }

    /** @return list<string> */
    private function nightMarketHeaders(): array
    {
        return ['market_code', 'market_name', 'local_authority', 'street_address', 'area', 'postcode', 'city', 'state', 'status', 'latitude', 'longitude', 'google_maps_url', 'description', 'source_url', 'source_page_title', 'source_date', 'verified_date', 'notes'];
    }

    /** @return list<string> */
    private function scheduleHeaders(): array
    {
        return ['market_code', 'day_of_week', 'opening_time', 'closing_time', 'source_url', 'verified_date', 'notes'];
    }

    /** @return list<string> */
    private function stallHeaders(): array
    {
        return ['stall_code', 'market_code', 'stall_name', 'stall_category', 'description', 'halal_status', 'halal_evidence_url', 'status', 'source_url', 'verified_date', 'notes'];
    }

    /** @return list<string> */
    private function foodHeaders(): array
    {
        return ['food_code', 'stall_code', 'food_name', 'food_category', 'food_description', 'price_min', 'price_max', 'price_display', 'must_try', 'recommendation_reason', 'status', 'source_url', 'price_checked_date', 'verified_date', 'notes'];
    }

    /** @return array<string, mixed> */
    private function marketRow(): array
    {
        return [
            'market_code' => 'MKT-001', 'market_name' => 'Test Market', 'local_authority' => 'MBPJ',
            'street_address' => '1 Test Road', 'area' => 'Test Area', 'postcode' => '46000',
            'city' => 'Petaling Jaya', 'state' => 'Selangor', 'status' => 'Active', 'latitude' => '3.1000',
            'longitude' => '101.6000', 'google_maps_url' => 'https://maps.example.test/market',
            'description' => 'Verified market', 'source_url' => 'https://example.test/market',
            'source_page_title' => 'Market source', 'source_date' => '2026-08-17',
            'verified_date' => '2026-08-18', 'notes' => 'unmapped',
        ];
    }

    /** @return array<string, mixed> */
    private function scheduleRow(): array
    {
        return [
            'market_code' => 'MKT-001', 'day_of_week' => 'Monday', 'opening_time' => '17:00',
            'closing_time' => '22:00', 'source_url' => 'https://example.test/schedule',
            'verified_date' => '2026-08-18', 'notes' => 'unmapped',
        ];
    }

    /** @return array<string, mixed> */
    private function stallRow(): array
    {
        return [
            'stall_code' => 'STALL-001', 'market_code' => 'MKT-001', 'stall_name' => 'Test Stall',
            'stall_category' => 'Street Food', 'description' => 'Verified stall', 'halal_status' => 'Unknown',
            'halal_evidence_url' => null, 'status' => 'Active', 'source_url' => 'https://example.test/stall',
            'verified_date' => '2026-08-18', 'notes' => 'unmapped',
        ];
    }

    /** @return array<string, mixed> */
    private function foodRow(): array
    {
        return [
            'food_code' => 'FOOD-001', 'stall_code' => 'STALL-001', 'food_name' => 'Test Food',
            'food_category' => 'Snack', 'food_description' => 'Verified food', 'price_min' => '5',
            'price_max' => '8.50', 'price_display' => 'RM5.00–RM8.50', 'must_try' => 'Yes',
            'recommendation_reason' => 'Popular choice', 'status' => 'Active',
            'source_url' => 'https://example.test/food', 'price_checked_date' => '2026-08-17',
            'verified_date' => '2026-08-18', 'notes' => 'unmapped',
        ];
    }
}
