# Configuration

All settings use the `QURABA_BACKUP_*` namespace. Secrets are read only from the environment and are never
stored in the database.

The optional Filament 5 panel is configured in the published `filament` section. Set
`pending_enabled=true` to schedule panel backup requests, then define the named Gates or callbacks in
[the Filament guide](filament.md). Panel authorizations default to denied. No secret is editable there.

Database metadata claims exact object completeness only when direct grants prove table reads, view
visibility, trigger visibility, routine visibility and EVENT capability, with the matching dump tool for
MariaDB sequences. The doctor warns about missing proof; an otherwise usable backup remains explicitly
marked incomplete.

## Identity

| Variable | Meaning |
|---|---|
| `QURABA_BACKUP_APP_ID` | Stable application UUID (generate once with `quraba:backup:identity --generate`). Never derived from APP_NAME, APP_URL, hostname, domain or directory. |
| `QURABA_BACKUP_ENVIRONMENT` | `production`, `staging`, … (falls back to `APP_ENV`; set it explicitly). |

## Backblaze B2 (customer-owned)

The B2 account, bucket and keys **belong to the customer**. Create a **bucket-scoped application key**
(never the master key). The package never uses a Quraba-owned account.

```dotenv
QURABA_BACKUP_B2_ENDPOINT=https://s3.us-west-004.backblazeb2.com
QURABA_BACKUP_B2_BUCKET=customer-backups
QURABA_BACKUP_B2_KEY_ID=...
QURABA_BACKUP_B2_APPLICATION_KEY=...
QURABA_BACKUP_PREFIX=quraba-backup
# QURABA_BACKUP_B2_REGION=us-west-004   # derived from the endpoint when omitted
```

Remote layout (one bucket may hold several applications):

```text
{bucket}/{QURABA_BACKUP_PREFIX}/{APP_ID}/archives/YYYY/MM/DD/{run_uuid}/application.zip
{bucket}/{QURABA_BACKUP_PREFIX}/{APP_ID}/manifests/YYYY/MM/DD/{run_uuid}.json
{bucket}/{QURABA_BACKUP_PREFIX}/{APP_ID}/retention/{run_uuid}/{component}.json
{bucket}/{QURABA_BACKUP_PREFIX}/{APP_ID}/restic/…        ← managed exclusively by Restic
```

The application key needs read, write, list and exact delete access to the bucket (archives, manifests,
retention tombstones and the Restic repository). Do **not** add B2 lifecycle rules that delete objects under
these prefixes; explicit package retention and Restic maintain them. Credentials stay in the environment; the package never stores
them in the database and never registers them as a named Laravel disk.
For bucket-restricted keys, Backblaze also documents `listAllBucketNames` as necessary for compatibility
with S3 SDK integrations; grant it if the SDK cannot authenticate a bucket-scoped key.

Restic may use separate credentials via `QURABA_BACKUP_RESTIC_B2_KEY_ID` /
`QURABA_BACKUP_RESTIC_B2_APPLICATION_KEY`; otherwise the shared B2 key is used. Credentials reach Restic only
as `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY` in the child process environment — never in arguments.

`QURABA_BACKUP_RESTIC_REPOSITORY` may override the derived location with an explicit `s3:https://…` URL.
Only `s3:https://` (and absolute local paths, for tests) are accepted; URLs with credentials, plain HTTP and
other Restic backends are refused.

## Restic repository password

Create a strong random password in a file readable only by the PHP user, **outside** the public directory.
The portable default is `storage/app/private/quraba-secrets/restic-password`; no environment path is needed
when the file is there. Keep this path out of the consumer application's version control and deployment
artifacts. On Linux, an optional path outside the application is also suitable:

```bash
mkdir -p ~/.quraba-backup && chmod 700 ~/.quraba-backup
openssl rand -base64 48 > ~/.quraba-backup/restic-password
chmod 600 ~/.quraba-backup/restic-password
```

