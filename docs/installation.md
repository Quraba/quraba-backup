# Installation

> This release installs the **foundation** only: identity, catalog, workspaces, locking, the Restic runtime
> and diagnostics. It does not create backups yet, and no scheduler entry is needed yet.

## 1. Require the package

```bash
composer require quraba/quraba-backup
php artisan migrate
```

The service provider is auto-discovered. Migrations are loaded automatically; to copy them into the
application instead, run `php artisan vendor:publish --tag=quraba-backup-migrations`.

Optionally publish the configuration:

```bash
php artisan vendor:publish --tag=quraba-backup-config
```

This publishes `config/quraba-backup.php` and `config/restic.php`. Everything is driven by
`QURABA_BACKUP_*` environment variables, so publishing is rarely necessary.

## 2. Create the application identity (once)

```bash
php artisan backup:identity --generate
```

The command prints a line such as `QURABA_BACKUP_APP_ID=6f614a0b-…`. Add it to `.env` **once** and store a copy
with the recovery secrets. It identifies this application's backups inside a shared bucket and must never
change. The command refuses to generate a new ID when one is already configured, and never writes `.env` itself.

Also set the environment identity explicitly (it separates production from staging backups):

```dotenv
QURABA_BACKUP_ENVIRONMENT=production
```

## 3. Install Restic (no root)

```bash
php artisan backup:install-restic
```

Downloads the package-pinned Restic release (currently **0.19.1**), verifies its SHA-256 against the value
pinned in the package *and* the release's official `SHA256SUMS`, decompresses it, runs `restic version`,
and atomically installs it to `storage/app/private/quraba-backup/bin/restic`. Running it again is a no-op
when the correct binary is present. `--force` replaces an existing managed binary explicitly (for example
after a package upgrade pins a newer Restic). Restic is never upgraded implicitly.

Hosts without outbound access to GitHub can use a mirror (`QURABA_BACKUP_RESTIC_DOWNLOAD_BASE_URL`, https
only) — the pinned checksums still decide what is accepted — or point `QURABA_BACKUP_RESTIC_BINARY` to a
binary installed by other means (it must report exactly the pinned version).

## 4. Configure B2 and the Restic password

See [configuration.md](configuration.md).

## 5. Initialize the repository explicitly

```bash
php artisan backup:restic:init
```

The command inspects the repository first and only initializes when Restic itself reports that no repository
exists at the configured location. It shows the exact location (no secrets) and asks for confirmation
(`--force` skips the prompt for automation; the absence check still applies). It then reads the new
repository back to prove it works.

## 6. Check everything

```bash
php artisan backup:doctor
php artisan backup:restic:health
```

Fix every `FAIL`. See [doctor.md](doctor.md).
