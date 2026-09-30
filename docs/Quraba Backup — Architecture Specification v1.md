
# Quraba Backup — Architecture Specification v1

**Package:** `quraba/quraba-backup`  
**Namespace:** `Quraba\Backup`  
**Architecture status:** Proposed / ready for implementation planning  
**Primary target:** Laravel applications on normal VPS and cPanel/shared hosting  
**Backup target:** Backblaze B2 through its S3-compatible API  
**Database target:** MySQL-compatible databases: MySQL and MariaDB  
**Media engine:** Restic  
**Database + `.env` archive engine:** `spatie/laravel-backup`  
**Multi-tenancy:** Out of scope  
**Vault:** Out of scope  
**Redis:** Not required  
**Queue worker:** Not required  
**Filament:** Optional integration

---

# 1. Purpose

Quraba Backup is a Laravel backup and disaster-recovery package focused on a **single Laravel application**.

Its responsibility is not merely to create copies of files.

It must provide a complete lifecycle:

```text
capture
→ identify
→ verify
→ store offsite
→ monitor
→ retain safely
→ discover after disaster
→ validate restore source
→ create pre-change safety backup
→ restore
→ verify
→ reconcile interrupted operations
```

The package must continue to be useful when the original server is completely lost.

A successful backup therefore means:

> “We have a specifically identifiable and verified recovery artifact that we know how to restore.”

It does **not** mean:

> “A command returned exit code 0.”

---

# 2. Non-goals

Version 1 must deliberately NOT implement:

- multi-tenancy;
- tenant-specific databases;
- Vault;
- CENTRAL/platform databases;
- Google Drive;
- Dropbox;
- arbitrary cloud providers;
- PostgreSQL;
- SQLite production restore;
- Windows production servers;
- full application source-code backup;
- `vendor/`;
- `node_modules/`;
- logs;
- caches;
- compiled frontend assets;
- automatic destructive rollback;
- automatic `restic unlock`;
- multi-host distributed coordination by default;
- arbitrary Restic commands from PHP;
- automatic database creation through cPanel;
- automatic server provisioning.

Application source code is expected to come from Git/deployment infrastructure.

The backup covers the application's **mutable and irreplaceable state**.

---

# 3. Core architectural principle

The package has three different backup concepts.

They must never be confused.

## 3.1 Database Backup

Contains:

```text
Database dump
.env
small configured critical files, if explicitly enabled
```

Stored as an encrypted archive.

Created using Spatie.

Internally called:

```text
Application Archive
```

because it contains more than only the database.

---

# 3.2 Media Snapshot

Contains configured mutable filesystem roots such as:

```text
storage/app/public
public/uploads
other explicitly configured application-owned data
```

Created using Restic.

It receives an exact Restic snapshot ID.

---

# 3.3 Recovery Point

A Recovery Point is one orchestrated backup operation containing both:

```text
Application Archive
+
Media Restic Snapshot
```

under the **same run UUID**.

A Recovery Point is what the package uses for a full application restore.

This distinction is mandatory:

```text
Database Backup != Media Snapshot != Recovery Point
```

Two unrelated backups taken hours apart must never automatically be presented as a single Full Backup.

---

# 4. Consistency model

This is one of the most important parts of the architecture.

Backing up the database at 02:00 and files at 03:00 does **not** guarantee that they represent the same application state.

Therefore every Recovery Point records its consistency level.

```text
best_effort
quiesced
```

## `best_effort`

Database and media belong to one orchestrated run but normal application writes may continue while they are captured.

This is useful and often sufficient, but the UI and metadata must not claim strict consistency.

## `quiesced`

The application was intentionally prevented from changing while both components were captured.

This is the stronger recovery point.

The package must never claim `quiesced` unless a configured `QuiescenceProvider` proves that condition.

---

# 5. Quiescence architecture

A generic Laravel package cannot honestly guarantee that all application writers are stopped simply by calling:

```bash
php artisan down
```

Maintenance mode blocks web traffic but may not stop:

- queue workers;
- scheduled jobs;
- external consumers;
- custom CLI writers.

Therefore quiescence is pluggable.

Interface:

```text
QuiescenceProvider
```

Conceptually:

```php
enter(): QuiescenceSession
assertStillQuiesced(): void
leave(): void
```

Built-in providers:

```text
NoneQuiescenceProvider
LaravelMaintenanceProvider
```

`NoneQuiescenceProvider` produces `best_effort`.

`LaravelMaintenanceProvider` can only produce `quiesced` when the deployment configuration explicitly states that there are no other uncontrolled background writers, or when a custom application provider proves they have stopped.

Applications with complex queues may implement:

```text
CustomQuiescenceProvider
```

Version 1 must prefer truthful `best_effort` metadata over pretending a strict guarantee exists.

---

# 6. Backup identity

Every operation receives an immutable UUID:

```text
run_uuid
```

Example:

```text
5ff081a8-503e-44ba-91ae-30cfef9b972f
```

A retry of the **same logical operation** retains the same UUID.

A new backup gets a new UUID.

Run UUID is used across:

```text
database catalog
remote manifest
archive path
Restic tags
logs
health
restore
reconciliation
Filament
```

---

# 7. Stable application identity

Each installation has a stable UUID:

```text
QURABA_BACKUP_APP_ID
```

Example:

```text
6f614a0b-c447-4e36-9758-347858cbb46b
```

It must not be based on:

```text
APP_NAME
APP_URL
domain
server hostname
folder name
```

because those can change.

It identifies which backup family belongs to which application when a B2 bucket contains multiple sites.

The installer may generate it once.

It must then remain stable for the lifetime of that application.

---

# 8. Environment identity

Every artifact also contains:

```text
environment
```

Examples:

```text
production
staging
```

A production restore must never accidentally resolve a staging snapshot.

Environment identity therefore participates in Restic tags and remote manifests.

---

# 9. Backup storage layout

One Backblaze B2 bucket can hold both archive and Restic storage, but they must use completely separate prefixes.

Recommended layout:

```text
bucket
└── quraba-backup/
    └── {app-id}/
        ├── archives/
        │   └── YYYY/MM/DD/
        │       └── {run-uuid}/
        │           └── application.zip
        │
        ├── manifests/
        │   └── YYYY/MM/DD/
        │       └── {run-uuid}.json
        │
        └── restic/
            └── ...
```

The Restic prefix belongs **only to Restic**.

No package code may manipulate files under:

```text
restic/
```

directly through Flysystem.

Only the Restic binary may manage its repository.

---

# 10. B2 architecture

Backblaze B2 is accessed using its S3-compatible API.

The recommended credentials are:

```text
bucket-scoped application key
```

and not the B2 account master key.

B2 credentials are shared by:

```text
ArchiveStore
ResticRunner
ManifestStore
```

unless the application explicitly configures separate credentials.

B2 lifecycle rules must **not** automatically delete objects under the Restic repository prefix.

Retention must be performed by Restic itself.

---

# 11. Encryption model

There are three unrelated secrets.

They must not be derived from each other.

```text
APP_KEY
Archive encryption password
Restic repository password
```

B2 credentials are another independent credential set.

Do not reuse:

```text
APP_KEY
```

as either backup password.

---

# 12. Application archive encryption

Because the archive contains `.env`, encryption is mandatory.

If `.env` is enabled and the archive encryption password is absent:

```text
BACKUP REFUSED
```

The package must never silently produce:

```text
unencrypted .env backup
```

Recommended encryption:

```text
AES-256 ZIP encryption
```

subject to runtime capability verification.

`quraba:backup:doctor` verifies support before production use.

---

# 13. Restic encryption

Restic encryption is provided by Restic itself.

Preferred authentication:

```text
RESTIC_PASSWORD_FILE
```

with file permissions:

```text
0600
```

Preferred location:

```text
outside the application public directory
```

Example:

```text
/home/account/.quraba-backup/restic-password
```

If the deployment cannot use that location, the package may use:

```text
storage/app/private/quraba-backup/secrets/
```

