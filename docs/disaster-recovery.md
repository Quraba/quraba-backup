# Disaster recovery: restoring onto a clean host

The server is gone. You have a new, empty host and — kept **outside** the old server — the recovery
materials. This runbook brings the application back from Backblaze B2.

The commands below show Linux paths and shell syntax. On Windows amd64, use equivalent PowerShell file
operations and Windows paths; the package commands, exact run selection and restore gates are the same.
Keep the recovered Restic password at the portable default
`storage/app/private/quraba-secrets/restic-password` or set an explicit safe absolute path, and restrict
its NTFS ACL to the application account. PHP cannot verify ACL privacy or sync the journal directory on
Windows; use a local durable filesystem and review the [restore journal notes](restore.md#the-restore-journal).

## What you need

`php artisan quraba:backup:recovery-checklist` lists these and says which are configured (it never prints
a value). On the old host, run it while everything still works and store the values somewhere else.

| Material | Variable |
|---|---|
| Application ID | `QURABA_BACKUP_APP_ID` |
| B2 S3 endpoint and bucket | `QURABA_BACKUP_B2_ENDPOINT`, `QURABA_BACKUP_B2_BUCKET` |
| B2 key ID and application key | `QURABA_BACKUP_B2_KEY_ID`, `QURABA_BACKUP_B2_APPLICATION_KEY` |
| Archive password | `QURABA_BACKUP_ARCHIVE_PASSWORD` |
| Restic repository password (a private file) | `QURABA_BACKUP_RESTIC_PASSWORD_FILE` |
| Remote prefix, if not the default | `QURABA_BACKUP_PREFIX` |
| Environment name, if not the default | `QURABA_BACKUP_ENVIRONMENT` (must equal the old one, e.g. `production`) |

Without the archive password nobody can open the archives; without the Restic password no media can be
restored.

## Runbook

### 1. Check out the application

```bash
git clone <your repository> /home/app/site && cd /home/app/site
```

Use the release that matches the backup you will restore.

### 2. Install dependencies

```bash
composer install --no-dev --optimize-autoloader
```

### 3. Install and verify Restic

```bash
php artisan quraba:backup:install-restic     # pinned version, SHA-256 verified, no root
```

### 4. Configure the minimal recovery secrets

Create a minimal `.env` with `APP_KEY` left empty for now and the variables from the table above, and put
the Restic password into a private file:

```bash
install -m 600 /dev/null /home/app/restic-password && nano /home/app/restic-password
php artisan quraba:backup:recovery-checklist --remote
```

Do **not** run `quraba:backup:restic:init`: the repository already exists.

### 5. Discover what exists remotely

```bash
php artisan quraba:backup:discover --remote
```

This needs no database and no local catalog. It reads the immutable manifests and the retention expiry
records and shows, per run, whether the archive and the snapshot are physically present. Choose the exact
run UUID of a complete Recovery Point.

### 6. Recover the archived `.env`

```bash
php artisan quraba:backup:bootstrap-env --run=UUID
```

The archive is downloaded, its SHA-256 compared with the manifest, decrypted and verified, and **only**
`.env` is written — to `.env.recovered`, as a private file. Its content is never displayed. An existing
file is never overwritten unless you pass `--overwrite --confirm=OVERWRITE_ENV`. Review it, adjust what
differs on the new host (database host, name and credentials, paths, the Restic password file) and move it
into place:

```bash
mv .env.recovered .env
```

### 7. Reload the configuration

```bash
php artisan config:clear
```

`APP_KEY` now comes from the recovered `.env`; a restore refuses a backup whose APP_KEY fingerprint differs.

### 8. Provision the empty target database

Create the database and its account (in cPanel: *MySQL Databases*). Leave it **empty** — do not run
migrations. The account needs full privileges on that database only. If the account name differs from the
old host's, set `QURABA_BACKUP_RESTORE_REWRITE_DEFINERS=true` (see [restore](restore.md#limits)).

Set a quiescence provider that can prove writers are stopped:

```
QURABA_BACKUP_QUIESCENCE_PROVIDER=laravel_maintenance
QURABA_BACKUP_NO_BACKGROUND_WRITERS=true      # no cron entry and no queue worker is running yet
```

### 9. Dry-run the exact Recovery Point

```bash
php artisan quraba:backup:restore --run=UUID --profile=full
```

It reconstructs and validates everything privately and changes nothing. It works with an empty database:
there is simply no audit table to write to.

### 10. Live restore

```bash
php artisan quraba:backup:restore --run=UUID --profile=full --clean-host \
    --force --confirm=RESTORE_APPLICATION
```

`--clean-host` declares that there is nothing to preserve. It is never inferred: the package **proves**
that the target database holds no object at all and that every media destination is absent or empty
(framework placeholders such as `.gitignore` do not count). Only then is the safety backup skipped — the
journal records `safety_backup = not_required_target_proven_empty`. On a host that holds anything, omit
`--clean-host`; a verified safety backup is then taken first.

No migrations are run before the import: the journal alone tracks the restore, the imported backup brings
its own catalog, and the restore's audit row is handed back into it afterwards.

### 11. Verify

The application is in maintenance mode. Check the database, the media, the configuration and the logs.
`php artisan quraba:backup:restore-reconcile` lists the restore journal; `php artisan quraba:backup:health`
must report no unresolved restore.

### 12. Rebuild and reconcile the catalog

```bash
php artisan quraba:backup:reconcile
php artisan quraba:backup:catalog:rebuild            # plan
php artisan quraba:backup:catalog:rebuild --apply    # adopt
```

The restored catalog is the one inside the backup. The rebuild adopts every backup that exists remotely
and is missing locally — only from physical evidence (archive present with its exact size and streamed SHA-256, snapshot listed
with this run's identity), honouring retention expiry records.

### 13. Bring the application online

```bash
php artisan up
```

Then restore the cron entry (`* * * * * cd /home/app/site && php artisan schedule:run >> /dev/null 2>&1`),
run `php artisan quraba:backup:doctor`, and take a new Recovery Point with `php artisan quraba:backup:run`.

## If something goes wrong

- The restore **failed** (exit 1): nothing was changed. Fix the cause and repeat.
- The restore is **indeterminate** (exit 3): it stopped after it began changing the host. Run
  `php artisan quraba:backup:restore-reconcile --restore=UUID` and follow its guidance; see
  [restore](restore.md#reconciliation). On a clean host the usual way on is to abandon the restore, empty
  the target database again and repeat step 10.