```dotenv
QURABA_BACKUP_RESTIC_PASSWORD_FILE=/home/account/.quraba-backup/restic-password
```

**Store a copy of this password outside the server.** Without it, nobody — including Quraba — can open the
repository after a server loss. Restic receives only the file path (`RESTIC_PASSWORD_FILE`). On Linux,
files readable by other users are refused. On Windows, restrict the file and parent directory with NTFS
ACLs to the application account; PHP cannot prove ACL privacy equivalent to `0600`, so the doctor reports
that limitation. An explicit `QURABA_BACKUP_RESTIC_PASSWORD_FILE` override must be an absolute safe path.

## Archive password (mandatory)

Application archives contain `.env`, so they are always AES-256 encrypted:

```dotenv
QURABA_BACKUP_ARCHIVE_PASSWORD=<long random password, different from APP_KEY and the Restic password>
```

A missing or blank password refuses database and recovery backups before anything is created. Without the
password nobody can open the archives — keep an off-server copy. `QURABA_BACKUP_ARCHIVE_INCLUDE_ENV=false`
leaves `.env` out (not recommended: a clean-host recovery then needs `.env` from elsewhere).

## Database

The application connection (`QURABA_BACKUP_DB_CONNECTION`, default: the default connection) must use the
`mysql` or `mariadb` driver; the primary (write) host is dumped. The dump tool is discovered
(`mariadb-dump`, then `mysqldump`, preferring `mysqldump` for Oracle MySQL servers) or set with
`QURABA_BACKUP_DB_DUMP_BINARY`. `QURABA_BACKUP_DB_DUMP_TIMEOUT` (seconds, default 3600) bounds the dump;
`QURABA_BACKUP_DB_DUMP_ROUTINES=false` skips stored routines if the database user lacks the privilege.
`QURABA_BACKUP_DB_EVENT_POLICY=auto` (default) includes scheduled events only when `SHOW GRANTS` proves
`EVENT` or `ALL` on the application database. A backup without that proof is marked incomplete in archive
metadata, the remote manifest, health and restore reports. `required` refuses the backup unless the privilege
is proven; `assume_none` is an explicit shared-hosting opt-out and remains marked incomplete. Legacy
`QURABA_BACKUP_DB_DUMP_EVENTS=true` acts as `required`. Routines remain enabled by default; disabling them
also marks database object protection incomplete. The dump includes tables, views and triggers; MariaDB
sequences are reported as protected only with `mariadb-dump`.

For restore preparation, `QURABA_BACKUP_RESTORE_DB_VALIDATION` selects `artifact` (default), `schema`, or
`scratch_import`. The last level needs `QURABA_BACKUP_SCRATCH_CONNECTION`, a dedicated, empty database
configured as a Laravel connection. The package proves `SELECT DATABASE()` differs from production and
requires a distinct scratch account whose visible grants are confined to that database. The scratch user
needs schema creation and deletion rights there; do not use a root or globally privileged account. The
package fingerprints the import and removes imported tables, views, routines and events. The production user does not need
`CREATE DATABASE`. When granting access to a scratch database whose name contains underscores, escape each
underscore as `\_` in the database-level `GRANT` scope; otherwise the grant can cover other database names.

## Live restore

| Setting | Default | Meaning |
|---|---|---|
| `restore.confirmation_phrase` | `RESTORE_APPLICATION` | The exact phrase `--confirm=` must carry (at least 8 characters). |
| `QURABA_BACKUP_RESTORE_AUTO_UP` | `false` | Leave maintenance mode after a completed restore. Keep it off: verify first. |
| `QURABA_BACKUP_RESTORE_REWRITE_DEFINERS` | `false` | Create views, triggers and routines as the restoring account when the dump names another one. |
| `QURABA_BACKUP_RESTORE_REQUIRE_COMPLETE_DATABASE` | `false` | Block dry and live restore when the source does not prove event and routine coverage. |
| `QURABA_BACKUP_DB_IMPORT_TIMEOUT` | `7200` | Seconds the database client may take to import the dump. |
| `retention.safety_days` | `30` | How long the safety backup of a settled restore is kept. |
| `restic.media.roots.{name}.staging` | none | A private directory on the root's filesystem, used when the package workspace is on another one. |