but the operator must understand that a server-loss recovery still requires an out-of-band copy of the password.

The password never appears in process command arguments.

---

# 14. Disaster recovery secret requirement

A backup system whose recovery secrets exist only on the server being backed up is not disaster recovery.

Operators must retain externally:

```text
B2 Key ID
B2 Application Key
B2 Bucket / endpoint information
Archive encryption password
Restic repository password
QURABA_BACKUP_APP_ID
```

The package should provide:

```bash
php artisan quraba:backup:recovery-checklist
```

which lists what must be preserved, but does not print secret values.

---

# 15. Restic binary management

The package provides a managed Restic installer.

Default binary path:

```text
storage/app/private/quraba-backup/bin/restic
```

The path must be configurable.

Restic version is pinned by the package.

Example conceptual configuration:

```text
restic.version = 0.x.y
```

The installer performs:

```text
detect OS
detect CPU architecture
download matching binary
verify expected SHA-256
decompress
chmod executable
run `restic version`
verify exact expected version
```

Supported v1 host:

```text
Linux x86_64
```

Optional:

```text
Linux arm64
```

may be included if tested.

No runtime operation may download a newer Restic release automatically.

Upgrade of Restic is an explicit package/administrator action.

---

# 16. ResticRunner

There is exactly one class in the package that can execute Restic.

```text
ResticRunner
```

No other class may call:

```text
shell_exec
exec
system
passthru
proc_open
Symfony Process
```

for Restic directly.

ResticRunner responsibilities:

```text
validate binary
validate version
build command argument array
inject repository configuration
inject credentials through environment/password file
set operation-specific timeout
execute process
collect stdout/stderr
redact secrets
verify exit code
return structured result
```

It accepts only predefined operations.

Allowed operations:

```text
version
init
snapshots
backup
restore
ls
stats
check
forget
prune
```

There is deliberately no:

```php
runArbitraryResticCommand(string $command)
```

API.

---

# 17. Restic secret handling

Never:

```bash
restic --password=my-secret
```

Never put B2 application key in argv.

Repository password:

```text
RESTIC_PASSWORD_FILE
```

B2 keys:

```text
AWS_ACCESS_KEY_ID
AWS_SECRET_ACCESS_KEY
```

Repository address contains no credentials.

Error messages are passed through:

```text
ResticRedactor
```

before persistence or logging.

---

# 18. Restic snapshots

Restic snapshots are identified by their **full canonical snapshot ID**.

Never persist only:

```text
short_id
```

because short IDs are prefixes.

Never restore by:

```text
latest
```

Never restore by:

```text
tag selector only
```

Selectors are used for discovery/reconciliation.

Restore uses:

```text
exact full snapshot ID
```

---

# 19. Restic tags

Every snapshot created by the package receives controlled tags.

Example:

```text
quraba-backup
app:{app_uuid}
env:production
kind:media
run:{run_uuid}
```

Recovery Point media:

```text
kind:recovery_media
```

Possible safety snapshot:

```text
kind:safety_media
```

No secrets are placed in tags.

No application user input is placed in tags without validation.

---

# 20. Retry/idempotency

This is mandatory.

Suppose:

```text
restic backup succeeded
→ remote snapshot exists
→ PHP process died
→ snapshot ID was never stored locally
```

Retrying must not automatically create another snapshot.

Before creating a Restic snapshot for an existing run UUID:

```text
query repository by run UUID
→ zero snapshots:
   create
→ one snapshot:
   verify and adopt
→ more than one snapshot:
   refuse as ambiguous
```

The adopted snapshot must then be re-read using its exact full ID and verified.

---

# 21. Success standard

The following is insufficient:

```text
restic returned 0
```

Required:

```text
restic backup
↓
successful process
↓
parse snapshot ID
↓
query exact snapshot ID
↓
verify identity/tags
↓
persist snapshot ID
↓
artifact status = VERIFIED
```

Only after that may the backup component become completed.

---

# 22. Spatie architecture

Spatie is used only through:

```text
SpatieArchiveEngine
```

Other package services do not directly depend on Spatie APIs.

This isolates the package from future Spatie changes.

Conceptually:

```text
BackupManager
   ↓
ApplicationArchiveService
   ↓
SpatieArchiveEngine
```

---

# 23. Spatie does not own offsite lifecycle

Spatie should create the archive **locally first**.

Recommended pipeline:

```text
Spatie
↓
private operation workspace
↓
encrypted application.zip
↓
ArchiveVerifier
↓
SHA-256
↓
ArchiveStore
↓
Backblaze B2
↓
remote existence verification
```

This is preferable to letting Spatie directly own B2 because Quraba Backup then controls:

```text
run UUID
remote naming
checksum
retry
verification
retention
catalog
restore
```

Spatie becomes the archive creation engine only.

---

# 24. Application archive contents

Default:

```text
database SQL dump
.env
backup metadata
```

Optional small critical files may be configured explicitly.

The archive must NOT contain:

```text
storage media
vendor
node_modules
logs
cache
Restic repository
backup workspaces
Restic password file
backup encryption key
```

---

# 25. Database dump strategy

Supported production drivers:

```text
mysql
mariadb
```

Dumper executable discovery considers:

```text
mariadb-dump
mysqldump
```

Restore executable discovery considers:

```text
mariadb
mysql
```

The package does not assume one executable name.

Dump credentials never appear in argv.

Where the underlying dumper requires credentials, use a temporary MySQL option file:

```ini
[client]
host=...
port=...
user=...
password=...
```

created securely and removed afterwards.

---

# 26. Database consistency

Database dumps should use transactional dump semantics where supported:

```text
single transaction
quick/streaming
avoid global table locks
```

This provides internally consistent InnoDB database dumps without freezing the whole site.

This does not, by itself, guarantee consistency between the database and media filesystem.

That is why Recovery Points have an explicit consistency level.

---

# 27. Database schema metadata

Each application archive should contain an internal metadata file, for example:

```text
quraba-backup.json
```

It may include:

```text
manifest schema version
run UUID
application UUID
environment
created_at UTC
PHP version
Laravel version
package version
database driver
database server version
migration fingerprint
schema fingerprint
APP_KEY fingerprint
archive component information
```

Never store the actual `APP_KEY`.

A one-way fingerprint can detect an APP_KEY mismatch before restoring encrypted application data.

---

# 28. Archive verification

Before upload:

```text
archive exists
size > 0
archive opens successfully
password successfully opens it
expected SQL dump exists
.env exists when enabled
internal metadata exists
SHA-256 calculated
```

After upload:

```text
remote object exists
remote size matches expected
```

If practical, metadata may carry the SHA-256.

Backup status is not completed before these checks pass.

---

# 29. Remote manifest

Every completed backup operation uploads an immutable non-secret JSON manifest separately.

Example content:

```json
{
  "schema": 1,
  "run_uuid": "...",
  "app_id": "...",
  "environment": "production",
  "profile": "recovery",
  "consistency": "best_effort",
  "created_at": "...",
  "archive": {
    "path": "...",
    "sha256": "...",
    "bytes": 123456
  },
  "restic": {
    "snapshot_id": "...",
    "kind": "recovery_media"
  }
}
```

It must contain no passwords, keys, `.env` values, database credentials, or tokens.

---

# 30. Why remote manifests are required

The application's local database may disappear in the same disaster we are trying to recover from.

Therefore:

```text
backup_runs table
```

cannot be the only catalog.

Remote manifests permit:

```text
new server
↓
configure B2
↓
scan manifests
↓
discover backups
↓
restore exact recovery point
```

without the original database.

---

# 31. Manifest rule

Manifests are written **after** the artifacts they describe have been verified.

Never:

```text
write manifest claiming snapshot exists
→ create snapshot later
```

Correct order:

```text
artifact exists
→ artifact verified
→ exact identity persisted
→ manifest written
```

Catalog may lag physical reality after a crash.

Catalog must never lead physical reality.

---

# 32. Local database catalog

Primary tables:

