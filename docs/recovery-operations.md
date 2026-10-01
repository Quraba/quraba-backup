# Recovery operations

All commands use the existing application and environment identity. Keep the archive password, Restic
password and B2 credentials outside the host so a clean server can read the remote manifests and data.

## Health

`php artisan quraba:backup:health --json` reports `healthy`, `degraded`, `failed`, or `unknown`. It checks
the age of verified database, media and complete Recovery Point backups; partial and unresolved runs;
repeated failures; maintenance and abandoned workspaces; retention and Restic check freshness; and live restores, from the
restore journals: unresolved after the destructive boundary is `unknown`, unresolved before it is
`degraded`, and a destructive restore whose safety backup is missing remotely is `failed`. It samples
the newest archive's existence and size and the newest exact snapshot in an application-scoped listing.
It does not download an archive or run `restic check`. A temporarily unreachable remote is `unknown`.
Set `health.max_age_hours` and related limits in `config/quraba-backup.php` for your recovery objective.

## Retention

`php artisan quraba:backup:retention --json` is a read-only plan. Review its exact run UUIDs, artifacts,
keep/expire decisions and reasons. `--execute` takes the package lock, rechecks the plan, deletes exact
managed archive locators and full Restic snapshot IDs and proves their absence. It never selects `latest`,
never invokes Restic prune, and preserves the newest viable database, media and complete Recovery Point
history, pins and unresolved runs. Policies for database, media and recovery are independent `keep_latest`,
`keep_daily`, `keep_weekly`, `keep_monthly`, and `keep_yearly` counts. A deleted backup is never recreated.

**Remote truth is recorded per component.** As soon as the absence of ONE component is proven, an
immutable expiry record is written for it, and only then is that artifact marked expired locally:

```
{prefix}/{app_id}/retention/{run_uuid}/application_archive.json
{prefix}/{app_id}/retention/{run_uuid}/media_snapshot.json
```

So when the archive was deleted and forgetting the snapshot then fails, remote discovery and restore source
resolution already know that the archive is gone — the immutable manifest alone would still advertise it.
A record is never overwritten: an identical one is adopted, anything else at its path is a collision.
Readers take the union of a run's component records and of a combined tombstone
`retention/{run_uuid}.json` written by earlier releases (still read, no longer written).

An interrupted pass is `indeterminate`. `php artisan quraba:backup:reconcile` then records every component
that is physically absent and leaves a component that is still present to the next retention pass, which
completes the partial retention.

Restores and retention: the source and the safety backup of an unresolved live restore are protected, and
`--execute` is refused outright while any live restore is unresolved. A safety backup is kept indefinitely
after a failed or indeterminate restore until that restore is resolved, and for `retention.safety_days`
after a settled one. See [restore](restore.md#safety-backups-and-retention).

## Repository maintenance

`php artisan quraba:backup:restic:check` runs and audits a real repository check; `--read-data` additionally
reads all data and may incur B2 traffic. `php artisan quraba:backup:restic:prune` defaults to a Restic dry
run; `--execute` performs actual prune. Both use package locks and verify repository identity. Prune is
never scheduled automatically. The package never automatically unlocks Restic.

## Remote discovery

`php artisan quraba:backup:discover --remote --json` scans the complete relevant immutable manifest set
and the retention expiry records for the configured application and environment. It works without the
original local backup catalog, with an empty target database and without the package migrations. It shows run UUID, time, profile, status, consistency, repository ID, physical
archive and snapshot availability, expired components and whether a complete Recovery Point remains.
Invalid manifests are reported separately. Repository identity discovery accepts exactly one unique ID,
ignores valid manifests without an ID and refuses conflicting IDs. A previously bound local identity
remains authoritative.

## Restore dry run

`php artisan quraba:backup:restore --run=EXACT-UUID --profile=full --json` only prepares and validates.
Profiles are `database`, `media`, and `full`. `full` requires one complete, non-expired Recovery Point.
The dry run freezes local and remote source identities, refuses a mismatch or an expired component,
measures staging space and atomic rename capability, downloads the exact encrypted archive, checks its
SHA-256 and AES-256 contents, and extracts only the SQL dump into a private workspace (the archived `.env`
is verified but never extracted by a restore). It compares APP_KEY fingerprints without showing APP_KEY; a
mismatch is a blocker. Release fingerprint differences are warnings; older archives can report `unknown`.
For media it restores each root of the exact full Restic snapshot ID into that workspace — the root
directory and its content, none of its ancestors — checks repository ID and tags before and after, and
maps logical roots to current destinations without writing to them. The private workspace is cleaned and
every attempt is recorded in `quraba_restore_runs` with `mode=dry_run`; `destructive_started_at` stays
null. On a host without catalog tables the dry run still works and simply records nothing. Output ends
with `Nothing was changed in the live application.`

The default `artifact` database validation checks the verified encrypted archive, the nonempty dump, and
that the dump is a plain single-database dump (no `USE`, `CREATE|DROP DATABASE` or account statements).
`schema` additionally checks the deterministic CREATE-statement fingerprint stored by newer backups.
`scratch_import` requires a separate, empty scratch database and a distinct account with grants confined
to that database. The dump is imported there with its `DEFINER` clauses removed (the scratch account can
only create views, triggers and routines as itself), the structural fingerprint is compared, and the
imported objects are removed again. Older backups remain readable at `artifact` level when they lack the
newer schema fingerprint. Shared hosting can use `artifact` or `schema` when no scratch DB is available.

If the scratch database cannot be emptied again, the dry run **fails** with
`restore.scratch_cleanup_failed` and `scratch_contaminated: true` — also when the import itself had
failed; the cleanup failure is never hidden behind it. The live application was not touched. The next
scratch validation refuses the database because it is not empty; empty it yourself. Nothing broader is
attempted automatically.

## Live restore and disaster recovery

`--force --confirm=RESTORE_APPLICATION` turns the same command into a live restore: see
[restore](restore.md). Recovering onto a new, empty host: see [disaster recovery](disaster-recovery.md).

## Catalog rebuild

`php artisan quraba:backup:catalog:rebuild` is a plan; `--apply` adopts. It streams each physical archive
and requires the exact manifest size and SHA-256 before marking a catalog artifact verified. It rebuilds the local catalog from
valid remote manifests, retention expiry records, the physical existence of every archive (present with
its exact size), the application-scoped Restic snapshot listing and the repository identity. A component
becomes a verified artifact only when it was just observed physically; one recorded as expired is adopted
as expired, one that is simply gone as failed, and a run with nothing physically present is not adopted.
Runs the catalog already knows are left to `quraba:backup:reconcile`. Use it after catalog loss, on a
clean host, and after restoring a database whose catalog is older than the backups that exist remotely.

## Recovery checklist

`php artisan quraba:backup:recovery-checklist [--remote]` lists the materials a clean-host restore needs
(application ID, B2 endpoint, bucket, key ID and application key, archive password, Restic password,
repository identity) as configured or missing. It never prints a secret value.
