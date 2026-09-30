# Changelog

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

### Not yet implemented
Retention, restore (dry run and live), restore journal, pre-restore safety backups, disaster-recovery
commands and Filament.

## Foundation (master plan phases 0–2)

- Package skeleton, configuration, domain enums/state machines, catalog, identity, workspaces, flock locks,
  exception taxonomy, secret redaction, pinned Restic runtime (installer, runner, repository init/health),
  doctor, database tool discovery, PHPStan max, Pint, CI.