```text
quraba_backup_runs
quraba_backup_artifacts
quraba_restore_runs
quraba_backup_maintenance_runs
quraba_backup_settings
```

Table prefixes may be configurable but should remain package-specific.

---

# 33. `quraba_backup_runs`

Purpose:

One logical backup operation.

Important fields:

```text
id
uuid

profile
    database
    media
    recovery

trigger
    scheduled
    manual
    pre_restore
    api

status
    pending
    preflighting
    running
    verifying
    partial
    completed
    failed
    canceled
    indeterminate

consistency
    none
    best_effort
    quiesced

requested_at
started_at
completed_at
failed_at

failure_stage
failure_code
failure_message

pinned_until
pin_reason

metadata JSON

created_at
updated_at
```

---

# 34. `quraba_backup_artifacts`

One run can have several artifacts.

Fields:

```text
id
backup_run_id

kind
    application_archive
    restic_snapshot
    remote_manifest

status
    pending
    creating
    uploading
    verifying
    verified
    failed
    expired

storage
    b2_archive
    restic
    b2_manifest

locator
snapshot_id

sha256
byte_size

created_at
verified_at
expired_at

metadata JSON
```

A Restic snapshot uses:

```text
snapshot_id
```

as its canonical identity.

The database archive uses:

```text
locator + sha256
```

---

# 35. Overall backup status

Component states must be retained independently.

Example:

```text
Application Archive  VERIFIED
Media Snapshot       FAILED
```

Overall:

```text
PARTIAL
```

Never collapse it into:

```text
COMPLETED
```

and never hide the valid database backup simply because media failed.

---

# 36. `quraba_restore_runs`

Fields:

```text
id
uuid

mode
    dry_run
    restore

profile
    database
    media
    full

status
    pending
    resolving
    reconstructing
    validating
    safety_backup
    quiescing
    applying
    verifying
    completed
    failed
    indeterminate

source_run_uuid
source_archive_locator
source_archive_sha256
source_snapshot_id

pre_change_run_uuid

requested_by_type
requested_by_id

destructive_started_at
completed_at
failed_at

failure_stage
failure_code
failure_message

metadata JSON

created_at
updated_at
```

---

# 37. Restore external journal

Database restore can replace the database that contains `quraba_restore_runs`.

Therefore the database row cannot be the only authority during a live restore.

Every live restore maintains an external journal:

```text
storage/app/private/quraba-backup/journal/
└── {restore_uuid}.json
```

Permissions:

```text
0600
```

Updates are atomic:

```text
write temporary file
fsync where practical
rename to final journal path
```

Journal stores:

```text
restore UUID
selected source identities
pre-change backup UUID
operation stage
which destructive boundaries were crossed
which media roots were swapped
whether database clear started
whether database import finished
verification results
timestamps
```

No secrets.

---

# 38. Why the journal matters

Example:

```text
database import completed
↓
process died
↓
restore_runs row was never updated
```

The correct answer is not automatically:

```text
FAILED
```

The state may be:

```text
INDETERMINATE
```

The journal provides evidence for reconciliation.

---

# 39. Operation workspaces

Every operation owns one isolated private workspace.

Layout:

```text
storage/app/private/quraba-backup/work/
└── op-{ULID}/
    ├── archive/
    ├── database/
    ├── restore/
    ├── media/
    └── temp/
```

No operation writes random files directly into one global temporary directory.

Only the owning operation may remove its workspace.

---

# 40. Workspace security

Requirements:

```text
not web-accessible
generated IDs only
no user-controlled path segment
path containment checks
no escaping via ../
secure permissions
cleanup in finally blocks
```

Abandoned workspaces are detectable separately from deletion.

Example:

```bash
php artisan quraba:backup:workspace:list
php artisan quraba:backup:workspace:cleanup --execute
```

Cleanup must refuse workspaces belonging to an active operation.

---

# 41. Media roots

Media backup is configuration-driven.

Example conceptual configuration:

```text
public_uploads
    storage/app/public

user_uploads
    public/uploads
```

Every root has:

```text
stable logical name
absolute current path
restore destination
symlink policy
```

Logical name is stored in manifests.

This permits a restored application to live at a different absolute filesystem path.

---

# 42. Default media scope

Recommended default:

```text
storage/app/public
```

Do not automatically back up the entire:

```text
public/
```

directory because that often contains deployable compiled assets.

Additional mutable paths must be explicitly configured.

---

# 43. Symlink policy

Default:

```text
allow_symlinks = false
```

A symlink reaching outside an application-owned backup root must never silently cause unrelated filesystem contents to be backed up or replaced.

The ordinary Laravel:

```text
public/storage
```

symlink does not need backup because it can be recreated using:

```bash
php artisan storage:link
```

---

# 44. Restic media backup

Flow:

```text
validate roots
↓
verify repository
↓
reconcile existing run UUID
↓
restic backup
↓
parse exact snapshot ID
↓
re-read exact snapshot
↓
verify app/env/kind/run identity
↓
persist artifact
```

Backup input is the configured roots only.

Never:

```text
application root
home directory
storage parent
```

unless explicitly configured.

---

# 45. Global operation coordination

The package must prevent incompatible operations from running concurrently.

For the single-host target, the default coordination method should be:

```text
OS file lock / flock
```

under:

```text
storage/app/private/quraba-backup/locks/
```

This avoids requiring:

```text
Redis
database cache lock
queue system
```

A crashed process automatically releases an OS file lock.

---

# 46. Optional distributed coordination

For multi-server deployments, an implementation may use:

```text
Laravel Cache Lock
```

with a shared backend such as Redis.

That is an optional advanced configuration, not a v1 dependency.

---

# 47. Lock classes

At minimum:

```text
global operation lock
repository maintenance lock
restore lock
```

Simplest safe v1 rule:

> Only one write-affecting Quraba Backup operation runs at a time.

That means no simultaneous:

```text
backup + restore
backup + prune
restore + retention
retention + check
```

Read-only health inspection may run concurrently.

This deliberately trades throughput for safety and simplicity.

For one application this is the correct trade.

---

# 48. Restic's locks remain authoritative

Package locking prevents known collisions before Restic starts.

Restic's own repository locking remains the backend authority.

The package must never automatically remove Restic locks.

A locked repository is reported as:

```text
BUSY / UNKNOWN
```

not “fixed”.

---

# 49. Backup request execution model

Shared hosting may have no long-running queue worker.

Therefore core correctness must not depend on:

```text
queue:work
Supervisor
Redis
Horizon
```

There are three execution paths.

## CLI

Immediate:

```bash
php artisan quraba:backup:run --profile=recovery
```

## Scheduler

Runs backup jobs directly from Laravel Scheduler.

## Filament

A web request must not run a multi-minute backup.

Filament creates a:

```text
PENDING backup run
```

The scheduler picks it up on the next tick.

This avoids:

```text
PHP web timeout
Cloudflare timeout
browser disconnect
queue-worker requirement
```

Optional queue execution can be added later.

---

# 50. Laravel Scheduler

One system cron is enough:

```text
* * * * * php artisan schedule:run
```

The package registers its tasks through Laravel Scheduler.

Potential schedules:

```text
pending request processor    every minute
database backup              configurable
media snapshot               configurable
recovery point               configurable
health check                 configurable
retention                    configurable
workspace cleanup            daily
Restic integrity check       optional
```

Every scheduled operation also uses package locks.

---

# 51. Recommended schedule model

Component backups and recovery points have independent schedules.

For example:

```text
Database archive:
daily

Media snapshot:
daily

Coordinated Recovery Point:
weekly
```

The exact frequencies remain configurable.

The package should not force maintenance mode every day simply to create strict recovery points unless the operator chooses that policy.

---

# 52. BackupManager

Top-level backup API:

```text
BackupManager
```

Responsibilities:

```text
create run
acquire operation lock
perform preflight
select profile
invoke appropriate services
derive final status
write remote manifest
clean workspace
emit lifecycle events
```

It knows orchestration.

It does not know Restic command details or Spatie internals.

---

# 53. Main backup services

