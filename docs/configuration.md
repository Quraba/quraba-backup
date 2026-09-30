# Configuration

All settings use the `QURABA_BACKUP_*` namespace. Secrets are read only from the environment and are never
stored in the database.

## Identity

| Variable | Meaning |
|---|---|
| `QURABA_BACKUP_APP_ID` | Stable application UUID (generate once with `backup:identity --generate`). Never derived from APP_NAME, APP_URL, hostname, domain or directory. |
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
{bucket}/{QURABA_BACKUP_PREFIX}/{APP_ID}/restic/        ← Restic only (this release)
{bucket}/{QURABA_BACKUP_PREFIX}/{APP_ID}/archives/…     ← later phase
{bucket}/{QURABA_BACKUP_PREFIX}/{APP_ID}/manifests/…    ← later phase
```

Do **not** add B2 lifecycle rules that delete objects under the `restic/` prefix; retention is done by Restic.

Restic may use separate credentials via `QURABA_BACKUP_RESTIC_B2_KEY_ID` /
`QURABA_BACKUP_RESTIC_B2_APPLICATION_KEY`; otherwise the shared B2 key is used. Credentials reach Restic only
as `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY` in the child process environment — never in arguments.

`QURABA_BACKUP_RESTIC_REPOSITORY` may override the derived location with an explicit `s3:https://…` URL.
Only `s3:https://` (and absolute local paths, for tests) are accepted; URLs with credentials, plain HTTP and
other Restic backends are refused.

## Restic repository password

Create a strong random password in a file readable only by the PHP user, **outside** the public directory
(ideally outside the application):

```bash
mkdir -p ~/.quraba-backup && chmod 700 ~/.quraba-backup
openssl rand -base64 48 > ~/.quraba-backup/restic-password
chmod 600 ~/.quraba-backup/restic-password
```

```dotenv
QURABA_BACKUP_RESTIC_PASSWORD_FILE=/home/account/.quraba-backup/restic-password
```

**Store a copy of this password outside the server.** Without it, nobody — including Quraba — can open the
repository after a server loss. Restic receives only the file path (`RESTIC_PASSWORD_FILE`). Files readable
by other users are refused.

## Recovery secrets checklist

Keep these outside the server (e.g. in the customer's password manager):

- B2 key ID, application key, bucket and endpoint
- Restic repository password
- Archive encryption password (`QURABA_BACKUP_ARCHIVE_PASSWORD`, used by application archives in a later phase)
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

## Restic version

The Restic version is pinned by the package (`ResticRelease::VERSION`, currently 0.19.1) together with the
SHA-256 of each supported release archive. A published `config/restic.php` references these constants; a
different hard-coded version is refused.
