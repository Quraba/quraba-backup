# Doctor and health

## `php artisan backup:doctor [--json]`

Broad setup/readiness check. Each result is `PASS`, `WARN`, `FAIL` or `SKIP`; the command exits non-zero
when any `FAIL` is present (FAIL is reserved for required checks).

| Area | Checks |
|---|---|
| runtime | PHP ≥ 8.5, verified Laravel major, Linux, CPU architecture, live `proc_open` probe, required extensions, zip/posix, HTTP transport |
| identity | `QURABA_BACKUP_APP_ID`, environment identity (warns when derived from APP_ENV), enabled flag |
| storage | package directories outside `public/`, private root writable, workspace create/cleanup probe |
| locking | `flock` works, a **second process** is refused while the lock is held, operation lock state |
| database | driver is mysql/mariadb, `pdo_mysql`, `SELECT VERSION()` (flavor detection), dump/client tool discovery |
| b2 | endpoint/bucket/key configuration, endpoint format and region, outbound HTTPS to the endpoint |
| restic | configuration, installer decompression support, binary, exact version, repository config, credentials, password file, repository reachable/initialized/readable, snapshots, Restic locks |
| safety | independent secrets (APP_KEY / archive / Restic), password file and binary locations, dangerous media roots, off-server secrets acknowledgement |

The doctor never installs anything and never creates package directories; it only uses temporary probe
files that it removes. If Restic is missing it tells you to run `php artisan backup:install-restic`.

B2 bucket access is proven by the Restic repository probe (authenticated). The direct archive-store bucket
check arrives with the archive phase and is reported as `SKIP` for now.

## `php artisan backup:restic:health [--json]`

Cheap Restic health — **not** `restic check`. It reads the repository config, the snapshot list and the lock
list without taking a repository lock.

Overall states: `healthy`, `degraded` (usable with warnings, e.g. Restic lock files present), `failed`,
`unknown` (e.g. B2 unreachable or repository locked). `unknown` is never reported as healthy. The command
exits non-zero unless the state is `healthy` or `degraded`.
