# Optional Filament 5 management panel

Quraba Backup runs without Filament. In a host application with `filament/filament:^5.0`, register the plugin on an authenticated panel:

```php
use Quraba\Backup\Filament\QurabaBackupPlugin;

return $panel->plugin(QurabaBackupPlugin::make());
```

Run the package migrations before enabling requests. The plugin provides **Backup Dashboard**, **Recovery Points**, **Restore**, and **Health & Maintenance**. It uses native Filament components. The panel does not configure storage credentials.

## Authorization

The host owns authorization. Define Laravel Gates with the names below, or set callbacks at `quraba-backup.filament.authorization.<ability>`. Each callback receives the authenticated panel user and must return exactly `true`. Every ability denies by default. There are no assumed roles or user model classes.

| Ability suffix | Allows |
| --- | --- |
| `view-dashboard` | Dashboard and Health & Maintenance pages |
| `view-details` | Recovery Points page and details |
| `view-recovery` | Restore page and history |
| `run-backup` | Manual database, media, and full backup requests |
| `dry-restore` | Dry Restore request |
| `live-restore` | Live Restore request when the feature flag is enabled |
| `run-health-check` | Health refresh and Doctor requests |
| `run-restic-check` | Standard Restic integrity check request |
| `plan-retention` | Read-only retention plan request |
| `configure-schedule` | Save and reset schedule overrides |

Page access and action submission are checked independently. The worker rechecks queued operation permission immediately before execution. By default, it resolves ordinary Eloquent `Authenticatable` models by their stored class and identifier. A host with another authentication implementation must bind `Quraba\Backup\Contracts\PendingOperationActorResolver` and resolve only its trusted actor types. An actor that cannot be resolved or is no longer authorized fails closed.

## Scheduler and feature flags

Set `quraba-backup.filament.pending_enabled=true` in published config to enable panel requests. Both the existing pending backup worker and the new pending operation worker run through Laravel Scheduler. The host needs its normal `php artisan schedule:run` cron entry every minute. No queue, Redis, Supervisor, daemon, or systemd service is needed. Each invocation claims at most one generic operation. Existing service and operation locks remain authoritative.

The pending-operation worker writes a small timestamp in private package storage each time it runs. This timestamp measures **only that panel worker**, not the Laravel scheduler or general backup cron. The panel shows it as disabled when `pending_enabled=false`; a missing timestamp does not then degrade recovery health. When pending operations are enabled and the timestamp is old, inspect the host cron and scheduler logs. Existing pending backup rows remain visible if the pending feature is later disabled. Scheduled backup definitions still use the package's existing maintenance-mode policy.

`quraba-backup.filament.live_restore_enabled` defaults to `false`. Enable it only after the host has configured live restore prerequisites, a quiescence provider, permissions, cron, and verified recovery procedures. It exposes the request flow while retaining the restore engine's guards.

## Daily workflows

The dashboard shows the latest complete Recovery Point, verified database and media components, successful backup, recent failures, repository and integrity evidence, unresolved restore warnings, worker observation, schedules, and recovery secret acknowledgement. Health derived from a background refresh includes its recorded check time. Without a recent remote check, the panel shows **Unknown**.

Recovery Points is a paginated Filament table with profile, status, trigger, consistency, component verification, date filters, and exact UUID search. Archive and snapshot columns say **N/A** when that component does not belong to the backup profile. A complete Recovery Point still requires a completed recovery-profile run with both verified components. Technical identifiers and hashes appear in details. Authorized users can request a Database Backup, Media Backup, or Full Recovery Point. Submission validates the package and feature state, records one pending `BackupRun`, and returns immediately. A database guard row serializes duplicate and pending-limit checks. The existing pending backup scheduler executes it later. Active work is polled modestly.

Restore's Dry Restore action requires an exact source UUID and scope (`database`, `media`, or `full`). Operators can browse recent known completed, verified Recovery Points or search them by UUID and see their time, consistency and status; the exact UUID is submitted. The advanced manual UUID field supports a source recoverable from its immutable remote manifest when the local catalog is stale. Neither source is selected automatically. The HTTP request records a typed pending operation. The worker invokes the existing `RestoreDryRunService`; the panel displays its recorded verification, warnings, blockers, and the fact that live data was unchanged. A completed dry run is operator guidance only. Live Restore performs fresh preparation and verification.

