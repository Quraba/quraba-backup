# Quraba Backup

`quraba/quraba-backup` — backup and disaster-recovery foundation for **single** Laravel applications on
Linux VPS and cPanel shared hosting, storing offsite in the customer's own **Backblaze B2** bucket through
the S3-compatible API, with **Restic** as the media snapshot engine.

> **Status: foundation release (master plan phases 0–2).** This version provides the package skeleton,
> catalog, identity, workspaces, process-safe locking, the Restic runtime (installer, runner, repository
> init/health) and `backup:doctor`. **It does not yet create backups, restore anything, schedule jobs or
> apply retention.** Those arrive in later phases; see [docs](docs/).

## Requirements

- PHP 8.5+, Laravel 13
- Linux (x86_64; arm64 is structurally supported) — VPS or shared hosting
- `proc_open` enabled for PHP CLI, writable private storage, outbound HTTPS, cron
- MySQL or MariaDB, plus the `mariadb-dump`/`mysqldump` and `mariadb`/`mysql` client tools
- No root, sudo, Docker, systemd, Supervisor, Redis or queue worker

## Quick start

```bash
composer require quraba/quraba-backup
php artisan vendor:publish --tag=quraba-backup-config   # optional
php artisan migrate

php artisan backup:identity --generate      # add the printed QURABA_BACKUP_APP_ID to .env, once
php artisan backup:install-restic           # pinned, SHA-256 verified, no root
# configure B2 + Restic password file (see docs/configuration.md)
php artisan backup:restic:init              # explicit, only when the repository is confirmed absent
php artisan backup:doctor
```

## Commands (this release)

| Command | Purpose |
|---|---|
| `backup:identity [--generate] [--json]` | Show the stable application identity (no secrets) |
| `backup:doctor [--json]` | Environment/setup readiness (PASS/WARN/FAIL/SKIP, non-zero on FAIL) |
| `backup:install-restic [--force]` | Install the pinned Restic release into package-private storage |
| `backup:restic:init [--force]` | Explicitly initialize the Restic repository after confirming absence |
| `backup:restic:health [--json]` | Cheap Restic health (not `restic check`) |
| `backup:workspace:list [--older-than=H] [--json]` | List operation workspaces, flag abandoned ones |
| `backup:workspace:cleanup [--older-than=H] [--execute] [--json]` | Plan (default) or remove abandoned workspaces |

## Documentation

- [Installation](docs/installation.md)
- [Configuration (B2, Restic, identity)](docs/configuration.md)
- [Shared hosting](docs/shared-hosting.md)
- [Doctor & health](docs/doctor.md)
- [Security model](docs/security.md)
- [Troubleshooting](docs/troubleshooting.md)
- Architecture: [specification](docs/Quraba%20Backup%20—%20Architecture%20Specification%20v1.md),
  [master plan](docs/Quraba%20Backup%20—%20Implementation%20Master%20Plan.md)

## Development

```bash
composer check        # composer validate, pint --test, phpstan (max), phpunit
```

Real-Restic integration tests run when `QURABA_BACKUP_TEST_RESTIC_BINARY` points to the pinned binary;
optional B2 tests run only when the `QURABA_BACKUP_TEST_B2_*` variables are set.