Recommended service boundaries:

```text
BackupManager
BackupRunExecutor

ApplicationArchiveService
SpatieArchiveEngine
ArchiveVerifier
ArchiveStore

MediaSnapshotService

RecoveryPointService

RemoteManifestBuilder
RemoteManifestStore

BackupReconciler
BackupHealthService
```

---

# 54. Contracts

Recommended abstraction interfaces:

```text
ArchiveEngine
ArchiveStore
SnapshotEngine
ManifestStore
LockManager
QuiescenceProvider
SettingsRepository
RestoreJournalStore
```

Concrete implementation can therefore evolve without rewriting orchestration.

---

# 55. Recovery Point execution

Recovery Point flow:

```text
create run UUID
↓
preflight
↓
optional quiescence
↓
create application archive
↓
verify archive
↓
upload archive
↓
verify remote archive
↓
create Restic media snapshot
↓
verify exact snapshot
↓
release quiescence
↓
build remote manifest
↓
upload manifest
↓
mark run completed
```

If only one component succeeds:

```text
PARTIAL
```

No completed Recovery Point manifest should claim both components are valid unless both have been verified.

---

# 56. Ordering

Version 1 should use deterministic sequential execution rather than parallel execution.

Recommended:

```text
Application Archive
then
Media Snapshot
```

Advantages:

```text
lower resource pressure
simpler state machine
simpler shared-hosting behavior
clearer failure handling
easier debugging
```

Parallel execution can be considered later.

---

# 57. Backup reconciliation

Interrupted backup operations are expected.

Command:

```bash
php artisan quraba:backup:reconcile
```

For stale/incomplete runs it inspects reality.

Example Restic reconciliation:

```text
catalog says running
↓
query exact run tag
↓
snapshot exists
↓
verify exact ID
↓
adopt
```

Archive reconciliation:

```text
expected deterministic object path
↓
remote object exists
↓
verify metadata/checksum where available
↓
adopt
```

Never create duplicates merely because a local status write was lost.

---

# 58. Health architecture

Health has two different levels.

## Preflight health

Cheap.

Answers:

```text
Is Restic installed?
Correct version?
B2 configuration present?
Repository reachable?
Can the archive disk be reached?
Can required executable be found?
Can lock manager work?
```

Command:

```bash
php artisan quraba:backup:doctor
```

and/or:

```bash
php artisan quraba:backup:restic:health
```

## Recovery health

More expensive.

Answers:

```text
When was the last DB backup?
When was the last media snapshot?
When was the last Recovery Point?
Are their physical artifacts still present?
Are there partial runs?
Are there indeterminate restores?
Is retention stalled?
```

Command:

```bash
php artisan quraba:backup:health
```

---

# 59. Health states

Primary states:

```text
HEALTHY
DEGRADED
FAILED
UNKNOWN
```

Examples:

### HEALTHY

Recent required recovery artifacts exist and verify.

### DEGRADED

Usable backups exist but:

```text
last run partial
recovery point older than target
workspace cleanup failed
retention delayed
```

### FAILED

Example:

```text
required backup missing beyond policy
archive repeatedly fails
no valid recovery point exists
```

### UNKNOWN

Example:

```text
B2 currently unreachable
repository locked
physical state cannot be inspected
interrupted restore unresolved
```

Unknown must never silently become healthy.

---

# 60. `restic check`

Repository reachability is not the same as repository integrity.

`restic check` is a separate maintenance operation.

Normal backup does **not** run full `restic check`.

Recommended:

```text
cheap snapshot query:
frequent

restic check:
periodic / configurable

restic check --read-data:
manual or rare
```

Full data reads of B2 may have cost and time implications.

---

# 61. Maintenance runs

`quraba_backup_maintenance_runs` records operations such as:

```text
retention
prune
check
reconciliation
```

Fields conceptually:

```text
uuid
operation
status
dry_run
planned_items JSON
affected_items JSON
started_at
finished_at
failure_message
metadata
```

This provides an audit trail for destructive maintenance.

---

# 62. Retention principle

Retention is package-aware.

Do not blindly run:

```text
restic forget --keep-something
```

without understanding Recovery Point relationships and safety backups.

The package computes a retention plan first.

The destructive Restic invocation receives **exact snapshot IDs** selected by that plan.

---

# 63. Retention policies

Separate policies should exist for:

```text
database archives
media snapshots
recovery points
pre-restore safety backups
```

Calendar policy may support:

```text
keep latest
keep daily
keep weekly
keep monthly
keep yearly
```

Values are configurable.

---

# 64. Minimum recovery protection

Regardless of retention configuration:

```text
never delete the newest valid database backup
never delete the newest valid media snapshot
never delete the newest valid Recovery Point
```

unless the operator explicitly configures a destructive zero-history policy.

The safe default must never reduce the application to zero recovery copies.

---

# 65. Pre-restore safety pin

A safety backup created before a live restore is special.

While the restore is unresolved:

```text
retention MUST NOT delete it
```

If restore succeeds:

```text
retain for configured safety period
```

If restore fails after destructive mutation or becomes indeterminate:

```text
keep indefinitely
```

until the operator explicitly resolves/releases it.

This is a simplified version of Qurb's safety-pin concept and is worth keeping.

---

# 66. Retention command

Default:

```bash
php artisan quraba:backup:retention
```

means:

```text
PLAN ONLY
```

Actual deletion requires:

```bash
php artisan quraba:backup:retention --execute
```

Scheduled deletion only occurs when explicitly enabled in configuration.

---

# 67. Prune

`forget` and `prune` are separate operations.

`forget`:

```text
removes selected snapshots
```

`prune`:

```text
reclaims unreferenced repository pack data
```

Prune is repository-global and potentially expensive.

Default:

```text
not automatically scheduled
```

Command:

```bash
php artisan quraba:backup:restic:prune
```

plan/default behavior where supported.

Actual:

```bash
php artisan quraba:backup:restic:prune --execute
```

---

# 68. Restore philosophy

Restore has four strict principles.

```text
Exact source
Isolated reconstruction
Verification before destruction
Safety backup before mutation
```

Live production state is the last thing touched.

---

# 69. Restore never uses `latest`

Restore accepts:

```text
exact run UUID
```

and internally freezes:

```text
archive locator
archive SHA-256
Restic snapshot ID
```

before destructive work begins.

If any of these identities changes during the operation:

```text
REFUSE
```

Do not silently follow a newer source.

---

# 70. Restore profiles

Three restore modes:

```text
database
media
full
```

## Database Restore

Restores database state.

`.env` remains untouched by default.

## Media Restore

Restores configured media roots.

Database remains untouched.

## Full Restore

Restores:

```text
database
+
all configured media roots
```

from one Recovery Point.

---

# 71. `.env` restore policy

Backing up `.env` does **not** mean overwriting the live `.env` during every restore.

Normal restore:

```text
.env remains unchanged
```

The backed-up `.env` exists for:

```text
disaster recovery
APP_KEY recovery
credential recovery
manual inspection
clean-host bootstrap
```

Restoring `.env` to live state requires a separate explicit operation.

This avoids unexpectedly changing:

```text
current DB credentials
B2 credentials
domain
mail configuration
runtime settings
```

during an ordinary database restore.

---

# 72. Restore dry run

Restore defaults to dry run.

Example:

```bash
php artisan quraba:backup:restore --run=UUID --profile=full
```

must not mutate production.

Dry run performs as much real work as possible:

```text
resolve source
download application archive
verify SHA-256
decrypt archive
validate archive contents
extract SQL into workspace
inspect internal metadata
compare APP_KEY fingerprint
optionally import into scratch DB
restore exact Restic snapshot into private workspace
verify reconstructed media structure
check target paths
check free disk
check atomic rename support
build restore plan
clean temporary state
```

---

# 73. Live restore gate

Live restore requires both:

```text
--force
```

and an exact confirmation phrase.

Example:

```bash
php artisan quraba:backup:restore \
  --run=RUN_UUID \
  --profile=full \
  --force \
  --confirm=RESTORE_APPLICATION
```

