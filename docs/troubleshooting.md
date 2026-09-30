# Troubleshooting

Every package error carries a machine-readable code in brackets, e.g. `[restic.wrong_password]`.

| Code / symptom | Meaning and fix |
|---|---|
| `config.invalid` "QURABA_BACKUP_APP_ID is not configured" | Run `php artisan backup:identity --generate` once and add the line to `.env`. If config is cached, re-run `php artisan config:cache`. |
| `restic.unavailable` "No Restic binary is available" | Run `php artisan backup:install-restic`. |
| `restic.version_mismatch` | The binary is not the pinned version. For the managed binary: `php artisan backup:install-restic --force`. |
| `restic.checksum_mismatch` | The download did not match the pinned SHA-256. Nothing was installed. Check for a proxy/mirror altering files; never bypass. |
| `restic.repository_uninitialized` / health "not initialized" | Nothing exists at the configured location. **Verify endpoint, bucket and prefix first**, then `php artisan backup:restic:init`. |
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
| Abandoned workspaces | `php artisan backup:workspace:list`, then `backup:workspace:cleanup --execute`. Active workspaces are never removed. |