A live restore also needs `QURABA_BACKUP_QUIESCENCE_PROVIDER=laravel_maintenance` together with
`QURABA_BACKUP_NO_BACKGROUND_WRITERS=true`. See [restore](restore.md).
See [Recovery operations](recovery-operations.md).

`QURABA_BACKUP_RELEASE_ID` identifies an application release in new encrypted archive metadata (hashed,
never exposed). Without it, the package uses the SHA-256 of `composer.lock` where available. Older archives
remain readable and report release compatibility as unknown.

## Media roots

Configured in `config/restic.php` (`restic.media.roots`); the default is `storage/app/public`:

```php
'media' => [
    'roots' => [
        'public'  => ['path' => storage_path('app/public')],
        'uploads' => ['path' => public_path('uploads'), 'optional' => true],
    ],
],
```

The key is a stable logical name recorded in manifests. Refused: `/`, HOME, the application root or its
parents, the whole `storage/` or `public/` directory, the package's private storage, a local Restic
repository, overlapping roots, and paths that traverse a symlink (unless `allow_symlinks`). `public/storage`
is Laravel's recreatable link — back up `storage/app/public` instead. Missing roots fail unless `optional`.

## Consistency and schedules

See [backups.md](backups.md#consistency-best_effort-vs-quiesced) for `QURABA_BACKUP_QUIESCENCE_PROVIDER`,
`QURABA_BACKUP_NO_BACKGROUND_WRITERS`, `QURABA_BACKUP_REQUIRE_QUIESCED`,
`QURABA_BACKUP_ALLOW_CONSISTENCY_DOWNGRADE` and the `QURABA_BACKUP_SCHEDULE_*` settings.

## Recovery secrets checklist

Keep these outside the server (e.g. in the customer's password manager):

- B2 key ID, application key, bucket and endpoint
- Restic repository password
- Archive encryption password (`QURABA_BACKUP_ARCHIVE_PASSWORD`)
- `QURABA_BACKUP_APP_ID`

The three secrets `APP_KEY`, the archive password and the Restic password must be independent; the doctor
fails if any two are identical. After storing them, set `QURABA_BACKUP_RECOVERY_SECRETS_ACKNOWLEDGED=true`.

## Paths

| Variable | Default |
|---|---|
| `QURABA_BACKUP_STORAGE_ROOT` | `storage/app/private/quraba-backup` |
| `QURABA_BACKUP_WORKSPACE_PATH` | `{root}/work` |
| `QURABA_BACKUP_LOCK_PATH` | `{root}/locks` (must be a local filesystem with working `flock`) |
| `QURABA_BACKUP_RESTIC_MANAGED_BINARY` | `{root}/bin/restic` |
| `QURABA_BACKUP_RESTIC_CACHE_DIR` | `{root}/cache/restic` |

None of these may be inside `public/`.

## Timeouts

Every Restic operation class has its own positive timeout (`QURABA_BACKUP_RESTIC_TIMEOUT_VERSION`, `_QUERY`,
`_INIT`, `_BACKUP`, `_RESTORE`, `_CHECK`, `_FORGET`, `_PRUNE`). Zero or negative values are refused — there
is no "unlimited".

## Restic repository identity

The first proven repository becomes this application's expected repository (see
[backups.md](backups.md#repository-identity)). Moving to a different repository or location therefore needs
an explicit operator decision; a later release will provide a command for it. Until then, a different
repository at the configured location is refused.

## Restic version

The Restic version is pinned by the package (`ResticRelease::VERSION`, currently 0.19.1) together with the
SHA-256 of each supported release archive. A published `config/restic.php` references these constants; a
different hard-coded version is refused.