A simple:

```text
yes/no
```

prompt is insufficient as the only destructive gate.

---

# 74. Source verification before maintenance

All expensive failures that can be found before downtime must be found before downtime.

Correct:

```text
download
restore to workspace
validate
stage
verify
↓
quiesce application
↓
destructive apply
```

Wrong:

```text
take site offline
↓
download 20 GB backup
↓
discover password is wrong
```

---

# 75. Restore workspace

Restored artifacts initially land in:

```text
private isolated workspace
```

Never directly into:

```text
production storage
live database
live .env
```

The reconstructed source is treated as untrusted until verified.

---

# 76. Scratch database validation

Strongest database restore validation:

```text
create/use isolated scratch database
↓
import source dump
↓
inspect resulting schema
↓
compare against backup metadata
```

However shared hosting may not permit:

```text
CREATE DATABASE
```

Therefore scratch validation is optional capability, not a mandatory architectural dependency.

Supported approaches:

```text
configured pre-created validation connection
privileged deployment-specific connection
no scratch DB
```

---

# 77. Validation levels

Record which level was achieved.

```text
artifact
schema
scratch_import
```

## `artifact`

Archive/checksum/decryption/SQL artifact verified.

## `schema`

Backup metadata also permits structural schema comparison.

## `scratch_import`

Dump was successfully imported into an isolated real MySQL/MariaDB database before production was touched.

UI and dry-run results show this clearly.

---

# 78. Database exact replacement

Database restore is **replacement**, not merge.

Why:

Source backup:

```text
users
posts
settings
```

Live database:

```text
users
posts
settings
future_feature
```

Simply importing the old dump may leave:

```text
future_feature
```

behind.

That produces a database state that never existed.

Therefore full/database restore does:

```text
prove target DB identity
↓
inventory current application DB objects
↓
clear current schema objects
↓
import validated dump
↓
verify restored schema
```

---

# 79. Database identity protection

Immediately before every destructive DB operation verify:

```text
configured connection is expected application connection
configured database name matches expected target
SELECT DATABASE() matches expected target
target is not protected/system database
```

Never trust configuration alone.

---

# 80. Database clearing

For MySQL/MariaDB:

```text
views removed appropriately
tables removed appropriately
foreign key checks controlled safely
session state restored/purged in finally
```

Do not drop the entire database unless a special bootstrap implementation requires it.

Dropping objects inside the intended database is narrower and safer than using broad `DROP DATABASE` privileges.

---

# 81. Database verification

After import:

```text
query resulting schema
compare schema fingerprint
verify required tables
verify migrations fingerprint where applicable
```

If scratch validation was available, compare live restored schema against the scratch result.

An exit code from `mysql` alone is not the final restore proof.

---

# 82. Media restore is exact replacement

Media restore is not:

```text
copy restored files over existing files
```

because files added after the backup would remain.

Instead each configured media root is restored exactly.

Example:

Backup:

```text
a.jpg
b.jpg
```

Live:

```text
a.jpg
b.jpg
c.jpg
```

After exact restore:

```text
a.jpg
b.jpg
```

---

# 83. Media restore staging

For each root:

```text
Restic reconstructs backup
↓
package identifies source tree
↓
build/prove staged destination tree
↓
confirm staged tree belongs to this restore
↓
confirm live destination unchanged
↓
atomic swap
```

No live tree is partially overwritten during the expensive preparation phase.

---

# 84. Atomic directory swap

Preferred commit:

```text
live → parked_old
staged → live
verify
remove parked_old
```

using filesystem rename.

Before mutation, dry-run must prove:

```text
staged path and target path support rename on same filesystem
```

Do not silently fall back from atomic rename to a long recursive copy.

If an atomic swap cannot be provided:

```text
live exact media restore is refused by default
```

A future explicitly unsafe compatibility mode may be considered separately.

---

# 85. Disk capacity

Before staging media:

```text
calculate expected restored payload
check free disk
include safety margin
```

Running out of disk while building staging should fail before live state is touched.

---

# 86. Full restore order

Recommended live full restore:

```text
source reconstructed and verified
↓
acquire global restore lock
↓
enter quiescence / maintenance
↓
create verified pre-change safety Recovery Point
↓
reconfirm source identities
↓
reconfirm staged media
↓
commit media exact replacements
↓
clear database
↓
import source database
↓
verify database
↓
verify media roots
↓
record terminal restore state
↓
leave application in maintenance for operator verification
```

The package must not automatically assume the site is ready for traffic merely because the command finished.

---

# 87. Pre-change safety backup

Before a destructive restore:

```text
database restore:
  create database safety backup

media restore:
  create media safety snapshot

full restore:
  create full Recovery Point safety backup
```

It must be verified before destructive mutation begins.

This backup uses a separate run UUID and trigger:

```text
pre_restore
```

---

# 88. Safety backup consistency

For a full restore, the safest path is:

```text
quiesce
↓
capture safety recovery point
↓
keep application quiesced
↓
apply restore
```

This can lengthen maintenance time but produces a real rollback point describing the immediately preceding application state.

The system should prefer correctness over hiding the cost.

---

# 89. No automatic rollback

Suppose the package has already replaced part of production and then fails.

It must NOT automatically start another destructive restore to the pre-change backup.

Instead:

```text
preserve safety backup
keep maintenance on
write journal
mark failed or indeterminate
instruct operator
```

Automatic rollback after a failed destructive operation compounds risk.

---

# 90. FAILED vs INDETERMINATE

## FAILED

The package has enough evidence to know that the requested operation did not complete.

## INDETERMINATE

The package crossed a destructive boundary but cannot prove the terminal application state.

Example:

```text
DB import may have succeeded
process died before verification
```

Indeterminate is a first-class state.

It is not converted to failure for convenience.

---

# 91. Restore reconciliation

Command:

```bash
php artisan quraba:backup:restore-reconcile --restore=UUID
```

It reads:

```text
external journal
current database state
remote source artifacts
safety backup
filesystem state
```

and tries to determine what actually happened.

Reconciliation may:

```text
mark completed
mark failed
keep indeterminate
repair missing audit metadata
```

It must not blindly repeat destructive SQL.

---

# 92. Unresolved restore rule

If an incomplete live restore journal exists:

```text
a second live restore is refused
```

until the previous operation is reconciled or explicitly abandoned through a safe administrative procedure.

This prevents two restore attempts from compounding uncertainty.

---

# 93. Maintenance mode

Live restore should integrate with Laravel maintenance mode.

Default behavior after destructive restore:

```text
DO NOT automatically run artisan up
```

Command output should state:

```text
Restore completed.
Verify the application.
Then bring it online deliberately.
```

The operator owns re-opening production.

---

# 94. Clean-host disaster recovery

The package must support recovery when:

```text
original host lost
original application DB lost
local backup catalog lost
only B2 survives
```

This is a primary requirement, not a future convenience.

---

# 95. Clean-host discovery

Command concept:

```bash
php artisan quraba:backup:discover --remote
```

Uses:

```text
B2 credentials
app ID
manifest prefix
```

It lists remote manifests.

It must not require the original `backup_runs` table.

---

# 96. Exact clean-host selection

Discovery can show dates for convenience.

Restore selection still freezes an exact:

```text
run UUID
archive locator + SHA-256
Restic snapshot ID
```

No restore command interprets:

```text
latest
```

as the source.

---

# 97. Clean-host `.env` bootstrap

A new server has a circular problem:

```text
old APP_KEY is in the backed-up .env
but Laravel needs temporary configuration to access B2
```

Therefore provide an explicit bootstrap flow.

Concept:

```bash
php artisan quraba:backup:bootstrap-env --run=UUID
```

It:

```text
uses minimal temporary recovery configuration
downloads selected encrypted archive
verifies SHA-256
decrypts it
extracts .env candidate
writes to explicitly chosen path with 0600 permissions
```

It never prints `.env` secrets to stdout.

