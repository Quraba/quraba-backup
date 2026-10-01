# Troubleshooting

Every package error carries a machine-readable code in brackets, e.g. `[restic.wrong_password]`.

| Code / symptom | Meaning and fix |
|---|---|
| `config.invalid` "QURABA_BACKUP_APP_ID is not configured" | Run `php artisan quraba:backup:identity --generate` once and add the line to `.env`. If config is cached, re-run `php artisan config:cache`. |
| `restic.unavailable` "No Restic binary is available" | Run `php artisan quraba:backup:install-restic`. |
| `restic.version_mismatch` | The binary is not the pinned version. For the managed binary: `php artisan quraba:backup:install-restic --force`. |
| `restic.checksum_mismatch` | The download did not match the pinned SHA-256. Nothing was installed. Check for a proxy/mirror altering files; never bypass. |
| `restic.repository_uninitialized` / health "not initialized" | Nothing exists at the configured location. **Verify endpoint, bucket and prefix first**, then `php artisan quraba:backup:restic:init`. |
| `restic.wrong_password` | The password file does not open this repository. Restore the original password from your off-server copy; do not re-initialize. |
| `restic.storage_credentials_rejected` | B2 rejected the key ID/application key or the key lacks access to the bucket. |
| `restic.repository_unavailable` | Network/DNS/TLS failure or missing bucket. Check outbound HTTPS and the endpoint. |
| `restic.repository_locked` | Another Restic process holds a lock (or a stale lock remains). The package never unlocks automatically; investigate before running `restic unlock` manually. |
| `restic.init_refused` | Initialization was refused because absence was not confirmed (see the state in the message). |
| `operation.busy` | Another write-affecting Quraba Backup operation is running (the message shows its pid and purpose). Wait for it. |
| `lock.unavailable` | `flock` could not be established (unwritable lock directory or a filesystem without locking). Use a local path for `QURABA_BACKUP_LOCK_PATH`. |
| `process.timeout` | An operation exceeded its configured timeout; raise the specific `QURABA_BACKUP_RESTIC_TIMEOUT_*` value. |
| `process.launch_failed` | A binary could not be executed (missing, not executable, wrong architecture, `proc_open` disabled). |
| Doctor: "Password file … accessible by other users" | `chmod 600` the password file. |
| Doctor: "second process acquired a lock" | The lock directory is on a filesystem without working `flock` (e.g. some NFS mounts). Move it. |
| Abandoned workspaces | `php artisan quraba:backup:workspace:list`, then `quraba:backup:workspace:cleanup --execute`. Active workspaces are never removed. A retained restore workspace requires journal resolution and `workspace:cleanup --restore=UUID --execute`. |
| `archive.password_missing` | `QURABA_BACKUP_ARCHIVE_PASSWORD` is missing or blank. Archives contain `.env` and are never created unencrypted. |
| `archive.encryption_unsupported` | This PHP/libzip build lacks AES-256 ZIP encryption. Use a PHP build with a current libzip. |
| `archive.database_dump_failed` | The dump tool failed or is missing (see the redacted message); check `quraba:backup:doctor`. |
| Doctor: EVENT privilege not proven | Default event policy records the backup as incomplete; grant EVENT on the application database or choose the explicit `assume_none` policy. `required` refuses the backup. |
| `archive.verification_failed` | The archive failed its checks (entries, AES-256, password, metadata). Nothing was uploaded. |
| `archive.upload_failed` | B2 did not accept or prove the upload. If the outcome is unknown the run is `indeterminate`; run `quraba:backup:reconcile`. |
| `archive.collision` | A different object already exists at this run's archive path. It is never overwritten; investigate the bucket. |
| `manifest.upload_failed` | The manifest could not be written; the run is `indeterminate` until `quraba:backup:reconcile` writes it. |
| `manifest.collision` | A different manifest exists for this run; it is never overwritten. |
| `restic.repository_identity_mismatch` | The configured location holds a different repository than the one this application is bound to. Check endpoint/bucket/prefix and the password file; never re-initialize to "fix" it. |
| `media.path_unsafe` | A media root is dangerous (root, HOME, application root, private storage, overlap, symlink) or missing. |
| `restic.snapshot_failed` | Restic did not create a snapshot (message has the redacted reason). |
| `restic.snapshot_incomplete` | Restic could not read all files (exit 3); the snapshot is not trusted. Check file permissions in the media roots. |
| `restic.snapshot_ambiguous` | More than one snapshot claims the run; nothing is chosen or deleted. The run stays `indeterminate` for operator review. |
| `restic.snapshot_identity_mismatch` | A snapshot claims the run with another app/environment/kind, or could not be re-read with the exact identity. |
| `restic.snapshot_uncertain` | Restic failed but a snapshot may exist; run `quraba:backup:reconcile`. |
| `quiescence.failed` | Quiesced Recovery Points are required but cannot be proven, or maintenance mode could not be entered/released. |
| Exit code 1 with "quiescence could not be released" | The backup finished but `php artisan up` failed; bring the site up manually after checking it. |
| `restore.confirmation_required` | A live restore needs `--force` **and** `--confirm=<exact phrase>`. Nothing was changed. |
| `restore.quiescence_unproven` | The quiescence provider cannot prove writers are stopped. Use `laravel_maintenance` and declare `QURABA_BACKUP_NO_BACKGROUND_WRITERS=true` only when it is true. There is no best-effort live restore. |
| `restore.unresolved_restore` | An earlier live restore is unresolved. Run `quraba:backup:restore-reconcile --restore=UUID` and follow its guidance. |
| `restore.safety_backup_failed` | The pre-change safety backup could not be verified. Nothing was changed; fix backups (`quraba:backup:doctor`) first. |
| `restore.catalog_unavailable` | The catalog tables are missing. Run the package migrations, or use `--clean-host` on a new empty host. |
| `restore.clean_host_refused` | `--clean-host` was given but the database holds objects or a media root holds files. Omit the flag (a safety backup is taken) or empty the target. |
| `restore.database_target_unsafe` | The live database could not be proven, changed since preflight, is a system schema or the scratch database — or the dump defines objects for another account (see `restore.rewrite_definers`). Nothing was changed. |
| `restore.database_apply_failed` / `restore.database_verification_failed` | The import stopped or its result is not the backup's schema. The restore is `indeterminate`; see [restore](restore.md#reconciliation). |
| `restore.media_staging_unavailable` | No private staging area on the media root's filesystem. Configure `restic.media.roots.{name}.staging`. |
| `restore.media_apply_failed` / `restore.media_verification_failed` | A media root could not be renamed or is not the staged tree. Nothing is copied or rolled back; reconcile the restore. |
| `restore.source_changed` | The exact source changed between validation and the destructive boundary (expired, replaced repository, modified dump). Nothing was changed. |
| `restore.journal_failed` | The restore journal could not be written or read. The step it was about to record did not happen. Check the private storage directory. |
| `restore.scratch_cleanup_failed` | The scratch validation database could not be emptied; it is contaminated. The live database was not touched. Empty the scratch database manually. |
| `retention.restore_unresolved` | Destructive retention is refused while a live restore is unresolved. Reconcile the restore. |
| `retention.deletion_unproven` | A component could not be proven absent. What was proven is already recorded remotely; repeat retention later. |
| `recovery.env_bootstrap_failed` | `bootstrap-env` could not recover `.env` (wrong archive password, target exists or is public, archive without `.env`). Nothing was written. |
| Restore exit code 3 | The restore is `indeterminate`: it stopped after its destructive boundary. The application stays in maintenance mode; run `quraba:backup:restore-reconcile --restore=UUID`. |
