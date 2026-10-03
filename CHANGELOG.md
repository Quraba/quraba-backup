# Changelog

## v1.1.0 — 2026-10-03

- Support PHP 8.4 alongside PHP 8.5 on the established Linux and Windows platforms. The doctor now
  accepts PHP 8.4+, and CI covers both runtimes with the real package suite; the manual B2 gate runs on
  the lowest supported PHP version.
- Add Windows amd64 support alongside Linux amd64/arm64. The managed installer verifies the official
  Restic 0.19.1 Windows ZIP against the package-pinned SHA-256 and official checksum manifest, extracts
  only its expected executable, verifies version and GOOS/GOARCH, and installs `restic.exe` safely.
- Make doctor, paths, executable verification and password-file diagnostics platform aware. Preserve the
  portable Laravel default `storage/app/private/quraba-secrets/restic-password`.
- Add real Windows PHP 8.4/8.5 CI jobs for the installer, full suite, repository operations, media backup
  and exact restore; retain the existing Linux and B2 gates. Windows ACL privacy and journal-directory
  syncing are documented as platform limitations.

## v1.0.1 — 2026-10-02

- Allow Laravel applications already using Guzzle 8 to install Quraba Backup. The v1.0.0
  `^7.9` Composer constraint blocked those consumers despite the package having no direct
  Guzzle API usage and its AWS SDK and Laravel dependencies supporting Guzzle 8.

## v1.0.0 — 2026-10-02

### Validated release gates

- Ubuntu 24.04 with PHP 8.5, MySQL 8.4 and MariaDB 11.4: full PHPUnit suite, Pint,
  PHPStan max, Composer validation, real Restic 0.19.1 integration, live restore end to end
  and clean-host disaster recovery.
- The manually triggered real Backblaze B2 S3 integration passed against a generated
  disposable prefix: authentication, object upload/existence/size/streamed hash, immutable
  manifest adoption and retention tombstone, Restic init/backup/snapshot listing/restore/
  forget/prune, wrong-credential distinction and exact object/version/delete-marker cleanup.
- These gates validate the stated Linux, database, Restic and B2 combinations. They do not
  claim a live restore on every supported hosting provider or architecture.

### Release hardening

- Catalog rebuild now streams each remote archive and requires its physical SHA-256 and exact byte count
  to match the immutable manifest, including a second check immediately before adoption.
- Database dumps record event policy, proven EVENT privilege, included object classes and exact object
  completeness. Exactness also requires direct grant proof for tables, views, triggers and routines. The
  default `auto` mode includes events when a direct grant proves capability; `required`
  fails closed, and `assume_none` explicitly records incomplete protection. Doctor, health, manifests and
  restore reports expose the decision.
- Indeterminate restore workspaces are marked with the exact restore UUID. Age-based cleanup refuses them;
  `workspace:cleanup --restore=UUID --execute` is permitted only after journal resolution.
- Restore journal writes now serialize per UUID through private file locks, reject an unsafe journal
  directory and treat failed directory sync as a durability failure.
- Optional package events, callback/Laravel Notification delivery and persistent health transition
  deduplication were added. Delivery failures do not change operation outcomes.
- Optional Filament 5 plugin adds a health dashboard, paginated run history, safe details, explicit
  authorization, scheduler-backed backup requests, restore dry runs and read-only retention planning.
- Added an explicitly opt-in real B2 disposable-prefix lifecycle test and a manual CI release gate;
  normal push and pull-request CI does not receive B2 credentials.

Supported target: PHP 8.5, Laravel 13, Linux, MySQL 8.4 or MariaDB 11.4, Restic 0.19.1 and Backblaze B2
S3 API. Live restore remains CLI-only and requires exact run UUID, quiescence, a verified safety backup
unless the target is proven empty, a durable journal and explicit confirmation.

### Live restore and disaster recovery

#### Changed
- **Retention records remote truth per component.** An immutable expiry record
  (`retention/{run}/{component}.json`) is written as soon as ONE component's absence is proven, so a deleted
  archive is never still advertised because forgetting the snapshot failed. Combined tombstones of the
  previous layout remain readable. Reconciliation settles each component on its own.
- **`quraba:backup:restore --force` is no longer refused**: together with `--confirm=<phrase>` it runs a
  live restore. `--force` alone changes nothing and shows what would be replaced.