After replacing `.env`, the operator restarts the recovery command with the original application's environment.

---

# 98. Clean-host database requirement

The target MySQL/MariaDB database must already exist.

The package does not require:

```text
CREATE DATABASE
```

permission.

This makes cPanel/shared hosting practical.

Clean-host restore can populate an empty database directly.

---

# 99. Database migrations during disaster recovery

Do **not** migrate the fresh application database to the current application schema before importing an exact backup merely to create package tables.

The restore should be able to operate statelessly from the external journal and remote manifest.

After importing the backed-up database, the package catalog is restored as part of that database state.

Any missing newer backup catalog entries can then be reconciled from remote manifests.

---

# 100. Remote catalog rebuild

Command concept:

```bash
php artisan quraba:backup:catalog:rebuild
```

Default:

```text
plan only
```

Apply:

```bash
php artisan quraba:backup:catalog:rebuild --apply
```

It scans remote manifests and can recreate missing:

```text
backup_runs
backup_artifacts
```

after:

```text
database rollback
clean-host restore
catalog corruption
```

Physical artifacts remain the authority.

---

# 101. Restore application version compatibility

Remote/internal manifests should record an application release fingerprint where available.

Possible sources:

```text
explicit RELEASE_ID
Git commit SHA if available
composer.lock SHA-256
```

Restore dry-run compares the backup's application version with the currently deployed code.

Mismatch is shown clearly.

A severe mismatch can require an explicit override for live restore.

This prevents silently restoring a database from a very different schema generation into incompatible application code.

---

# 102. APP_KEY compatibility

Archive internal metadata stores only:

```text
SHA-256 fingerprint of APP_KEY
```

During restore:

```text
current fingerprint == backup fingerprint
```

is expected for normal same-application restore.

Mismatch should block or strongly gate database restore because Laravel-encrypted database values may otherwise become unreadable.

Clean-host recovery should restore the backed-up `.env` first.

---

# 103. Settings architecture

Settings split into:

## Secrets

Only environment/config files:

```text
B2 key ID
B2 secret
archive password
Restic password/path
```

Never stored in package DB.

## Safe operational settings

May be stored in:

```text
quraba_backup_settings
```

Examples:

```text
enabled
schedule
retention values
health thresholds
preferred backup profile
maintenance behavior
```

Configuration file supplies defaults.

Database settings may override only explicitly allowed safe keys.

---

# 104. Media path configuration

Filesystem paths are deployment configuration.

They should not be freely editable through Filament.

Reason:

Changing:

```text
/storage/foo
```

to:

```text
/
```

is not an ordinary runtime preference.

Media roots remain code/config-defined.

Filament can display them read-only.

---

# 105. Filament integration

Filament is optional.

Core package does not require it for correctness.

If Filament 5 is installed, package may register a plugin.

Suggested pages:

```text
Backup Dashboard
Backup Runs
Restore
Health & Maintenance
Backup Settings
```

---

# 106. Backup Dashboard

Show:

```text
overall health
last database backup
last media snapshot
last Recovery Point
last strict/quiesced Recovery Point
B2 reachability
Restic repository health
next scheduled backup
unfinished operations
```

Avoid exposing credentials.

---

# 107. Backup Runs

Table columns:

```text
date
profile
trigger
consistency
application archive status
media snapshot status
overall status
duration
size where known
run UUID
```

Actions:

```text
view details
view manifest
run reconciliation
download encrypted application archive
prepare restore
```

---

# 108. Restore UI

Restore page must emphasize the exact selected source.

Show:

```text
run UUID
date
profile
consistency level
archive checksum
Restic snapshot ID
application/release version
validation level
```

Workflow:

```text
Select
↓
Dry Run
↓
Review Result
↓
Live Restore
```

Live action requires explicit typed confirmation.

---

# 109. Filament long-running actions

Filament does not perform the backup in the web request.

Action creates:

```text
pending run
```

The scheduler processes it.

UI polls/refreshed state from the database.

This keeps the package usable on shared hosting.

---

# 110. Filament authorization

Package defines gates/callbacks such as:

```text
view backups
run backups
manage settings
restore backups
run destructive maintenance
download archives
```

It must not assume a specific User model or role package.

Applications can map these gates to:

```text
Laravel policies
Spatie Permission
Filament Shield
custom logic
```

---

# 111. Events

Useful Laravel events:

```text
BackupRequested
BackupStarting
BackupCompleted
BackupPartial
BackupFailed

RestoreDryRunStarting
RestoreDryRunCompleted

RestoreStarting
RestoreCompleted
RestoreFailed
RestoreIndeterminate

RetentionPlanned
RetentionApplied

BackupHealthChanged
```

These allow applications to integrate:

```text
Slack
email
monitoring
activity logs
custom quiescence
```

without modifying core backup logic.

---

# 112. Logging

Every structured log should include where relevant:

```text
run_uuid
restore_uuid
profile
stage
artifact kind
duration
status
```

Never log:

```text
B2 secret
archive password
Restic password
.env contents
database password
temporary credential-file contents
presigned URLs
```

Repository/B2 diagnostics pass through a redactor.

---

# 113. Error taxonomy

Do not reduce everything to:

```text
RuntimeException("Backup failed")
```

Recommended categories:

```text
ConfigurationException
EnvironmentUnsupported
ArchiveCreationFailed
ArchiveVerificationFailed
ArchiveUploadFailed
ResticUnavailable
ResticRepositoryUnavailable
ResticSnapshotFailed
ResticSnapshotAmbiguous
SnapshotVerificationFailed
LockUnavailable
QuiescenceFailed
RestoreSourceInvalid
RestoreValidationFailed
SafetyBackupFailed
DatabaseRestoreFailed
MediaRestoreFailed
RestoreVerificationFailed
RestoreIndeterminate
RetentionRefused
```

Machine-readable failure codes should be persisted separately from human messages.

---

# 114. Time

All internal timestamps are stored and emitted as:

```text
UTC
```

UI may display the application's configured timezone.

Manifests contain ISO-8601 UTC instants.

No backup identity depends on local timezone strings.

---

# 115. Commands

Recommended v1 command surface:

```text
quraba:backup:doctor

quraba:backup:install-restic
quraba:backup:restic:init
quraba:backup:restic:health
quraba:backup:restic:check
quraba:backup:restic:prune

quraba:backup:run
quraba:backup:health
quraba:backup:list
quraba:backup:reconcile

quraba:backup:retention

quraba:backup:restore
quraba:backup:restore-reconcile

quraba:backup:discover
quraba:backup:bootstrap-env
quraba:backup:catalog:rebuild

quraba:backup:workspace:list
quraba:backup:workspace:cleanup

quraba:backup:identity
quraba:backup:recovery-checklist
```

Not every command has to be implemented in Phase 1, but the architecture reserves these responsibilities.

---

# 116. `quraba:backup:doctor`

This becomes one of the most important commands.

Checks:

```text
supported PHP
supported Laravel
Linux
CPU architecture

proc_open / Symfony Process usable

zip extension
required encryption support

database driver
mysqldump / mariadb-dump
mysql / mariadb

writable private storage

Restic binary
Restic version

operation locks

B2 endpoint
bucket access
credentials

archive destination

Restic repository configuration

archive password
Restic password configuration

media paths
path readability

scheduler configuration hints

free disk

dangerous config combinations
```

Output:

```text
PASS
WARN
FAIL
SKIP
```

Production readiness is non-zero exit when required checks fail.

---

# 117. Shared hosting requirements

Core package must work without:

```text
root
sudo
Docker
systemd
Supervisor
Redis
daemon process
```

It requires:

```text
PHP CLI
proc_open
writable private storage
outbound HTTPS
cron
MySQL/MariaDB CLI tools for full restore
```

Long-running work executes from CLI, not web PHP.

---

# 118. Memory behavior

Large dumps and restores must be streamed.

Never:

```php
file_get_contents($fiveGigabyteDump);
```

Database dump output:

```text
streamed
```

Database import:

```text
streamed to mysql stdin
```