Health & Maintenance requests Health Refresh, Doctor, standard Restic check, and read-only Retention Plan as background operations. Results show their status and recorded time. Retention execution, Restic prune, repository initialization, and repository unlock remain CLI-only. The page displays configured/missing secret **states** without values. Failed or interrupted operations remain visible for investigation.

## Live Restore safety

The Live Restore action appears only when the flag and `live-restore` ability allow it. Its wizard collects the exact Recovery Point and scope, shows recorded source evidence and impact, requires a successful matching Dry Restore within 24 hours, requires two acknowledgements, and compares the configured confirmation phrase exactly on the server. Case and whitespace matter. The phrase is not stored in the operation row, private approval, logs, or result. It is removed from the action data after submission.

The request creates a short-lived, private, atomic one-time approval file outside the application database. It contains a random nonce and binds the operation UUID, exact source, profile, actor identity, and expiry. The database row holds only the nonce hash. The worker atomically consumes the matching approval once, verifies its private bytes after the rename, then constructs a narrowly scoped internal `LiveRestoreAuthorization`. `LiveRestoreService` independently checks its exact source and scope before doing work. A hand-inserted database row has no proof. Expired, mismatched, or consumed approvals fail. An interrupted live request must be submitted again with a new confirmation; it is never replayed automatically.

Before physical replacement, the service creates its existing Restore Journal and associates it with the consumed request evidence. From that point, the journal is authoritative for boundary crossing, physical state, indeterminate state, and reconciliation. Database replacement can remove or rewind the pending row, or remove its table entirely if the Recovery Point predates the Filament migration. The worker records a non-authoritative terminal request outcome in the private consumed evidence and does not turn a completed journal into a failed restore because the auxiliary row vanished. The Restore page continues to show journals and request correlation even when the table is missing. Unreadable or unresolved journals block new live requests. The service still enforces immutable source evidence, archive and snapshot verification, repository identity, APP_KEY and release compatibility, quiescence, a verified pre-restore safety backup, exact DB and media replacement, maintenance mode, and no automatic rollback.

After an exact restore of an older database, first verify the restored application and review its external journal while it remains in maintenance mode. If the panel reports missing package operation tables, run the host application's normal `php artisan migrate` process. The service provider registers the package migrations through `loadMigrationsFrom`; no package-specific migration command or automatic migration during restore is needed. Running migrations recreates operational tables but cannot recreate the consumed pending row. Use the external journal and private request correlation for that restore. Recheck effective schedules after migration, then continue the normal reconciliation and application-up runbook.

After live restore, use the CLI and the existing recovery runbook to reconcile the backup catalog, rebuild or adopt remote history when required, reconcile the restore, review and clean parked media, verify the application, then exit maintenance mode. The panel does not automatically execute those steps. Disaster, emergency, and clean-host recovery, restore abandonment, catalog recovery, destructive retention, and Restic prune remain CLI-led.

## Schedule overrides

Health & Maintenance can edit only the allowlisted schedule settings: global enabled state and timezone, plus enabled/frequency/day/time for database, media, and recovery backups. Frequency is daily, weekly, or monthly; time, day bounds, minute boundary, and IANA timezone are validated with the scheduler definition rules. Each displayed value identifies its source: `config` default or `database` override. Reset removes overrides and returns to deployment defaults. Credentials, URLs, filesystem paths, binaries, APP_ID, and `.env` are never editable here.

`BackupSetting` lives in the application database. A database restore may therefore rewind schedule overrides. Inspect the **effective** schedule afterwards. Config/environment values remain safe deployment defaults. Invalid or corrupt overrides fail closed: profile schedules are refused and logged; they never become arbitrary commands. The pending worker registration remains available for diagnosis.

## Secrets and operational boundaries

The panel never displays backup passwords, B2 credentials, APP_KEY, or database credentials. It stores typed, allowlisted operation payloads and redacted results. Only package-private files hold the worker heartbeat and one-time live approvals. Keep the package's private storage outside a public web root and backed by the host's normal filesystem permissions.

The CLI remains authoritative for disaster and emergency recovery, destructive retention execution, Restic prune, repository initialization and unlock, unsafe catalog rebuild/adoption, restore abandonment, parked-media deletion, and application maintenance-mode exit. Existing CLI confirmation behavior is unchanged.
