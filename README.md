# Quraba Backup

`quraba/quraba-backup` — backup and disaster-recovery package for **single** Laravel applications on
Linux or Windows. Linux VPS and cPanel shared hosting are supported. Offsite storage is the customer's own **Backblaze B2** bucket (S3 API);
**Spatie Laravel Backup** builds the encrypted database + `.env` archive and **Restic** takes media snapshots.

> **Status: backups, health, retention, restore and disaster recovery.** The package creates verified
> encrypted application archives, verified Restic media snapshots and coordinated Recovery Points with
> immutable remote manifests; reports recovery health; applies exact retention; and restores one exact run —
> as a dry run by default, live only with `--force --confirm=RESTORE_APPLICATION`, journaled, behind a
> verified safety backup and proven quiescence — including onto a clean host. An optional Filament 5
> panel provides health, run history and read-only recovery tools.

## Requirements

- PHP 8.5+, Laravel 13
- Linux amd64/arm64 or Windows amd64; the managed Restic 0.19.1 release is pinned for each
- `proc_open` enabled for PHP CLI, writable private storage, outbound HTTPS, one cron entry
- MySQL or MariaDB with `mariadb-dump`/`mysqldump` (and `mariadb`/`mysql`) client tools
- PHP `zip` with AES-256 support (checked by the doctor)
- No root, sudo, Docker, systemd, Supervisor, Redis or queue worker

## Quick start

```bash
composer require quraba/quraba-backup
php artisan migrate

php artisan quraba:backup:identity --generate    # add the printed QURABA_BACKUP_APP_ID to .env, once
php artisan quraba:backup:install-restic         # pinned Restic 0.19.1, SHA-256 verified, no root
# configure B2, archive password and Restic password file (docs/configuration.md)
php artisan quraba:backup:restic:init            # explicit; binds this application to the repository
php artisan quraba:backup:doctor                 # fix every FAIL
php artisan quraba:backup:run                    # first Recovery Point
```

On Linux, add one cron entry: `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1`.
On Windows, run `php artisan schedule:run` every minute using Task Scheduler; scheduled jobs run in
the foreground. See [installation](docs/installation.md) and [platform notes](docs/doctor.md).

## Commands

All package commands use the `quraba:backup:*` namespace (bare `backup:*` belongs to Spatie).

| Command | Purpose |
|---|---|
| `quraba:backup:run [--profile=database\|media\|recovery] [--json]` | Create a verified backup (default `recovery`); exit 0 completed, 2 partial, 3 indeterminate, 1 failed |
| `quraba:backup:pending [--json]` | Process one authorized pending panel request under the operation lock; scheduled when enabled |
| `quraba:backup:list [--limit=] [--profile=] [--json]` | Browse the local catalog |
| `quraba:backup:reconcile [--dry-run] [--json]` | Resolve interrupted runs from physical evidence |
| `quraba:backup:health [--json]` | Recovery health: healthy, degraded, failed or unknown; optional transition alerts |
| `quraba:backup:retention [--execute] [--json]` | Plan (default) or apply exact retention |
| `quraba:backup:restic:check [--read-data] [--json]` | Audited Restic repository check |
| `quraba:backup:restic:prune [--execute] [--json]` | Restic prune (dry run by default, never scheduled) |
| `quraba:backup:discover --remote [--json]` | List backups from remote manifests and expiry records (no local catalog needed) |
| `quraba:backup:restore --run=UUID [--profile=database\|media\|full] [--json]` | **Dry run** of one exact run: reconstruct and validate privately |
| `quraba:backup:restore --run=UUID … --force --confirm=RESTORE_APPLICATION [--clean-host]` | **Live restore**; exit 0 completed, 1 failed (nothing changed), 3 indeterminate |
| `quraba:backup:restore-reconcile [--restore=UUID] [--abandon --confirm=ABANDON_RESTORE] [--cleanup-parked]` | List restore journals; decide an interrupted live restore from evidence |
| `quraba:backup:bootstrap-env --run=UUID [--target=PATH]` | Clean host: recover the archived `.env` only |
| `quraba:backup:catalog:rebuild [--apply] [--json]` | Rebuild the local catalog from remote truth (plan by default) |
| `quraba:backup:recovery-checklist [--remote] [--json]` | Which recovery materials are configured (never prints a secret) |
| `quraba:backup:doctor [--json]` | Environment and backup readiness (PASS/WARN/FAIL/SKIP) |
| `quraba:backup:identity [--generate] [--json]` | Show the stable application identity |
| `quraba:backup:install-restic [--force]` | Install the pinned Restic release |
| `quraba:backup:restic:init [--force]` | Explicitly initialize and bind the Restic repository |
| `quraba:backup:restic:health [--json]` | Cheap Restic health (not `restic check`) |
| `quraba:backup:workspace:list [--older-than=H] [--json]` | List operation workspaces |
| `quraba:backup:workspace:cleanup [--older-than=H] [--execute] [--restore=UUID] [--json]` | Plan (default) or remove abandoned workspaces; retained restore evidence requires exact resolved restore UUID |

## Documentation

- [Installation](docs/installation.md)
- [Configuration (B2, passwords, media roots, consistency, schedules)](docs/configuration.md)
- [Backups: profiles, Recovery Points, verification, reconciliation, scheduling](docs/backups.md)
- [Health, retention, maintenance, discovery, restore dry run, catalog rebuild](docs/recovery-operations.md)
- [Restore: the live gate, the journal, failure and reconciliation](docs/restore.md)
- [Disaster recovery onto a clean host (runbook)](docs/disaster-recovery.md)
- [Shared hosting / cPanel](docs/shared-hosting.md)
- [Doctor & health](docs/doctor.md)
- [Security model](docs/security.md)
- [Troubleshooting](docs/troubleshooting.md)
- [Optional notifications](docs/notifications.md)
- [Optional Filament 5 panel](docs/filament.md)
- [Upgrading](docs/upgrading.md)
- Architecture: [specification](docs/Quraba%20Backup%20—%20Architecture%20Specification%20v1.md),
  [master plan](docs/Quraba%20Backup%20—%20Implementation%20Master%20Plan.md)

## Development

```bash
composer check        # composer validate, pint --test, phpstan (max), phpunit
```

Opt-in integration suites: `QURABA_BACKUP_TEST_RESTIC_BINARY` (real pinned Restic, local repositories),
`QURABA_BACKUP_TEST_MYSQL_HOST` (+ `_PORT`, `_USERNAME`, `_PASSWORD`; creates and drops its own
`quraba_backup_it_*` databases and `quraba_it_*` accounts — including the end-to-end live restore and
clean-host tests) and `QURABA_BACKUP_TEST_B2=1` plus the `QURABA_BACKUP_TEST_B2_*` variables for a
disposable test bucket. In CI, real B2 runs only through the manual `run_b2` input, never on normal push
or pull-request runs.
