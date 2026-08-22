<?php

namespace Database\Seeders;

use App\Services\CatalogWorkbookImportService;
use Illuminate\Database\Seeder;
use RuntimeException;

class ProductionCatalogSeeder extends Seeder
{
    public function run(CatalogWorkbookImportService $importer): void
    {
        $report = $importer->runProduction(true);

        if ($report['errors'] !== [] || ! $report['applied']) {
            throw new RuntimeException('The production catalog seeder failed safely; no partial catalog changes were saved.');
        }

        $this->command?->info('Verified production catalog imported idempotently.');
    }
}
