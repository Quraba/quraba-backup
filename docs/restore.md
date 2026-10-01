# Restore

`quraba:backup:restore` restores **one exact backup run**, identified by its run UUID. It never restores
"the latest" and never accepts a short Restic ID.

```bash
php artisan quraba:backup:restore --run=UUID --profile=full          # dry run (default): changes nothing
php artisan quraba:backup:restore --run=UUID --profile=full \
    --force --confirm=RESTORE_APPLICATION                             # LIVE: replaces application data
```

Profiles: `database` (the application database), `media` (every media root of the snapshot), `full` (both;
needs a complete Recovery Point).

## The dry run

Without `--force` and `--confirm` the command only prepares and validates, in a private workspace, and ends
with `Nothing was changed in the live application.` See [recovery operations](recovery-operations.md).

## The live gate

A live restore needs **both** `--force` and `--confirm=<phrase>`. The phrase is
`quraba-backup.restore.confirmation_phrase` (default `RESTORE_APPLICATION`) and must match exactly; a
question answered with "yes" is never enough. With only one of the two, the command prints what a live
restore **would** replace — source run, profile, consistency, exact archive SHA-256, exact snapshot ID,
repository ID, validation level, live database, media destinations, safety backup, quiescence — and exits
without changing anything.

A live restore is refused when

- another live restore is **unresolved** (see *Reconciliation*);
- the configured quiescence provider cannot **prove** that writers are stopped;
- the catalog tables are missing and the host was not declared clean (`--clean-host`, see
  [disaster recovery](disaster-recovery.md)).

## What a live restore does

```
gate → fresh preparation → plan live targets → quiescence → safety backup → re-verification
  ── destructive boundary ──
  database: clear exactly → import → verify → hand the audit row back
  each media root: park the live tree → activate the staged tree → verify
  → final verification → COMPLETED (the application stays in maintenance mode)
```

1. **Fresh preparation.** An earlier dry run is never trusted. The exact source is resolved again (the
   immutable remote manifest is required), the archive is downloaded and its SHA-256, encryption and
   metadata verified, the APP_KEY fingerprint compared (a mismatch blocks), the repository and the exact
   snapshot identity proven, the media staged privately and the dump validated at
   `restore.db_validation_level`. All of this happens **before** the application is quiesced.
2. **Proven quiescence.** There is no best-effort live restore and no downgrade switch. Use
   `QURABA_BACKUP_QUIESCENCE_PROVIDER=laravel_maintenance` and set `QURABA_BACKUP_NO_BACKGROUND_WRITERS=true`
   only when queue workers, the scheduler and every other writer really are stopped.
3. **Verified safety backup** of the current state, taken through the normal backup pipeline (trigger
   `pre_restore`): a database backup for a database restore, a media snapshot for a media restore, a full
   Recovery Point for a full restore. It must be completed, every component verified, its manifest readable
   remotely, the archive present with its exact size and the exact snapshot listed. If it cannot be
   verified the restore stops and nothing was changed.
4. **Re-verification** of the source, the repository, the live database identity and inventory, the staged
   trees and the quiescence, immediately before the boundary.
5. **Exact database replacement.** The target is proven (configured production connection, the database
   the server reports with `SELECT DATABASE()`, not a system schema, not the scratch database, unchanged
   since preflight). Exactly the inventoried objects — tables, views, sequences, procedures, functions,
   events; triggers go with their tables — are dropped, each qualified with the proven database name.
   `DROP DATABASE` is never issued. The validated dump is streamed to the `mysql`/`mariadb` client (argument
   array, credentials in a private option file, bounded by `QURABA_BACKUP_DB_IMPORT_TIMEOUT`). A clean exit is
   not success: the package reconnects and proves the base tables, the schema fingerprint and the migration
   history against the dump and the backup metadata.
6. **Exact media replacement.** Each root is replaced by two renames — live → a parked sibling
   (`.<root>.quraba-parked-<restore uuid>`), staged → live — and then verified. Nothing is ever copied or
   overlaid into a live root.
7. **Maintenance mode stays on.** The command ends with
   `Restore completed. The application remains in maintenance mode for operator verification.`
   Verify the application, then run `php artisan up` yourself. (`restore.auto_up` exists but is off.)

### Why the database is replaced before the media

The order is deliberate. The database import is the long, non-atomic step and the one most likely to fail.
It runs while every media root is still untouched, so a failure leaves exactly one component to recover
and the verified safety backup next to it. Media roots are swapped afterwards by renames that take moments
and keep the old tree complete next to the new one.

The database and the media roots are **not one transaction**, and neither are two media roots: each
component is journaled on its own, and all parked old trees are kept until the whole restore is verified.

