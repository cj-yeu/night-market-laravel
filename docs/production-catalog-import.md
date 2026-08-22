# Production Catalog Import Runbook

This workflow imports the reviewed normalized catalog from the bundled `database/seeders/data/Collab Night Market Cleaned.xlsx` artifact. The artifact is the approved attached `Collab Night Market Cleaned(1).xlsx`, stored under a stable deployment filename.

The workflow is deliberately additive and idempotent. It upserts records by internal `catalog_code`, never truncates or deletes data, never adopts an untagged natural-identity collision, and wraps every applied run in one database transaction.

## Immutable catalog contract

The production command verifies the workbook SHA-256 before reading it:

```text
5486448056b4502ff8480eeff0c7297f2e21f639ce662dceb4fb684d6200817d
```

Only these normalized sheets are loaded:

| Sheet | Required records | Publication rule |
| --- | ---: | --- |
| `NightMarkets` | 17 | Every row must be Active and in Selangor |
| `MarketSchedules` | 19 | Every row must reference an imported Market |
| `Stalls` | 21 | Every row must be Active and retain Unknown Halal |
| `Foods` | 21 | Every row must be Active and reference an imported Stall |

The importer does not load the eight `RAW_*` sheets, `NeedsVerification`, or `DataIssues`. A changed file, changed normalized count, inactive normalized record, non-Selangor Market, or upgraded Halal classification fails before any write.

## Deployment procedure

1. Put the application into the normal deployment maintenance window and take a restorable database backup.
2. Confirm the target environment and database name without displaying credentials. Do not continue if either value is unexpected.
3. Deploy the release and run the normal migrations:

   ```bash
   php artisan migrate --force
   ```

4. Run the mandatory non-writing preview:

   ```bash
   php artisan catalog:import-production
   ```

   Confirm the validated counts are exactly 17 Night Markets, 19 Schedules, 21 Stalls, and 21 Foods. On a first clean import, these normally appear as created. On a repeat deployment, they should appear as unchanged. Any collision or validation error must be investigated; do not bypass it.

5. Apply only after the dry-run is accepted:

   ```bash
   php artisan catalog:import-production --apply --force
   ```

   `--apply` is the database-write boundary. `--force` suppresses Laravel's interactive production prompt for controlled non-interactive deployment jobs; it does not bypass workbook validation, the integrity check, collision detection, or the transaction.

6. Run the preview again. The 17/19/21/21 records should now be classified as unchanged:

   ```bash
   php artisan catalog:import-production
   ```

7. Smoke-test public Market, Stall, and Food discovery. Confirm Unknown Halal records display as unverified and no `NeedsVerification` candidate appears publicly.

## Seeder integration

The preferred operator workflow is the dry-run-first command above. Deployment systems that require a Laravel seeder may run the dedicated idempotent seeder only after a successful preview:

```bash
php artisan db:seed --class=ProductionCatalogSeeder --force
```

`ProductionCatalogSeeder` is intentionally not called by `DatabaseSeeder`, preventing the catalog from being loaded during ordinary development or test seeding.

## Failure and recovery

- Preflight or integrity failures write nothing.
- A write failure rolls back the entire catalog transaction.
- Existing images, Reviews, Visit Plans, user content, untagged catalog records, and schedules not owned by the workbook are preserved.
- There is no truncate, sync-delete, wipe, or rollback-delete option.
- If an applied metadata change must be reversed, restore the verified pre-deployment database backup or deploy a separately reviewed corrective workbook. Do not manually delete catalog rows without impact analysis.

## Audit notes

- Codes are internal import identities and remain hidden from public serialization and browser mass assignment.
- Unknown or incomplete prices remain unknown; `price_display` is not parsed into numeric prices.
- Unknown Halal remains the internal `unknown` classification and is never promoted to Halal-certified.
- Source and verification metadata is mapped only where the current schema supports it. The existing generic import command reports intentionally unmapped workbook columns.
