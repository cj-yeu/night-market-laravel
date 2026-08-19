<?php

namespace App\Console\Commands;

use App\Services\CatalogWorkbookImportService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class ImportCleanedCatalogWorkbook extends Command
{
    protected $signature = 'catalog:import-cleaned-workbook
        {--file=imports/Collab Night Market Cleaned.xlsx : Workbook path relative to storage/app}
        {--dry-run : Validate and preview without database writes}
        {--apply : Apply the validated import in one transaction}';

    protected $description = 'Validate or import the normalized verified night-market catalog workbook';

    public function handle(CatalogWorkbookImportService $importer): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Choose either --dry-run or --apply, not both.');

            return self::INVALID;
        }

        $apply = (bool) $this->option('apply');

        try {
            $report = $importer->run((string) $this->option('file'), $apply);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('The workbook could not be processed safely. No import was applied.');

            return self::FAILURE;
        }

        $this->info('Mode: '.($apply ? 'apply' : 'dry-run'));
        $this->table(
            ['Entity', 'Validated', 'Created', 'Updated', 'Unchanged', 'Skipped', 'Rejected'],
            collect([
                'Night Markets' => 'markets',
                'Schedules' => 'schedules',
                'Stalls' => 'stalls',
                'Foods' => 'foods',
            ])->map(fn ($key, $label) => [$label, ...array_values($report['counts'][$key])])->values()->all(),
        );

        $this->newLine();
        $this->line('Intentionally unmapped workbook columns:');
        foreach ($report['unmapped'] as $sheet => $columns) {
            $this->line("- {$sheet}: ".implode(', ', $columns));
        }

        if ($report['errors'] !== []) {
            $this->newLine();
            foreach ($report['errors'] as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $this->newLine();
        $this->info($apply ? 'Import applied successfully.' : 'Dry-run complete. No database records were changed.');

        return self::SUCCESS;
    }
}
