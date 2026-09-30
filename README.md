# Quraba Backup

`quraba/quraba-backup` — backup and disaster-recovery package for **single** Laravel applications on Linux
VPS and cPanel shared hosting. Offsite storage is the customer's own **Backblaze B2** bucket (S3 API);
**Spatie Laravel Backup** builds the encrypted database + `.env` archive and **Restic** takes media snapshots.

> **Status: backups (master plan phases 0–5).** The package creates verified encrypted application archives,
> verified Restic media snapshots and coordinated Recovery Points with immutable remote manifests,
> reconciles interrupted runs and schedules itself through Laravel. **Restore, retention and Filament are not
> implemented yet**; nothing in this release deletes or overwrites backup data.

## Requirements

- PHP 8.5+, Laravel 13
- Linux (x86_64; arm64 is structurally supported) — VPS or shared hosting
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

Then add one cron entry: `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1`.

## Commands

All package commands use the `quraba:backup:*` namespace (bare `backup:*` belongs to Spatie).

| Command | Purpose |
|---|---|
| `quraba:backup:run [--profile=database\|media\|recovery] [--json]` | Create a verified backup (default `recovery`); exit 0 completed, 2 partial, 3 indeterminate, 1 failed |
| `quraba:backup:list [--limit=] [--profile=] [--json]` | Browse the local catalog |
| `quraba:backup:reconcile [--dry-run] [--json]` | Resolve interrupted runs from physical evidence |
| `quraba:backup:doctor [--json]` | Environment and backup readiness (PASS/WARN/FAIL/SKIP) |
| `quraba:backup:identity [--generate] [--json]` | Show the stable application identity |
| `quraba:backup:install-restic [--force]` | Install the pinned Restic release |
| `quraba:backup:restic:init [--force]` | Explicitly initialize and bind the Restic repository |
| `quraba:backup:restic:health [--json]` | Cheap Restic health (not `restic check`) |
| `quraba:backup:workspace:list [--older-than=H] [--json]` | List operation workspaces |
| `quraba:backup:workspace:cleanup [--older-than=H] [--execute] [--json]` | Plan (default) or remove abandoned workspaces |

## Documentation

- [Installation](docs/installation.md)
- [Configuration (B2, passwords, media roots, consistency, schedules)](docs/configuration.md)
- [Backups: profiles, Recovery Points, verification, reconciliation, scheduling](docs/backups.md)
- [Shared hosting / cPanel](docs/shared-hosting.md)
- [Doctor & health](docs/doctor.md)
- [Security model](docs/security.md)
- [Troubleshooting](docs/troubleshooting.md)
- Architecture: [specification](docs/Quraba%20Backup%20—%20Architecture%20Specification%20v1.md),
  [master plan](docs/Quraba%20Backup%20—%20Implementation%20Master%20Plan.md)

## Development

```bash
composer check        # composer validate, pint --test, phpstan (max), phpunit
```

Opt-in integration suites: `QURABA_BACKUP_TEST_RESTIC_BINARY` (real pinned Restic, local repositories),
`QURABA_BACKUP_TEST_MYSQL_HOST` (+ `_PORT`, `_USERNAME`, `_PASSWORD`; creates and drops its own
`quraba_backup_it_*` database) and the `QURABA_BACKUP_TEST_B2_*` variables (real B2, never in normal CI).