## The restore journal

Every live restore writes a journal at `<private root>/journal/<restore uuid>.json` — outside the
application database, which the restore replaces. It is private (0600 in a 0700 directory), written
atomically (temporary file, fsync, rename, directory fsync, read-back), contains no secret and only moves
forward. It records the frozen source identities, the safety backup run, the quiescence, the database
target and inventory, every media root's live, staged and parked path, and each step.

**Every step is journaled before it happens.** If the journal cannot record the next step, the restore
stops before that step. The `quraba_restore_runs` row is only a mirror: after a database import it is
rebuilt from the journal, because the imported catalog is the old copy from the backup.

## Failure: FAILED or INDETERMINATE

- **Before the destructive boundary** the restore is `failed`. Nothing was changed; maintenance mode the
  package entered itself is left again (an application that was already down stays down).
- **After the boundary** a failure is `indeterminate` (exit code 3). Nothing is rolled back: the safety
  backup is not imported, parked media is not renamed back, SQL is not repeated. The journal, the safety
  backup, the parked media and the private workspace are kept and the application stays in maintenance
  mode. Health reports the application state as UNKNOWN and destructive retention is refused.

## Reconciliation

```bash
php artisan quraba:backup:restore-reconcile                    # list every restore journal
php artisan quraba:backup:restore-reconcile --restore=UUID     # decide one restore from evidence
```

Reconciliation reads the journal, the live database (identity, inventory, schema fingerprint), every media
root (live, staged, parked), the remote state of the safety backup and the audit row. Outcomes:

| Outcome | When |
|---|---|
| `completed` | every planned component is positively proven to be in its final restored state |
| `failed` | only when the journal shows the destructive boundary was never crossed |
| `indeterminate` | anything else; the command explains what it found and what you can do (exit code 3) |
| `abandoned` | only with `--abandon --confirm=ABANDON_RESTORE`: you close an indeterminate restore yourself |

It always repairs the catalog row from the journal. It never repeats SQL, never moves media, never
restores a safety backup and never changes maintenance mode. After `abandoned` a new live restore is
possible — typically the same source again, or the safety backup to return to the previous state.

If an abandoned restore left the database without the package tables, run the package migrations
(`php artisan migrate --path=vendor/quraba/quraba-backup/database/migrations`) before restoring again.

`--cleanup-parked` removes the parked pre-restore media of a **completed** restore — exactly the paths in
the journal. Do this after you verified the application.

## After a database or full restore

The restored catalog is the one from the backup: it predates the restore, the safety backup and every
backup made since. Bring it up to date from remote truth:

```bash
php artisan quraba:backup:reconcile                # the source run's own row is frozen mid-run in its dump
php artisan quraba:backup:catalog:rebuild --apply  # re-adopts newer backups, including the safety backup
```

## Safety backups and retention

A safety backup is pinned the moment it is requested and is never counted as an ordinary backup. Retention
keeps it (and the restore's source) while the restore is unresolved; after a failed or indeterminate
restore, indefinitely until that restore is explicitly resolved; after a settled restore, for
`retention.safety_days` (default 30). The evidence is the restore journal, not a timing guess.

## Media staging

Media is never restored directly into a live root. Each root is staged in a private directory on the
**same filesystem** as its destination, so it can be activated by a rename: the package workspace when it
is on that filesystem, otherwise a directory you configure for the root:

```php
'media' => ['roots' => ['public' => ['path' => storage_path('app/public'), 'staging' => '/mnt/media/.staging']]],
```

The configured directory must exist, be writable, not be inside the public web directory and not overlap a
media root. Without a private same-filesystem staging area the live media restore is refused; there is no
fallback to copying. Links that point outside a root are refused unless the root sets `allow_symlinks`.

## Limits

- MySQL and MariaDB only. Scheduled events are part of a backup only with `database.dump_events`; an exact
  replacement removes events that are not in the backup (the restore warns).
- A dump names the account that defined each view, trigger and routine. Restoring as another account is
  refused before anything is changed unless `restore.rewrite_definers` is enabled (those objects are then
  created as the restoring account). On servers with binary logging, an account without `SUPER` can only
  create triggers when `log_bin_trust_function_creators` is on.
- Import confinement relies on the archive being the verified one (SHA-256 from the immutable manifest,
  AES-256 password) and on the privileges of the production account; a dump containing `USE`, `CREATE
  DATABASE`, `DROP DATABASE` or account statements is refused.
- Verification proves structure (tables, schema fingerprint, migration history), not every row.
- After an indeterminate restore the private workspace is kept as evidence; it contains the plaintext SQL
  dump. Remove it with `quraba:backup:workspace:cleanup --execute` once you no longer need it.
