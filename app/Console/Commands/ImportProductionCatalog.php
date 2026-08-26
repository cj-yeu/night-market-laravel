<?php

namespace App\Console\Commands;

use App\Services\CatalogWorkbookImportService;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use InvalidArgumentException;
use Throwable;

class ImportProductionCatalog extends Command
{
    use ConfirmableTrait;

    protected $signature = 'catalog:import-production
        {--dry-run : Validate and preview the bundled production catalog without database writes}
        {--apply : Apply the validated production catalog in one transaction}
        {--force : Apply without an interactive production confirmation}';

    protected $description = 'Safely validate or import the immutable reviewed production catalog';

    public function handle(CatalogWorkbookImportService $importer): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Choose either --dry-run or --apply, not both.');

            return self::INVALID;
        }

        if (! $this->option('apply') && $this->option('force')) {
            $this->error('--force is valid only together with --apply.');

            return self::INVALID;
        }

        $apply = (bool) $this->option('apply');

        if ($apply && ! $this->confirmToProceed('The reviewed production catalog will be upserted without deleting catalog or user data.')) {
            return self::FAILURE;
        }

        try {
            $report = $importer->runProduction($apply);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('The production catalog could not be processed safely. No import was applied.');

            return self::FAILURE;
        }

        $this->info('Mode: '.($apply ? 'apply' : 'dry-run'));
        $this->line('Source: bundled reviewed catalog (SHA-256 verified)');
        $this->table(
            ['Entity', 'Validated', 'Created', 'Updated', 'Unchanged', 'Skipped', 'Rejected'],
            collect([
                'Night Markets' => 'markets',
                'Schedules' => 'schedules',
                'Stalls' => 'stalls',
                'Foods' => 'foods',
            ])->map(fn ($key, $label) => [$label, ...array_values($report['counts'][$key])])->values()->all(),
        );

        if ($report['errors'] !== []) {
            $this->newLine();
            foreach ($report['errors'] as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $this->newLine();
        $this->info($apply
            ? 'Production catalog applied successfully. No records were deleted.'
            : 'Production dry-run complete. No database records were changed.');

        return self::SUCCESS;
    }
}
