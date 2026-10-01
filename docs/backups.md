# Backups

Quraba Backup creates verified backups of one Laravel application in the customer's B2 bucket.
Health, explicit retention, repository maintenance, remote discovery and restore dry runs are described in
[recovery operations](recovery-operations.md); the live restore in [restore](restore.md); recovery onto a
new host in [disaster recovery](disaster-recovery.md).

## Profiles

| Profile | Creates | Use |
|---|---|---|
| `database` | Encrypted **application archive** (database dump + `.env` + `quraba-backup.json`) uploaded to B2, plus a manifest | Frequent database protection |
| `media` | A **Restic snapshot** of the configured media roots (kind `media`), plus a manifest | Frequent file protection |
| `recovery` (default) | **Both** under ONE run UUID (media kind `recovery_media`) and one manifest | A **Recovery Point** for full recovery |

A Recovery Point is only ever the two components of one run. Two independent database/media backups are
never combined and presented as one.

## Run a backup

```bash
php artisan quraba:backup:run                    # recovery (default)
php artisan quraba:backup:run --profile=database
php artisan quraba:backup:run --profile=media --json
```

Exit codes:

| Code | Meaning |
|---|---|
| `0` | completed — every required component verified and the manifest written |
| `2` | partial — at least one component verified, at least one failed; **not** a complete Recovery Point |
| `3` | indeterminate — the physical outcome could not be proven; run `quraba:backup:reconcile` |
| `1` | failed or refused (e.g. another operation is running, preflight failed), or quiescence could not be released |

Only one write-affecting Quraba Backup operation runs at a time (OS `flock`); a second one is refused and
creates no run.

## What "verified" means

**Application archive**

1. `mariadb-dump`/`mysqldump` (MySQL and MariaDB) with `--single-transaction --quick` (consistent InnoDB
   snapshot, streamed to disk). Credentials are passed only through a temporary 0600 option file inside the
   operation workspace (`--defaults-extra-file`), never in the process arguments, and it is deleted at once.
2. The archive (Spatie Laravel Backup's zip task) is **AES-256** encrypted with `QURABA_BACKUP_ARCHIVE_PASSWORD`.
   A missing or blank password refuses the backup before anything is created; there is no unencrypted fallback.
   The plaintext dump never outlives the archive build; `.env` is read in place, never copied.
3. The verifier re-opens the archive: exact entries, every entry AES-256, the password decrypts every
   entry end-to-end (CRC checked), non-empty dump, `.env` present, metadata matches run/app/environment,
   SHA-256 of the file.
4. Upload to `{prefix}/{app_id}/archives/YYYY/MM/DD/{run_uuid}/application.zip`, then proof that the object
   exists with exactly the expected size. Locator, SHA-256, size and verification time are recorded.
5. Nothing is overwritten: an object already at that path is adopted only if its size and streamed SHA-256
   equal this run's verified archive; anything else is a collision (`archive.collision`).

`quraba-backup.json` (inside the archive) holds only safe metadata: schema version, run/app/environment,
UTC creation time, package/Laravel/PHP versions, database driver/flavor/server version, migration and
schema fingerprints, and a SHA-256 fingerprint of APP_KEY — never APP_KEY, passwords or `.env` values.
It also records event policy, proven object-class coverage and an explicit exact-completeness flag. Missing
EVENT or object-visibility grants leave the flag false; a completed Recovery Point does not silently imply
exact database object coverage.

**Media snapshot**

1. The media roots are validated (see configuration).
2. The opened Restic repository must be the application's **expected repository** (below).
3. The run's identity is searched: `quraba-backup, app:{uuid}, env:{env}, kind:{kind}, run:{uuid}` (a single
   comma-joined `--tag`, which Restic treats as AND). 0 → create; 1 → adopt; more than one → refused
   (`restic.snapshot_ambiguous`); a snapshot claiming the run with another app/env/kind is refused.
4. The full snapshot ID from Restic's JSON summary is re-read by exact ID and its full identity is checked, its
   paths must equal the configured roots, and the repository ID is checked again. Only then is the artifact
   verified. Short IDs and `latest` are never used.

## Remote manifest