- A scratch database that cannot be cleaned fails the validation with `restore.scratch_cleanup_failed`
  (never hidden behind the import's own outcome) and is refused by the next validation.
- Scratch imports strip `DEFINER` clauses (a confined scratch account cannot create objects for others).
- Media is reconstructed per root (`restic restore ID:{parent} --include /{root}`): the root directory and
  its content, none of its ancestors. This also works on Windows development machines.
- A restore no longer extracts the archived `.env` into its workspace.
- Restore source resolution, remote discovery and the dry run work without catalog tables; a local run row
  that is not terminal although an immutable manifest exists for it is treated as stale.
- `QuiescenceProvider` gained `claimsQuiescence()`; `RestoreStatus` gained `abandoned`; an indeterminate
  restore that crossed its destructive boundary can no longer be resolved as `failed`.
- Pre-restore safety backups no longer count towards a family's retention rules; they are kept by journal
  evidence and expire `retention.safety_days` after their restore was settled.
- Health reports unresolved live restores from the restore journals, also when the catalog is gone.

#### Added
- **Live restore** (`LiveRestoreService`): fresh non-destructive preparation, proven quiescence, verified
  pre-change safety backup (`pre_restore`, pinned), re-verification, then exact database replacement followed
  by exact media replacement, final verification; the application stays in maintenance mode.
- **Restore journal** (`RestoreJournalStore`): durable, private, atomic, forward-only, outside the database;
  every step recorded before it happens; unresolved journals block further live restores.
- `ExactDatabaseReplacement` (target proof, deterministic inventory, exact clear without `DROP DATABASE`,
  streamed import, post-import verification) and the `DatabaseReplacement` contract.
- `MediaStaging`, `ExactDirectoryReplacement` (private same-filesystem staging, two renames, no copy
  fallback, parked old trees).
- `SafetyBackupService`, `CleanHostProof`, `LiveRestoreAuthorization`, `RestoreStepObserver`.
- `quraba:backup:restore-reconcile`, `quraba:backup:bootstrap-env`, `quraba:backup:catalog:rebuild`,
  `quraba:backup:recovery-checklist`.
- Configuration: `restore.rewrite_definers`, `timeouts.database_import`, `database.dump_events`,
  `restic.media.roots.{name}.staging`.
- Documentation: `docs/restore.md`, `docs/disaster-recovery.md`.

### Backups

#### Changed
- **All package commands moved to the `quraba:backup:*` namespace** (bare `backup:*` belongs to
  spatie/laravel-backup). No aliases are provided.
- CI runs on pushes and pull requests for `development` and `master`, with MySQL 8.4 and MariaDB 11.4 services.
- Repository IDs are validated as Restic object IDs, not as snapshot IDs.
- The doctor's archive password check is now a required FAIL; media roots are validated by the real resolver.

#### Added
- Encrypted application archive (database dump + `.env` + `quraba-backup.json`) built by Spatie Laravel Backup's
  zip task behind the `ArchiveEngine` boundary, AES-256 enforced; `ArchiveVerifier`.
- MySQL/MariaDB dumps through Spatie db-dumper's dumper with an argument-array executor (no shell, credentials
  only in a temporary 0600 option file, `--single-transaction --quick`, `--result-file`).
- Backblaze B2 archive and manifest storage (`ArchiveStore`, `ManifestStore`) with deterministic paths,
  proof by existence/size, hash-proven adoption and collision refusal; the Restic prefix is off limits.
- `MediaSnapshotService` with central snapshot identity tags (`SnapshotIdentity`), exact-ID re-reading,
  retry adoption and ambiguity refusal.
- Repository identity continuity (`RepositoryIdentityGuard`, `quraba_backup_repository_identities`).
- `BackupManager` (database/media/recovery profiles, partial/indeterminate states), immutable remote manifests,
  quiescence providers (`none`, `laravel_maintenance`) with truthful consistency.
- `BackupReconciler` and `quraba:backup:reconcile`; `quraba:backup:run`; `quraba:backup:list`.
- Laravel scheduling of the three profiles (5-minute-boundary validated, overlap-safe via flock).
- Doctor backup-readiness group; Restic health reports the expected repository identity.

### Health, retention and restore dry run

- `quraba:backup:health`, `quraba:backup:retention` (plan by default, exact deletion with `--execute`),
  `quraba:backup:restic:check`, `quraba:backup:restic:prune`, `quraba:backup:discover --remote` and the
  restore dry run (`quraba:backup:restore`), with repository-identity consensus across manifests, strict
  private credential files and background scheduling.

### Foundation

- Package skeleton, configuration, domain enums/state machines, catalog, identity, workspaces, flock locks,
  exception taxonomy, secret redaction, pinned Restic runtime (installer, runner, repository init/health),
  doctor, database tool discovery, PHPStan max, Pint, CI.