B2 archive upload:

```text
streamed
```

File hashing:

```text
chunked
```

Restic handles media streaming itself.

Memory consumption should not scale with total backup size.

---

# 119. Timeouts

Different operation classes require different timeouts.

Example categories:

```text
version
query
archive
backup
restore
maintenance
```

Zero or negative timeout values are rejected.

Do not interpret:

```text
0
```

as unlimited.

The package should require explicit positive values.

---

# 120. Cleanup semantics

Cleanup failure must not rewrite a successful recovery artifact as failed.

Example:

```text
backup verified
remote manifest written
temporary directory could not be removed
```

Backup status:

```text
COMPLETED
```

with:

```text
cleanup warning
```

Health may become:

```text
DEGRADED
```

but the recovery point remains valid.

---

# 121. Remote deletion rules

Package deletion operates only on artifacts it can prove belong to:

```text
this app ID
this environment
this run
```

Never construct a broad B2 prefix from arbitrary input and recursively delete it.

Restic deletion uses exact snapshot IDs.

---

# 122. Archive retention deletion

Application archive deletion:

```text
select exact expired run
↓
protect pinned/newest backups
↓
delete exact object path
↓
verify object absence
↓
mark artifact expired
```

Do not mark expired before deletion has actually been observed.

---

# 123. Restic retention deletion

Media snapshot deletion:

```text
compute policy
↓
protect safety/source snapshots
↓
record plan
↓
restic forget EXACT_FULL_IDS
↓
re-list exact IDs
↓
prove absence
↓
mark expired
```

Process exit alone does not prove retention result.

---

# 124. Restic repository initialization

Local package installation must not silently initialize a Restic repository merely because a repository cannot be opened.

Explicit:

```bash
php artisan quraba:backup:restic:init
```

Initialization should first determine whether:

```text
repository exists
repository missing
credentials invalid
network failed
wrong password
```

and only initialize when the repository is genuinely absent.

A typo in repository configuration must not create a second empty backup repository and then report success.

---

# 125. Restic repository password recovery

Repository password is essential.

No package feature can recover a Restic repository without it.

`quraba:backup:doctor` should warn strongly when the package cannot confirm that an operator has acknowledged out-of-band secret storage.

It cannot itself know whether a password exists in a password manager, so this is an operational requirement.

---

# 126. Application archive password recovery

Same principle.

The encryption password must be held outside the application server.

The package must not store it inside the same encrypted archive it unlocks.

---

# 127. No secrets in remote manifest

Remote manifest is designed for discovery.

It may therefore contain:

```text
UUIDs
timestamps
artifact sizes
checksums
snapshot IDs
package versions
consistency metadata
```

It must not contain:

```text
APP_KEY
database credentials
B2 credentials
archive password
Restic password
.env values
```

---

# 128. Package config

Conceptual structure:

```php
return [

    'enabled' => true,

    'app_id' => env('QURABA_BACKUP_APP_ID'),

    'storage' => [
        'provider' => 'b2',
        'bucket' => env('QURABA_BACKUP_B2_BUCKET'),
        'endpoint' => env('QURABA_BACKUP_B2_ENDPOINT'),
        'key_id' => env('QURABA_BACKUP_B2_KEY_ID'),
        'application_key' => env('QURABA_BACKUP_B2_APPLICATION_KEY'),
        'prefix' => env('QURABA_BACKUP_PREFIX', 'quraba-backup'),
    ],

    'archive' => [
        'password' => env('QURABA_BACKUP_ARCHIVE_PASSWORD'),
        'include_env' => true,
    ],

    'restic' => [
        'binary' => storage_path('app/private/quraba-backup/bin/restic'),
        'version' => 'PINNED_VERSION',
        'password_file' => env('QURABA_BACKUP_RESTIC_PASSWORD_FILE'),
    ],

    'media' => [
        'roots' => [
            'public' => storage_path('app/public'),
        ],
    ],

    'schedule' => [
        //
    ],

    'retention' => [
        //
    ],

    'restore' => [
        'require_atomic_media_swap' => true,
        'auto_up' => false,
    ],
];
```

Exact naming can be refined during implementation.

---

# 129. Suggested `.env`

Conceptually:

```text
QURABA_BACKUP_APP_ID=

QURABA_BACKUP_B2_ENDPOINT=
QURABA_BACKUP_B2_BUCKET=
QURABA_BACKUP_B2_KEY_ID=
QURABA_BACKUP_B2_APPLICATION_KEY=
QURABA_BACKUP_PREFIX=quraba-backup

QURABA_BACKUP_ARCHIVE_PASSWORD=

QURABA_BACKUP_RESTIC_PASSWORD_FILE=
```

Avoid unnecessary duplication of:

```text
AWS_*
RESTIC_*
BACKUP_*
```

when the package can own a clear Quraba-specific namespace.

---

# 130. Internal package structure

Recommended:

```text
src/
├── Archive/
│   ├── ApplicationArchiveService.php
│   ├── SpatieArchiveEngine.php
│   ├── ArchiveVerifier.php
│   └── ArchiveStore.php
│
├── Backup/
│   ├── BackupManager.php
│   ├── BackupRunExecutor.php
│   ├── RecoveryPointService.php
│   └── BackupReconciler.php
│
├── Restic/
│   ├── ResticInstaller.php
│   ├── ResticRunner.php
│   ├── ResticRepository.php
│   ├── ResticTags.php
│   ├── ResticSnapshotService.php
│   ├── ResticSnapshotVerifier.php
│   └── ResticRedactor.php
│
├── Restore/
│   ├── RestoreManager.php
│   ├── RestoreSourceResolver.php
│   ├── RestorePlanner.php
│   ├── RestoreWorkspace.php
│   ├── RestoreJournal.php
│   ├── SafetyBackupService.php
│   ├── DatabaseRestoreService.php
│   ├── ExactDatabaseReplacement.php
│   ├── MediaRestoreService.php
│   ├── ExactDirectoryReplacement.php
│   └── RestoreReconciler.php
│
├── Retention/
│   ├── RetentionPlanner.php
│   └── RetentionExecutor.php
│
├── Health/
│   ├── DoctorService.php
│   └── BackupHealthService.php
│
├── Manifest/
│   ├── ManifestBuilder.php
│   └── ManifestStore.php
│
├── Coordination/
│   ├── LockManager.php
│   └── FileLockManager.php
│
├── Consistency/
│   ├── QuiescenceProvider.php
│   ├── NoneQuiescenceProvider.php
│   └── LaravelMaintenanceProvider.php
│
├── Models/
├── Enums/
├── DTOs/
├── Contracts/
├── Console/
├── Events/
├── Exceptions/
├── Filament/
└── Support/
```

---

# 131. Package dependencies

Core intended dependencies:

```text
Laravel framework
spatie/laravel-backup
league/flysystem-aws-s3-v3
Symfony Process
```

Filament should be optional.

Do not add dependencies merely to avoid writing small, well-contained infrastructure code.

---

# 132. Model enums

Use PHP enums for state domains instead of arbitrary strings throughout the code.

Examples:

```text
BackupProfile
BackupTrigger
BackupStatus
ArtifactKind
ArtifactStatus
ConsistencyLevel
RestoreMode
RestoreProfile
RestoreStatus
HealthState
MaintenanceOperation
```

Database still stores stable string values.

---

# 133. State transition discipline

Services must not freely execute:

```php
$run->status = 'whatever';
```

State transitions should pass through dedicated methods or services.

Example:

```text
markRunning()
markPartial()
markCompleted()
markFailed()
markIndeterminate()
```

This prevents illegal transitions such as:

```text
FAILED → COMPLETED
```

without reconciliation.

---

# 134. UTC and immutable identities

Identity fields must never depend on mutable labels.

Use:

```text
app UUID
run UUID
restore UUID
full Restic snapshot ID
artifact SHA-256
```

Not:

```text
domain
app name
backup filename alone
timestamp alone
short snapshot ID
```

---

# 135. Testing strategy

