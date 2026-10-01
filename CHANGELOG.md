# Changelog

## Unreleased — Live restore and disaster recovery

### Changed
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

### Added
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

### Not yet implemented
Filament UI.

## Unreleased — Backups (master plan phases 3–5)

### Changed
- **All package commands moved to the `quraba:backup:*` namespace** (bare `backup:*` belongs to
  spatie/laravel-backup). No aliases are provided.
- CI runs on pushes and pull requests for `development` and `master`, with MySQL 8.4 and MariaDB 11.4 services.
- Repository IDs are validated as Restic object IDs, not as snapshot IDs.
- The doctor's archive password check is now a required FAIL; media roots are validated by the real resolver.

### Added
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

## Health, retention and restore dry run (master plan phases 6–7)

- `quraba:backup:health`, `quraba:backup:retention` (plan by default, exact deletion with `--execute`),
  `quraba:backup:restic:check`, `quraba:backup:restic:prune`, `quraba:backup:discover --remote` and the
  restore dry run (`quraba:backup:restore`), with repository-identity consensus across manifests, strict
  private credential files and background scheduling.

## Foundation (master plan phases 0–2)

- Package skeleton, configuration, domain enums/state machines, catalog, identity, workspaces, flock locks,
  exception taxonomy, secret redaction, pinned Restic runtime (installer, runner, repository init/health),
  doctor, database tool discovery, PHPStan max, Pint, CI.
