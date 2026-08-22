# Production Operations and Recovery

## Admin bootstrap recovery

Use this only when no suitable administrator can be recovered through the existing Admin/User-management workflow. The command never converts or overwrites an existing account.

```bash
php artisan admin:bootstrap --force
```

In production, `--force` is mandatory and does not bypass the final interactive confirmation. Enter the name, email, and password only at the terminal prompt. The command creates exactly one active, verified administrator and never prints the password or its hash. If the email already exists, it refuses without changing that account.

## Backups and catalog changes

Before migrations, bulk catalog updates, or any manual data operation, create and validate a Railway MySQL backup/export using Railway's current database backup/export facility. Store it according to the team's approved retention policy. Never run destructive SQL, `migrate:fresh`, `db:wipe`, or manual truncate/drop statements in production.

Safe catalog workflow:

1. Review and normalize the workbook.
2. Run the isolated test suite and obtain review through a PR.
3. Deploy the reviewed release.
4. Run `php artisan catalog:import-production` as a dry run.
5. Check the reported counts and errors.
6. Run the approved apply command only after the dry run is accepted.

See [production-catalog-import.md](production-catalog-import.md) for the immutable workbook contract and exact apply command.

## Manual release checklist

- [ ] Confirm the target Railway service and take a restorable MySQL backup/export.
- [ ] Deploy and run the reviewed migrations.
- [ ] Run `php artisan storage:link` and verify public image persistence after redeploy.
- [ ] Smoke-test public Home, Market, Stall, Food, and social-highlight pages.
- [ ] Verify Google login and normal password login where configured.
- [ ] Verify Logout submits and redirects over HTTPS.
- [ ] Send a registration-verification and password-reset test email through production SMTP.
- [ ] Confirm Admin access and critical management actions.
- [ ] For catalog releases, complete the documented dry-run before apply.

## Audit scope

This release intentionally does not add analytics tracking, cookie profiling, or a broad audit-log database schema. If a future requirement needs them, define retention, access controls, event scope, and privacy review before implementation.