Backup/restore is high-risk and needs more than happy-path unit tests.

Test layers:

## Unit

```text
state machines
tags
manifest validation
path containment
retention planning
configuration validation
redaction
identity matching
```

## Integration

```text
real Restic against local filesystem repository
real encrypted archive creation
actual Symfony processes
workspace cleanup
locking
```

## Database integration

Test both:

```text
MySQL
MariaDB
```

where practical.

## Restore end-to-end

Example:

```text
create DB/files state A
backup
mutate to state B
restore A
prove DB == A
prove media == A
prove B-only files/tables removed
```

---

# 136. Failure injection tests

Tests must deliberately kill/fail operations at important boundaries.

Examples:

```text
Restic succeeds before catalog write
archive upload succeeds before status update
manifest write fails
process dies after media swap
process dies after DB clear
process dies after DB import
journal write fails
workspace cleanup fails
B2 becomes unreachable
repository locked
wrong archive password
wrong Restic password
wrong APP_KEY
wrong environment
wrong run UUID
ambiguous duplicate snapshot
```

The package should be designed around these cases, not patched for them later.

---

# 137. Security tests

Mandatory categories:

```text
no secret in argv
no secret in logs
no path traversal
symlink escape rejected
wrong-app snapshot rejected
wrong-environment snapshot rejected
short Restic ID rejected for destructive restore
modified archive rejected
checksum mismatch rejected
cross-filesystem atomic swap refused
wrong DB target refused
unresolved restore blocks new restore
```

---

# 138. Compatibility target

Recommended v1 contract:

```text
PHP 8.3+
Laravel 12/13
Linux
MySQL 8+
MariaDB compatible modern releases
Backblaze B2 S3 API
Restic pinned version
Filament 5 optional
```

Exact Composer constraints should be verified when implementation begins rather than guessed from this architectural document.

---

# 139. Versioning

Remote manifest has:

```text
schema_version
```

Application archive internal metadata also has:

```text
schema_version
```

Package must refuse manifest formats it cannot safely understand.

Never interpret unknown future fields/versions optimistically for destructive restore.

---

# 140. Upgrade philosophy

Package upgrade must not automatically:

```text
upgrade Restic repository format
change encryption method
change retention semantics
delete old backups
rewrite manifests
```

These are explicit migration operations.

Normal Composer update should be non-destructive.

---

# 141. Restic repository format

Restic repository initialization explicitly uses the repository format the package supports.

A future Restic version must not silently migrate an existing repository.

Repository format changes need:

```text
documented upgrade path
explicit command
backup verification
```

---

# 142. Public PHP API

The package may expose a small façade:

```php
QurabaBackup::request(...);
QurabaBackup::health();
QurabaBackup::findRun(...);
```

Do not expose low-level Restic execution publicly.

Application code should not be able to casually invoke:

```text
forget
prune
arbitrary restore
```

through generic helper methods.

---

# 143. Package principles

Implementation must follow these rules:

1. **Physical truth before catalog truth.**
2. **Exact identity before convenience.**
3. **Verification before destruction.**
4. **Dry run before live restore.**
5. **Safety backup before destructive restore.**
6. **No automatic rollback after destructive failure.**
7. **Secrets never in argv or logs.**
8. **No `latest` restore.**
9. **No hidden downgrade from atomic to non-atomic restore.**
10. **No requirement for Redis or queue workers.**
11. **No false claim of DB/media consistency.**
12. **No backup success based only on process exit.**
13. **Every retry is idempotent where practical.**
14. **Every destructive operation is auditable.**
15. **Clean-host disaster recovery is part of the architecture, not an afterthought.**

---

# 144. Recommended v1 product behavior

From the user's point of view the package should feel simple despite the safety underneath.

Typical setup:

```text
composer require quraba/quraba-backup

php artisan vendor:publish ...
php artisan migrate

php artisan quraba:backup:install-restic

configure B2 + passwords

php artisan quraba:backup:restic:init

php artisan quraba:backup:doctor
```

Then configure one cPanel Cron:

```text
php artisan schedule:run
```

Normal operation becomes automatic.

---

# 145. Typical daily backup

```text
Scheduler
↓
new run UUID
↓
global lock
↓
preflight
↓
encrypted DB + .env archive
↓
B2
↓
Restic media snapshot
↓
B2 Restic repository
↓
verify both
↓
remote manifest
↓
catalog
↓
health
```

From the user perspective:

```text
Backup completed.
```

Internally every identity and component remains separately tracked.

---

# 146. Typical restore

```text
Select exact Recovery Point
↓
Dry Run
↓
download + verify archive
↓
restore Restic snapshot to workspace
↓
validate DB and media
↓
show restore plan
↓
operator confirms
↓
maintenance
↓
fresh safety backup
↓
exact media replacement
↓
exact DB replacement
↓
verification
↓
keep maintenance on
↓
operator verifies
↓
artisan up
```

---

# 147. Clean-host disaster

```text
new hosting
↓
checkout application
↓
install Composer packages
↓
minimal recovery config
↓
quraba:backup:discover --remote
↓
choose exact run UUID
↓
recover original .env
↓
reload configuration
↓
configure empty database
↓
dry-run full restore
↓
live restore
↓
verify
↓
application returns
```

This is the real success criterion for the package.

---

# 148. Architecture decisions inherited from Qurb

Keep the principles:

```text
central Restic runner
full snapshot IDs
run UUIDs
process-array execution
secret isolation
physical verification
isolated workspaces
dry-run restore
pre-destructive recovery point
exact replacement
indeterminate state
reconciliation
retention planning
health separate from repository check
```

Remove Qurb-specific complexity:

```text
tenants
tenant barriers
Vault
central database
two Restic repositories
platform bootstrap logic
tenant retention families
round-robin fleet retention
catalog rollback fences
distributed SaaS coordination
tenant safety pins
Google Drive
```

The result should be significantly smaller while preserving the strongest safety ideas.

---

# 149. Final architecture

```text
                         QURABA BACKUP
                               │
             ┌─────────────────┴─────────────────┐
             │                                   │
           BACKUP                              RESTORE
             │                                   │
        BackupManager                       RestoreManager
             │                                   │
     ┌───────┴────────┐                  Resolve exact run
     │                │                          │
Application       MediaSnapshot             Dry-run first
ArchiveService       Service                    │
     │                │                    Reconstruction
     │                │                          │
 Spatie          ResticRunner               Verification
     │                │                          │
Encrypted         Exact Restic             Restore plan
DB + .env         Snapshot ID                   │
     │                │                  Pre-change backup
     └───────┬────────┘                          │
             │                              Quiescence
      Recovery Point                            │
             │                            Exact live apply
             │                                   │
      Remote Manifest                       Verification
             │                                   │
             └────────── Backup Catalog ─────────┘
                              │
                           Health
                              │
                         Retention
                              │
                       Reconciliation
```

---

# 150. Implementation boundary for Phase 1

Even though this document defines the full architecture, implementation should not begin with all features at once.

The first implementation foundation should contain only the primitives that every later feature depends on:

```text
package skeleton
configuration
enums
database catalog
operation workspace
file lock manager
Restic installer
ResticRunner
B2 configuration
Restic repository init/health
SpatieArchiveEngine
ArchiveStore
ApplicationArchiveService
MediaSnapshotService
remote manifest schema
BackupManager
basic doctor command
core tests
```

Restore, retention, Filament and disaster-recovery commands should be built on top of that foundation rather than being mixed into the first implementation batch.

---

# 151. Architecture verdict

The library should **not** be “Spatie + some Restic commands”.

It should be:

```text
Quraba Backup orchestration
      │
      ├── Spatie as the application-archive engine
      └── Restic as the media snapshot engine
```

with Quraba Backup owning:

```text
identity
state
verification
storage layout
retry
health
retention
restore
safety
reconciliation
Filament
disaster recovery
```

That gives us a package considerably simpler than Qurb while retaining the parts of Qurb's backup/restore architecture that actually matter for production safety.

**Architecture confidence: 0.97**