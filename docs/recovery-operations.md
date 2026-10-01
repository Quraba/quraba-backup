# Recovery operations

All commands use the existing application and environment identity. Keep the archive password, Restic
password and B2 credentials outside the host so a clean server can read the remote manifests and data.

## Health

`php artisan quraba:backup:health --json` reports `healthy`, `degraded`, `failed`, or `unknown`. It checks
the age of verified database, media and complete Recovery Point backups; partial and unresolved runs;
repeated failures; maintenance and abandoned workspaces; retention and Restic check freshness. It samples
the newest archive's existence and size and the newest exact snapshot in an application-scoped listing.
It does not download an archive or run `restic check`. A temporarily unreachable remote is `unknown`.
Set `health.max_age_hours` and related limits in `config/quraba-backup.php` for your recovery objective.

## Retention

`php artisan quraba:backup:retention --json` is a read-only plan. Review its exact run UUIDs, artifacts,
keep/expire decisions and reasons. `--execute` takes the package lock, rechecks the plan, deletes exact
managed archive locators and full Restic snapshot IDs, proves absence, writes immutable retention
tombstones, then marks the local catalog expired. It never selects `latest`, never invokes Restic prune,
and preserves the newest viable database, media and complete Recovery Point history, pins and unresolved
runs. Policies for database, media and recovery are independent `keep_latest`, `keep_daily`, `keep_weekly`,
`keep_monthly`, and `keep_yearly` counts. Interrupted deletion is marked indeterminate and can be settled
with `php artisan quraba:backup:reconcile`. A deleted backup is never recreated.

## Repository maintenance

`php artisan quraba:backup:restic:check` runs and audits a real repository check; `--read-data` additionally
reads all data and may incur B2 traffic. `php artisan quraba:backup:restic:prune` defaults to a Restic dry
run; `--execute` performs actual prune. Both use package locks and verify repository identity. Prune is
never scheduled automatically. The package never automatically unlocks Restic.

## Remote discovery

`php artisan quraba:backup:discover --remote --json` scans the complete relevant immutable manifest set
and retention tombstones for the configured application and environment. It works without the original
local backup catalog. It shows run UUID, time, profile, status, consistency, repository ID, physical
archive and snapshot availability, expired components and whether a complete Recovery Point remains.
Invalid manifests are reported separately. Repository identity discovery accepts exactly one unique ID,
ignores valid manifests without an ID and refuses conflicting IDs. A previously bound local identity
remains authoritative.

## Restore dry run

`php artisan quraba:backup:restore --run=EXACT-UUID --profile=full --json` only prepares and validates.
Profiles are `database`, `media`, and `full`. `full` requires one complete, non-expired Recovery Point.
`--force` is unsupported. The dry run freezes local and remote source identities, refuses a mismatch or
tombstone, measures staging space and atomic rename capability, downloads the exact encrypted archive,
checks its SHA-256 and AES-256 contents, and extracts a dump and optional `.env` candidate only into a
private workspace. It compares APP_KEY fingerprints without showing APP_KEY; a mismatch is a blocker.
Release fingerprint differences are warnings; older archives can report `unknown`. For media it restores
the exact full Restic snapshot ID into that workspace, checks repository ID and tags before and after,
and maps logical roots to current destinations without writing to them. The private workspace is cleaned
and every attempt is recorded in `quraba_restore_runs` with `mode=dry_run`; `destructive_started_at` stays
null. Output ends with `Nothing was changed in the live application.`

The default `artifact` database validation checks the verified encrypted archive and nonempty dump.
`schema` additionally checks the deterministic CREATE-statement fingerprint stored by newer backups.
`scratch_import` requires a separate, empty scratch database and a distinct account with grants confined
to that database. It compares the imported structural fingerprint and removes imported tables, views,
routines and events. Older
backups remain readable at `artifact` level when they
lack the newer schema fingerprint. Shared hosting can use `artifact` or `schema` when no scratch DB is
available. Live database replacement, media replacement, `.env` overwrite and live restore execution are
not part of this release.
