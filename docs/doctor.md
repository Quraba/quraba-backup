# Doctor and health

## `php artisan quraba:backup:doctor [--json]`

Broad setup/readiness check. Each result is `PASS`, `WARN`, `FAIL` or `SKIP`; the command exits non-zero
when any `FAIL` is present (FAIL is reserved for required checks).

| Area | Checks |
|---|---|
| runtime | PHP ≥ 8.5, verified Laravel major, Linux, CPU architecture, live `proc_open` probe, required extensions, zip/posix, HTTP transport |
| identity | `QURABA_BACKUP_APP_ID`, environment identity (warns when derived from APP_ENV), enabled flag |
| storage | package directories outside `public/`, private root writable, workspace create/cleanup probe |
| locking | `flock` works, a **second process** is refused while the lock is held, operation lock state |
| database | driver is mysql/mariadb, `pdo_mysql`, `SELECT VERSION()` (flavor detection), dump/client tool discovery, EVENT capability and object-completeness policy |
| b2 | endpoint/bucket/key configuration, endpoint format and region, outbound HTTPS to the endpoint |
| restic | configuration, installer decompression support, binary, exact version, repository config, credentials, password file, repository reachable/initialized/readable, snapshots, Restic locks |
| safety | independent secrets (APP_KEY / archive / Restic), password file and binary locations, off-server secrets acknowledgement |
| backup | Spatie archive engine, archive password (required), AES-256 support (required), `.env` readability, media roots (validated by the real resolver), authenticated read access to the archive and manifest prefixes, expected repository identity, free workspace disk, consistency configuration, active schedules and the cron hint, optional Filament availability |

The doctor never installs anything and never creates package directories; it only uses temporary probe
files that it removes. If Restic is missing it tells you to run `php artisan quraba:backup:install-restic`.

B2 access for archives and manifests is proven with a read-only request for a key that never exists
(authenticated, nothing written). The doctor never creates a backup and never binds a repository identity.

## `php artisan quraba:backup:restic:health [--json]`

Cheap Restic health — **not** `restic check`. It reads the repository config, the snapshot list and the lock
list without taking a repository lock.

Overall states: `healthy`, `degraded` (usable with warnings, e.g. Restic lock files present), `failed`,
`unknown` (e.g. B2 unreachable or repository locked). `unknown` is never reported as healthy. The command
exits non-zero unless the state is `healthy` or `degraded`.