After the components are verified, an immutable JSON manifest is written to
`{prefix}/{app_id}/manifests/YYYY/MM/DD/{run_uuid}.json`. It contains no secrets: run/app/environment,
profile, trigger, status (`completed` or `partial`), consistency, creation time, package version, the Restic
repository ID, the archive locator/SHA-256/size and the full snapshot ID/kind. `recovery_point: true` only when
both components of a recovery run are verified. A manifest is never overwritten: identical content is adopted,
different content is a collision (`manifest.collision`). The catalog becomes `completed` only **after** the
manifest exists — the catalog never leads physical truth.

## Consistency: `best_effort` vs `quiesced`

A Recovery Point captures the database and the media one after the other. Unless writers are stopped, the
two may describe slightly different moments: that is `best_effort`, the honest default.

`quiesced` is recorded **only** when the configured provider proves no writer can change data:

```dotenv
QURABA_BACKUP_QUIESCENCE_PROVIDER=laravel_maintenance   # puts the site in maintenance mode during capture
QURABA_BACKUP_NO_BACKGROUND_WRITERS=true                 # you declare: no queue workers, scheduled writers or CLI writers
```

Maintenance mode alone only blocks HTTP, so without the declaration the Recovery Point is still
`best_effort`. `QURABA_BACKUP_REQUIRE_QUIESCED=true` refuses Recovery Points that cannot be quiesced unless
`QURABA_BACKUP_ALLOW_CONSISTENCY_DOWNGRADE=true` (then they are recorded as `best_effort` with an
explanation). Maintenance mode is always left in a `finally` block; if the application was already down, it
stays down. If releasing fails, the backup reports it loudly (exit code 1, critical log).

## Repository identity

The first Restic repository proven for this application/environment (by `quraba:backup:restic:init`, or by
the first media backup) becomes the **expected repository**; its ID is recorded in the catalog
(`quraba_backup_repository_identities`, not secret) and in every manifest. If the catalog has no record, the
expected ID is learned from the most recent manifests in the bucket. Afterwards:

- every backup verifies the opened repository ID; a different repository at the configured location is
  refused (`restic.repository_identity_mismatch`) — an empty replacement is never adopted;
- `quraba:backup:restic:init` refuses to create a new repository while the application is bound to one.

## Reconciliation

```bash
php artisan quraba:backup:reconcile            # resolve interrupted runs
php artisan quraba:backup:reconcile --dry-run  # only inspect
```

Holding the global lock proves no backup is running, so every run still preflighting/running/verifying or
indeterminate was interrupted. Physical evidence decides:

- archive: the object at the run's deterministic path is downloaded, decrypted and verified, then adopted;
  absent → the component failed;
- media: the run's exact snapshot is adopted (never re-created); none → failed (unless Restic lock files show a
  backup may still be writing); several/conflicting → stays indeterminate for the operator;
- a missing manifest is written (never overwritten);
- each pass is audited as a `reconciliation` maintenance run. The command exits non-zero while runs remain
  indeterminate.

## Scheduling

Profiles are scheduled through Laravel's scheduler (defaults: database daily 02:00, media daily 02:30,
recovery weekly Sunday 03:30). Configure or disable each profile:

```dotenv
QURABA_BACKUP_SCHEDULE_DATABASE_TIME=02:00
QURABA_BACKUP_SCHEDULE_MEDIA=false
QURABA_BACKUP_SCHEDULE_TIMEZONE=Asia/Riyadh
```

(`frequency`/`day` are set in `config/quraba-backup.php`.) Times must be on a 5-minute boundary so hosts
that run the scheduler every 5 minutes never miss them. The host needs one cron entry — the package never
edits crontab:

```text
* * * * * cd /home/account/app && php artisan schedule:run >> /dev/null 2>&1
```

(`*/5 * * * *` is fine on cPanel.) No queue worker, Supervisor or Redis is needed; overlapping runs are
refused by the package lock. Scheduled backups also run while the site is in maintenance mode.
`QURABA_BACKUP_ENABLED=false` or `QURABA_BACKUP_SCHEDULE_ENABLED=false` schedules nothing.

## Listing

```bash
php artisan quraba:backup:list [--limit=20] [--profile=recovery] [--json]
```

Shows date, run UUID, profile, trigger, consistency, archive/media status and overall status from the
local catalog only (no B2 or Restic calls).
