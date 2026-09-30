# Changelog

## Unreleased — Foundation (master plan phases 0–2)

### Added
- Laravel package skeleton: service provider, `config/quraba-backup.php`, `config/restic.php`, publishable config and migrations.
- Domain enums and controlled state machines for backup runs, artifacts, restore runs and maintenance runs.
- Catalog migrations and models: `quraba_backup_runs`, `quraba_backup_artifacts`, `quraba_restore_runs`,
  `quraba_backup_maintenance_runs`, `quraba_backup_settings` (UTC `DATETIME` storage, compare-and-set transitions).
- `ApplicationIdentity` (`QURABA_BACKUP_APP_ID`) and `backup:identity`.
- Operation workspaces (`op-{ULID}`) with flock-proven ownership, containment and non-destructive listing/cleanup commands.
- Process-safe `flock` coordination (global operation, restore, maintenance, installer locks).
- Exception taxonomy with stable machine-readable failure codes; sanitized failure persistence.
- Secret redaction (`SecretRedactor`, `ResticRedactor`) applied to every process output, log, exception and persisted failure.
- Restic runtime pinned to **0.19.1**: SHA-256-verified installer (`backup:install-restic`), deliberate binary
  resolution, the single `ResticRunner` process boundary, structured `ResticResult`/JSON parsing, the B2 S3
  `ResticRepository` with explicit `backup:restic:init`, and `backup:restic:health`.
- `backup:doctor` with human and JSON output.
- MySQL/MariaDB dump/client executable discovery.
- PHPUnit (Testbench) suite, PHPStan (level max), Pint, GitHub Actions CI with a checksum-verified Restic.

### Not yet implemented
Application archives (Spatie), media backups, backup orchestration, remote manifests, scheduling, retention,
restore, reconciliation and Filament.
