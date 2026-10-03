
# Quraba Backup — Implementation Master Plan

> Historical v1 implementation plan. The current platform contract, including upcoming Windows amd64
> support in v1.1.0, is documented in the README and operational guides.

**Package:** `quraba/quraba-backup`  
**Namespace:** `Quraba\Backup`  
**Target:** Single-application Laravel backup and disaster recovery  
**Storage:** Backblaze B2 via S3 API  
**Archive engine:** Spatie Laravel Backup  
**Media engine:** Restic  
**Database:** MySQL / MariaDB  
**PHP:** 8.5+  
**Shared Hosting:** First-class supported environment  
**Multi-tenancy:** Out of scope  
**Vault:** Out of scope

---

# Implementation Philosophy

We will not build everything at once.

The dependency chain is:

```text
Foundation
    ↓
Restic Runtime
    ↓
Archive + B2
    ↓
Media Snapshots
    ↓
Backup Orchestration
    ↓
Retention / Health
    ↓
Restore Dry Run
    ↓
Live Restore
    ↓
Disaster Recovery
    ↓
Filament
    ↓
Production Hardening
```

Restore work must not begin until Backup creation and verification are stable.

Destructive restore must not begin until dry-run restore is proven.

---

# Phase 0 — Repository & Architecture Freeze

## Goal

Create the Composer package repository and freeze the conventions every later phase depends on.

No backup functionality yet.

## Repository

Recommended repository:

```text
Quraba/quraba-backup
```

Composer package:

```text
quraba/quraba-backup
```

Namespace:

```text
Quraba\Backup
```

This naming is preferable because Quraba may later publish:

```text
quraba/activity-log
quraba/media
quraba/seo
quraba/backup
```

without repeating `quraba-` in every package name.

## Initial structure

```text
quraba-backup/
├── src/
├── config/
├── database/
│   └── migrations/
├── resources/
├── routes/
├── tests/
├── docs/
├── composer.json
├── phpunit.xml
├── README.md
└── CHANGELOG.md
```

## Initial package components

Create:

```text
QurabaBackupServiceProvider
QurabaBackup facade
package config publishing
migration loading
command registration
event registration
```

## Configuration split

Use two package-owned configurations.

### `config/quraba-backup.php`

Contains:

```text
application identity
B2 configuration
archive configuration
backup profiles
schedules
retention
restore policy
health thresholds
workspace paths
locking
Filament integration
```

### `config/restic.php`

Contains:

```text
Restic binary
Restic version
repository
password file
timeouts
media roots
tags/environment
maintenance
```

This also preserves the requirement that media paths are configurable through:

```text
config/restic.php
```

## Dependency policy

Install only dependencies actually required by the architecture.

Likely:

```text
spatie/laravel-backup
league/flysystem-aws-s3-v3
symfony/process
```

Filament remains optional.

## CI foundation

CI should start with:

```text
PHP 8.5
current supported Laravel
MySQL
MariaDB where practical
```

Do not promise support for unknown future Laravel majors automatically.

The rule should be:

> Support the current verified Laravel major, then add each future major after CI validation.

## Phase 0 gate

Before proceeding:

```text
composer install            PASS
package discovery           PASS
config publishing           PASS
migration discovery         PASS
test application boot       PASS
PHPStan/static analysis     PASS
coding style                PASS
```

---

# Phase 1 — Core Domain, Catalog, Workspaces & Locks

## Goal

Build the infrastructure every backup and restore operation depends on.

No Spatie or Restic operation yet.

---

## 1.1 Core enums

Create enums such as:

```text
BackupProfile
    Database
    Media
    Recovery

BackupTrigger
    Scheduled
    Manual
    PreRestore
    Api

BackupStatus
    Pending
    Preflighting
    Running
    Verifying
    Partial
    Completed
    Failed
    Canceled
    Indeterminate

ArtifactKind
    ApplicationArchive
    ResticSnapshot
    RemoteManifest

ArtifactStatus
    Pending
    Creating
    Uploading
    Verifying
    Verified
    Failed
    Expired

ConsistencyLevel
    None
    BestEffort
    Quiesced

RestoreMode
    DryRun
    Restore

RestoreProfile
    Database
    Media
    Full

RestoreStatus
    Pending
    Resolving
    Reconstructing
    Validating
    SafetyBackup
    Quiescing
    Applying
    Verifying
    Completed
    Failed
    Indeterminate

HealthState
    Healthy
    Degraded
    Failed
    Unknown
```

No arbitrary strings scattered through the package.

---

## 1.2 Database migrations

Create:

```text
quraba_backup_runs
quraba_backup_artifacts
quraba_restore_runs
quraba_backup_maintenance_runs
quraba_backup_settings
```

Indexes must cover:

```text
uuid
status
profile
trigger
created_at
backup_run_id
artifact kind
snapshot ID where appropriate
```

UUID uniqueness must be enforced in DB.

---

## 1.3 Models

Create:

```text
BackupRun
BackupArtifact
RestoreRun
BackupMaintenanceRun
BackupSetting
```

Models should expose state-transition methods instead of direct status modification.

Example:

```text
markRunning()
markVerifying()
markCompleted()
markPartial()
markFailed()
markIndeterminate()
```

---

## 1.4 Stable Application Identity

Implement:

```text
ApplicationIdentity
```

Read:

```text
QURABA_BACKUP_APP_ID
```

Validate UUID.

If missing during install/setup:

```text
generate UUID
```

but never silently change an existing identity.

Command:

```bash
php artisan quraba:backup:identity
```

shows:

```text
App ID
Environment
Package version
```

but no secrets.

---

## 1.5 OperationWorkspace

Implement:

```text
OperationWorkspace
```

Layout:

```text
storage/app/private/quraba-backup/work/
└── op-{ULID}/
    ├── archive/
    ├── database/
    ├── media/
    ├── restore/
    └── temp/
```

Requirements:

```text
private path
unique owner
safe containment
no user-defined path fragments
cleanup on normal exit
diagnosable abandoned workspace
```

Commands reserved:

```bash
quraba:backup:workspace:list
quraba:backup:workspace:cleanup
```

---

## 1.6 FileLockManager

Primary v1 coordination:

```text
flock()
```

Location:

```text
storage/app/private/quraba-backup/locks/
```

Implement:

```text
GlobalOperationLock
RestoreLock
MaintenanceLock
```

Initial safety rule:

```text
one write-affecting Quraba Backup operation at a time
```

This is intentionally conservative.

---

## 1.7 Exception taxonomy

Create package-specific exceptions.

Examples:

```text
ConfigurationException
EnvironmentUnsupported
OperationBusy
ArtifactVerificationFailed
StorageUnavailable
RestoreSourceInvalid
RestoreIndeterminate
```

Persist machine-readable:

```text
failure_code
```

separately from:

```text
failure_message
```

---

## Phase 1 tests

Must cover:

```text
state transitions
invalid transitions
UUID validation
workspace isolation
path traversal
workspace cleanup
concurrent flock refusal
crashed lock release
enum persistence
model relationships
```

## Phase 1 gate

No Phase 2 until:

```text
two concurrent processes cannot own the global operation
one operation cannot delete another workspace
invalid application identity is refused
catalog state transitions are deterministic
```

---

# Phase 2 — Restic Runtime & Repository Foundation

## Goal

Produce a safe, reusable Restic subsystem before backing up a single customer file.

---

## 2.1 ResticInstaller

Implement:

```text
ResticInstaller
```

Responsibilities:

```text
detect OS
detect CPU
select supported release artifact
download pinned release
download official checksum file
verify SHA-256
decompress
chmod
run version command
verify pinned version
```

Default location:

```text
storage/app/private/quraba-backup/bin/restic
```

Must work without:

```text
sudo
apt
dnf
root
```

Shared Hosting is a primary target.

---

## 2.2 Restic binary resolver

Resolution order:

```text
explicit configured path
↓
package-managed binary
↓
optional system binary if allowed
```

The binary must still pass version verification.

---

## 2.3 ResticRedactor

Build before ResticRunner.

It must sanitize:

```text
B2 secrets
password file paths where sensitive
query strings
credentials accidentally present in repository strings
environment variables
```

No raw Restic error should reach:

```text
logs
database
Filament
exception output
```

without redaction.

---

## 2.4 ResticRunner

Only this class may execute Restic.

API examples:

```text
version()
init()
snapshots()
backup()
restore()
check()
forget()
prune()
stats()
```

Internally use:

```text
Symfony Process argument arrays
```

Never concatenate shell commands.

---

## 2.5 ResticResult

Structured result object:

```text
exitCode
stdout
stderr
duration
jsonLines()
successful()
```

Restic JSON output should be parsed structurally.

Never parse human output if JSON is available.

---

## 2.6 Secret handling

Support:

```text
RESTIC_PASSWORD_FILE
AWS_ACCESS_KEY_ID
AWS_SECRET_ACCESS_KEY
```

Never:

```text
--password SECRET
```

---

## 2.7 ResticRepository

Implement:

```text
ResticRepository
```

Responsibilities:

```text
build B2 S3 repository URI
detect repository state
check reachability
check password
initialize explicitly
list snapshots
```

Repository initialization must distinguish:

```text
missing repository
wrong credentials
wrong password
network failure
```

Do not initialize on generic failure.

---

## 2.8 Commands

Implement:

```bash
quraba:backup:install-restic
quraba:backup:restic:init
quraba:backup:restic:health
```

---

## 2.9 Initial `quraba:backup:doctor`

Add first version checking:

```text
PHP
required extensions
proc_open
MySQL/MariaDB tools
private storage
Restic binary
Restic version
outbound HTTPS
B2 credentials
B2 bucket
Restic repository
lock system
```

---

## Phase 2 integration tests

Use a real temporary Restic repository.

Test:

```text
init
snapshot listing
wrong password
wrong repository
wrong Restic version
checksum mismatch
binary not executable
secret redaction
timeout
```

Also repeat the same portable-binary execution pattern that already succeeded on Namecheap.

## Phase 2 gate

Must prove:

```text
Restic install works without root
ResticRunner is the only process entry point
secrets do not appear in argv/logs
wrong repository is not auto-initialized
health correctly distinguishes failure types
```

---

# Phase 3 — B2 Storage & Application Archive Pipeline

## Goal

Build verified database + `.env` archives independently from Restic media.

---

## 3.1 B2 configuration

Create package filesystem/storage adapter around B2 S3.

Validate:

```text
endpoint
bucket
key ID
application key
prefix
```

No dependency on a Quraba-owned account.

Every customer uses their own B2 account.

---

## 3.2 ArchiveEngine contract

Create:

```text
ArchiveEngine
```

Implementation:

```text
SpatieArchiveEngine
```

This prevents BackupManager from depending directly on Spatie internals.

---

## 3.3 Spatie integration

Spatie should create:

```text
Database
.env
internal metadata
```

only.

Exclude automatically:

```text
media
vendor
node_modules
logs
cache
package workspaces
Restic secrets
```

Archive encryption is mandatory.

Missing encryption password:

```text
hard failure
```

---

## 3.4 Database compatibility

Detect both:

```text
mysqldump
mariadb-dump
```

and record detected server flavor.

Support:

```text
MySQL
MariaDB
```

even if the Laravel connection driver is named `mysql`.

---

## 3.5 Internal archive metadata

Produce:

```text
quraba-backup.json
```

Containing:

```text
schema version
run UUID
app UUID
environment
created_at UTC
package version
Laravel version
PHP version
database flavor/version
migration fingerprint
schema fingerprint
APP_KEY fingerprint
```

No secrets.

---

## 3.6 ArchiveVerifier

Verify:

```text
file exists
non-zero size
ZIP opens
password works
database dump exists
.env exists
internal metadata exists
metadata identities match
SHA-256 calculated
```

---

## 3.7 ArchiveStore

Upload after local verification.

Remote layout:

```text
quraba-backup/{app-id}/archives/YYYY/MM/DD/{run-uuid}/application.zip
```

Verify remote object:

```text
exists
size matches
```

Store:

```text
remote locator
sha256
bytes
```

---

## Phase 3 tests

Test:

```text
real DB dump
encrypted archive
wrong archive password
missing .env
corrupted ZIP
B2 upload failure
retry of deterministic object path
remote size mismatch
secret leakage
```

## Phase 3 gate

Application Archive must be recoverable independently before continuing.

---

# Phase 4 — Media Backup with Restic

## Goal

Create safe, identifiable, verified media snapshots.

---

## 4.1 Media root configuration

In:

```text
config/restic.php
```

Example:

```php
'paths' => [
    'public' => storage_path('app/public'),
    'uploads' => public_path('uploads'),
],
```

Each entry has a stable logical name.

Validate:

```text
path exists
path is readable
path is not /
path is not application parent
path is not package workspace
path is not Restic repository
```

---

## 4.2 Symlink policy

Default:

```text
do not follow symlinks outside configured roots
```

Explicitly handle Laravel:

```text
public/storage
```

as recreatable rather than backup content.

---

## 4.3 ResticTags

Implement centralized tags.

Example:

```text
quraba-backup
app:{uuid}
env:{environment}
kind:media
run:{uuid}
```

Recovery media uses:

```text
kind:recovery_media
```

Safety media:

```text
kind:safety_media
```

---

## 4.4 MediaSnapshotService

Flow:

```text
preflight
↓
check existing run snapshot
↓
0 found → backup
1 found → verify/adopt
>1 found → ambiguity failure
↓
parse full snapshot ID
↓
query exact ID
↓
verify tags
↓
persist artifact
```

---

## 4.5 Full snapshot IDs

Reject:

```text
short IDs
latest
ambiguous selectors
```

Persist exact canonical ID only.

---

## Phase 4 tests

Real Restic tests:

```text
new snapshot
adopt existing snapshot
duplicate run snapshot ambiguity
wrong app tag
wrong environment
wrong kind
corrupted/invalid metadata
deleted file preserved in older snapshot
restoring exact old snapshot
```

## Phase 4 gate

Must prove accidental file deletion can be recovered from an older snapshot.

---

# Phase 5 — Backup Orchestration & Recovery Points

## Goal

Combine working archive and media engines into the actual product.

---

## 5.1 BackupManager

Top-level orchestration.

Profiles:

```text
database
media
recovery
```

---

## 5.2 Database profile

Creates only:

```text
Application Archive
```

---

## 5.3 Media profile

Creates only:

```text
Restic media snapshot
```

---

## 5.4 Recovery profile

Creates:

```text
Application Archive
+
Media Snapshot
```

under one:

```text
run UUID
```

and one remote manifest.

---

## 5.5 QuiescenceProvider contract

Implement:

```text
NoneQuiescenceProvider
LaravelMaintenanceProvider
```

Default Recovery Point may initially be:

```text
best_effort
```

Strict mode becomes:

```text
quiesced
```

only when the provider proves it.

No fake consistency claims.

---

## 5.6 RemoteManifest

Create versioned JSON schema.

Upload only after referenced artifacts are verified.

Path:

```text
quraba-backup/{app-id}/manifests/YYYY/MM/DD/{run-uuid}.json
```

---

## 5.7 Run finalization

Examples:

```text
archive verified
media verified
→ COMPLETED

archive verified
media failed
→ PARTIAL

archive failed
media never started
→ FAILED
```

Persist component states separately.

---

## 5.8 BackupReconciler

Implement recovery from interrupted writes.

Examples:

```text
Restic snapshot exists but catalog doesn't know ID
archive uploaded but run still says uploading
manifest missing after both artifacts succeeded
```

Command:

```bash
quraba:backup:reconcile
```

---

## 5.9 Scheduling

Package registers Laravel schedules from config.

Support separate schedules:

```text
database
media
recovery
```

and:

```text
without overlap
```

plus package lock.

Shared Hosting only needs one normal Laravel scheduler cron.

---

## 5.10 Pending manual requests

Implement scheduler processing for:

```text
pending manual backup requests
```

This will later allow Filament to request backups without executing them through a web request.

---

## Phase 5 tests

Critical scenarios:

```text
database succeeds/media fails
media succeeds/catalog save dies
manifest write fails
quiescence refuses
scheduler double-run
manual + scheduled collision
process crash and reconciliation
```

## Phase 5 gate

Only after this phase do we have a real usable backup product.

---

# Phase 6 — Health, Retention & Maintenance

## Goal

Make backups sustainable over months and years.

---

## 6.1 BackupHealthService

Health states:

```text
Healthy
Degraded
Failed
Unknown
```

Inspect:

```text
last DB backup
last media snapshot
last Recovery Point
latest quiesced Recovery Point
partial runs
failed runs
unresolved restores
repository reachability
archive accessibility
```

Command:

```bash
quraba:backup:health
```

---

## 6.2 Restic integrity check

Command:

```bash
quraba:backup:restic:check
```

Separate from cheap health.

Support:

```text
standard check
optional read-data check
```

No full data read on every backup.

---

## 6.3 RetentionPlanner

No deletion yet.

Produce a plan from:

```text
daily
weekly
monthly
yearly
latest
safety pins
```

---

## 6.4 Safety invariants

Never remove:

```text
newest valid DB archive
newest valid media snapshot
newest valid Recovery Point
unresolved pre-restore safety backup
```

---

## 6.5 RetentionExecutor

Default command:

```bash
quraba:backup:retention
```

means plan only.

Deletion:

```bash
quraba:backup:retention --execute
```

For Restic:

```text
forget exact full IDs
re-list
prove absence
mark expired
```

For archives:

```text
delete exact object
prove absence
mark expired
```

---

## 6.6 Restic prune

Separate:

```bash
quraba:backup:restic:prune
quraba:backup:restic:prune --execute
```

Default schedule:

```text
NONE
```

---

## 6.7 Maintenance audit

Every:

```text
retention
check
prune
reconcile
```

creates a `BackupMaintenanceRun`.

---

## Phase 6 tests

Especially:

```text
retention cannot reach zero backups
pinned safety backup survives
wrong app snapshots untouched
wrong environment untouched
dry-run retention changes nothing
forget failure does not mark expired
prune lock conflicts correctly
```

---

# Phase 7 — Restore Foundation & Dry Run

## Goal

Build a complete restore verification pipeline that touches no production state.

This is a mandatory stage before destructive restore is allowed to exist.

---

## 7.1 RestoreSourceResolver

Select by:

```text
exact backup run UUID
```

Freeze:

```text
archive locator
archive SHA-256
Restic full snapshot ID
app ID
environment
manifest version
```

Never:

```text
latest
```

---

## 7.2 RestoreWorkspace

Separate from backup workspace but same ownership rules.

---

## 7.3 Archive reconstruction

Dry run:

```text
download archive
↓
verify SHA-256
↓
decrypt
↓
verify metadata
↓
extract DB dump
↓
extract .env candidate
```

Live `.env` untouched.

---

## 7.4 APP_KEY verification

Compare:

```text
backup APP_KEY fingerprint
current APP_KEY fingerprint
```

Mismatch is a restore blocker by default.

---

## 7.5 Restic reconstruction

Restore exact snapshot ID into:

```text
private restore workspace
```

Not live media directory.

Verify reconstructed path belongs to workspace.

---

## 7.6 RestorePlanner

Determine:

```text
DB operation
media operations
target roots
required disk capacity
atomic rename support
source identities
validation strength
```

---

## 7.7 Scratch database validator

Optional capability.

If configured DB user can create scratch DB or a validation DB is provided:

```text
import dump
inspect schema
fingerprint schema
```

Otherwise:

```text
artifact-level validation
```

Dry-run must report:

```text
validation level
```

instead of pretending full DB import was tested.

---

## 7.8 Dry-run command

Example:

```bash
php artisan quraba:backup:restore \
    --run=UUID \
    --profile=full
```

No `--force` means:

```text
DRY RUN
```

---

## Phase 7 tests

Must prove dry run:

```text
never modifies live DB
never modifies live media
never modifies .env
detects wrong password
detects wrong APP_KEY
detects bad archive hash
detects missing snapshot
detects insufficient disk
detects cross-filesystem rename problem
```

## Phase 7 gate

No live restore code until dry-run E2E tests pass.

---

# Phase 8 — Live Restore Safety & Exact Apply

## Goal

Implement the highest-risk part of the package only after all source validation infrastructure is mature.

This phase receives the strictest review.

---

## 8.1 RestoreJournal

External durable journal:

```text
storage/app/private/quraba-backup/journal/{restore-uuid}.json
```

Atomic updates.

Records every destructive boundary.

No secrets.

---

## 8.2 RestoreLock

Only one live restore.

An unresolved restore journal blocks another restore.

---

## 8.3 Confirmation

Live restore requires:

```text
--force
--confirm=RESTORE_APPLICATION
```

Both.

---

## 8.4 Pre-change safety backup

Before mutation:

### Database restore

```text
database safety archive
```

### Media restore

```text
media safety snapshot
```

### Full restore

```text
full safety Recovery Point
```

Must be verified.

---

## 8.5 Maintenance/quiescence

For Full Restore:

```text
enter maintenance
↓
capture safety point
↓
keep maintenance
↓
apply restore
```

Do not reopen automatically.

---

## 8.6 ExactDatabaseReplacement

Before destructive operation prove:

```text
Laravel configured DB
actual SELECT DATABASE()
expected application DB
not protected/system DB
```

Then:

```text
inventory existing schema
clear existing objects
import validated dump
verify schema
```

This is replacement, not merge.

---

## 8.7 ExactDirectoryReplacement

For every media root:

```text
prepare complete stage
↓
hash/verify stage
↓
prove live path
↓
prove same filesystem
↓
live → parked
↓
stage → live
↓
verify live
↓
remove parked
```

No recursive-copy fallback after destructive boundary.

---

## 8.8 Destructive journal boundaries

Record at least:

```text
media swap starting
media swap completed
DB clear starting
DB cleared
DB import starting
DB import completed
verification starting
verification completed
```

---

## 8.9 Failure semantics

Before destructive boundary:

```text
FAILED
```

After destructive boundary when final state cannot be proven:

```text
INDETERMINATE
```

---

## 8.10 No automatic rollback

Never automatically restore the safety backup after a destructive failure.

Keep:

```text
maintenance mode ON
safety backup pinned
journal intact
```

---

## 8.11 Restore reconciliation

Command:

```bash
quraba:backup:restore-reconcile --restore=UUID
```

Use:

```text
journal
current DB
media tree
backup catalog
remote manifests
safety backup
```

to determine actual state.

---

## Phase 8 failure-injection tests

Mandatory kill points:

```text
after safety backup
after first media rename
after media replacement
after DB clear
mid DB import
after DB import
before terminal status update
```

Tests must prove package reaches a safe:

```text
FAILED
or
INDETERMINATE
```

state.

---

# Phase 9 — Disaster Recovery & Clean-Host Restore

## Goal

Prove the package works after complete server loss.

This is the real end-to-end definition of Backup.

---

## 9.1 Remote discovery

Command:

```bash
quraba:backup:discover --remote
```

Uses B2 manifests rather than local DB.

---

## 9.2 Recovery checklist

Command:

```bash
quraba:backup:recovery-checklist
```

Lists required externally preserved secrets without printing their values.

---

## 9.3 `.env` bootstrap

Command:

```bash
quraba:backup:bootstrap-env --run=UUID
```

Flow:

```text
temporary recovery B2 config
↓
download encrypted application archive
↓
verify hash
↓
decrypt
↓
extract .env candidate
↓
explicitly install with 0600 permissions
```

---

## 9.4 Clean-host database restore

Assumption:

```text
empty MySQL/MariaDB database already exists
```

No requirement for:

```text
CREATE DATABASE
```

privilege.

Suitable for cPanel.

---

## 9.5 Catalog rebuild

Command:

```bash
quraba:backup:catalog:rebuild
```

Plan first.

Then:

```bash
quraba:backup:catalog:rebuild --apply
```

Read remote manifests and rebuild missing local catalog rows.

---

## 9.6 Full disaster simulation

Automated/manual test:

```text
create application state
↓
backup to test B2-compatible storage
↓
destroy application DB
destroy local media
destroy local backup catalog
↓
fresh Laravel checkout
↓
configure recovery secrets
↓
discover backup remotely
↓
recover .env
↓
restore DB
↓
restore media
↓
prove application state
```

## Phase 9 gate

Package cannot be called production-ready until this succeeds.

---

# Phase 10 — Filament 5 Integration

## Goal

Add management UX without making Filament a core dependency.

---

## 10.1 Optional plugin

Create something like:

```text
QurabaBackupPlugin
```

Loaded only when Filament exists.

---

## 10.2 Backup Dashboard

Show:

```text
overall health
last DB backup
last media snapshot
last Recovery Point
last strict Recovery Point
B2 status
Restic status
next scheduled jobs
partial/failed operations
unresolved restore warning
```

---

## 10.3 Backup Runs

Table:

```text
date
profile
trigger
consistency
archive status
media status
overall status
duration
run UUID
```

Actions:

```text
details
manifest
request new backup
prepare restore
reconcile
```

---

## 10.4 Settings

Editable safe settings:

```text
backup enablement
schedule
retention
health thresholds
default profile
```

Read-only:

```text
media paths
B2 endpoint
bucket name where appropriate
Restic binary/version
```

Never expose secrets.

---

## 10.5 Long operations

Filament button:

```text
Run Backup
```

does not run backup synchronously.

It creates:

```text
pending backup run
```

Scheduler processes it.

No queue daemon required.

---

## 10.6 Restore interface

Flow:

```text
select exact run
↓
dry run
↓
view verification
↓
typed confirmation
↓
queue/request live restore
```

For destructive restore, I would prefer CLI as the primary authority initially.

Filament may prepare the restore, but actual live restore could remain CLI-only in the first stable release.

This significantly reduces risk.

---

# Phase 11 — Security & Production Hardening

## Goal

Treat hostile/malformed inputs and process failures as normal engineering cases.

---

## 11.1 Secret audit

Automated tests verify secrets do not appear in:

```text
argv
logs
exceptions
DB failure messages
Filament output
manifests
```

---

## 11.2 Path security

Test:

```text
../ traversal
absolute path injection
symlink escape
root path configuration
overlapping media roots
workspace escape
restore outside intended destination
```

---

## 11.3 Repository identity

Reject Restic snapshots with:

```text
wrong app ID
wrong environment
wrong kind
wrong run ID
conflicting tags
missing full ID
```

---

## 11.4 B2 destructive safety

Exact paths only.

No broad recursive delete API available to arbitrary callers.

---

## 11.5 Database identity safety

Test intentional misconfiguration such as:

```text
DB_DATABASE=mysql
DB_DATABASE=information_schema
connection silently points elsewhere
```

Restore must refuse.

---

## 11.6 Shared-hosting constraints

Test under environment resembling Namecheap:

```text
no sudo
no Supervisor
no Redis
portable Restic
Cron scheduler
PHP CLI
CloudLinux-style limits
```

---

# Phase 12 — Final Production Validation & v1 Release

## Goal

Prove the package can be trusted before installation in customer projects.

---

## Required end-to-end scenarios

### Scenario A — Normal database backup

```text
DB + .env
→ encrypted archive
→ B2
→ verified
```

### Scenario B — Media backup

```text
media
→ Restic B2
→ exact snapshot
→ delete local file
→ restore old snapshot
```

### Scenario C — Recovery Point

```text
archive + media
→ one run UUID
→ one manifest
```

### Scenario D — Partial backup

```text
archive success
Restic failure
→ PARTIAL
```

### Scenario E — Retry

```text
Restic completed
catalog write lost
→ reconcile
→ adopt existing snapshot
→ no duplicate
```

### Scenario F — Database restore

```text
state A
backup
state B + extra table
restore A
→ extra table gone
→ DB exactly A
```

### Scenario G — Media restore

```text
A = files x,y
backup
B = x,y,z
restore A
→ z gone
```

### Scenario H — Full restore

```text
DB A + files A
backup
mutate everything to B
restore A
→ exact A
```

### Scenario I — Restore failure

Inject failure after destructive boundary.

Expected:

```text
maintenance stays on
safety backup exists
restore = INDETERMINATE/FAILED as appropriate
no automatic rollback
```

### Scenario J — Full server loss

```text
only B2 survives
```

Restore onto clean server.

Must succeed.

---

# Documentation required before v1

Create:

```text
README.md
installation.md
configuration.md
shared-hosting.md
backups.md
restores.md
disaster-recovery.md
retention.md
security.md
troubleshooting.md
filament.md
upgrading.md
```

Especially provide one clear document:

```text
DISASTER-RECOVERY-RUNBOOK.md
```

An operator should be able to restore a customer site without reading the source code.

---

# Release gates

Before `1.0.0`:

```text
PHP test suite             PASS
MySQL E2E                  PASS
MariaDB E2E                PASS
Restic real-repo tests     PASS
B2 integration tests       PASS
Shared Hosting test        PASS
failure-injection tests    PASS
security tests             PASS
full restore               PASS
clean-host recovery        PASS
static analysis            PASS
code style                 PASS
documentation              PASS
```

No exception for clean-host recovery.

---

# Recommended Branch / Development Strategy

One implementation branch per phase.

Example:

```text
feature/phase-01-foundation
feature/phase-02-restic-runtime
feature/phase-03-archive-b2
feature/phase-04-media-snapshots
feature/phase-05-backup-orchestration
feature/phase-06-maintenance
feature/phase-07-restore-dry-run
feature/phase-08-live-restore
feature/phase-09-disaster-recovery
feature/phase-10-filament
feature/phase-11-hardening
```

Each phase should end with:

```text
implementation
↓
tests
↓
self-review
↓
independent review
↓
fix findings
↓
freeze phase
```

Do not continue building new features on top of known high-risk findings.

---

# Risk classification

## Lower/medium risk

```text
Phase 0
Phase 1
Phase 2
Phase 3
Phase 4
Phase 5
Phase 10
```

These can move relatively quickly with strong automated tests.

## High risk

```text
Phase 6 retention
Phase 7 restore source validation
Phase 8 destructive restore
Phase 9 disaster recovery
Phase 11 security hardening
```

These require stricter review because mistakes can delete or overwrite customer data.

---

# Recommended implementation batching

Do not split every class into its own AI prompt.

Use one coherent prompt per phase or major sub-phase.

For example Phase 2 should be implemented together:

```text
ResticInstaller
ResticBinary
ResticRunner
ResticResult
ResticRedactor
ResticRepository
Restic health/init commands
tests
```

because these classes share one contract and should be reviewed as one subsystem.

Likewise Phase 8 should be implemented as a coherent restore-safety subsystem rather than dozens of independent prompts.

---

# Master dependency graph

```text
PHASE 0
Repository / package shell
        │
        ▼
PHASE 1
Domain + Catalog + Workspace + Locks
        │
        ▼
PHASE 2
Restic Runtime
        │
        ├───────────────┐
        ▼               ▼
PHASE 3             PHASE 4
Archive + B2        Media / Restic
        │               │
        └──────┬────────┘
               ▼
           PHASE 5
     Backup Orchestration
               │
               ▼
           PHASE 6
       Health + Retention
               │
               ▼
           PHASE 7
        Restore Dry Run
               │
               ▼
           PHASE 8
         Live Restore
               │
               ▼
           PHASE 9
     Disaster Recovery
               │
        ┌──────┴───────┐
        ▼              ▼
    PHASE 10       PHASE 11
    Filament       Hardening
        └──────┬───────┘
               ▼
           PHASE 12
          v1 Release
```

---

# What Phase 1 must NOT do

To prevent scope creep, the first implementation phase must NOT implement:

```text
Restic
B2
Spatie
backup commands
restore
retention
Filament
scheduler
```

Phase 1 is only:

```text
package structure
configuration
domain enums
models
migrations
application identity
workspace
locks
exceptions
foundation tests
```

Once these foundations are frozen, every later subsystem has stable primitives to build on.

---

# What Phase 2 must NOT do

Phase 2 must NOT back up customer media yet.

It only proves:

```text
we can safely install,
configure,
execute,
query,
and initialize Restic.
```

This separation is intentional.

---

# What Phase 7 must NOT do

Phase 7 cannot contain any method capable of modifying:

```text
live DB
live media
live .env
```

It is source resolution and dry-run verification only.

This creates a structural safety boundary before Phase 8.

---

# Final milestone definitions

## M1 — Backup Foundation

After Phase 5:

```text
Database Backup       READY
Media Backup          READY
Recovery Point        READY
B2                     READY
Scheduler              READY
Reconciliation         READY
```

At this point the package can already be used for automated backup.

---

## M2 — Operational Backup System

After Phase 6:

```text
Health                 READY
Retention              READY
Restic Check           READY
Maintenance Audit      READY
```

The package can safely operate long term.

---

## M3 — Safe Restore

After Phase 8:

```text
Dry Run                READY
Safety Backup          READY
Exact DB Restore       READY
Exact Media Restore    READY
Full Restore           READY
Restore Reconciliation READY
```

---

## M4 — Disaster Recovery

After Phase 9:

```text
Remote discovery       READY
Clean-host restore     READY
.env recovery          READY
Catalog rebuild        READY
```

Now the backup system can survive total server loss.

---

## M5 — Productized Package

After Phases 10–12:

```text
Filament               READY
Security hardening     READY
Shared Hosting         VERIFIED
Docs                    READY
v1.0.0                  READY
```

---

# Recommended starting point

The first development cycle should implement **Phase 0 + Phase 1 together** as one coherent foundation batch.

They are closely related and low enough risk to combine.

The first coding milestone therefore becomes:

```text
Composer package skeleton
+
configuration contract
+
catalog/migrations
+
models/enums
+
application identity
+
operation workspaces
+
file locking
+
exception structure
+
tests
```

Then freeze that foundation before touching Restic.

---

# Final implementation strategy

The project should move in this order:

```text
1. Build primitives.
2. Prove Restic independently.
3. Prove encrypted archive independently.
4. Prove media snapshots independently.
5. Orchestrate them.
6. Make operations maintainable.
7. Build a restore that cannot mutate anything.
8. Only then build destructive restore.
9. Prove complete server-loss recovery.
10. Add Filament UX.
11. Attack it with failure/security tests.
12. Release.
```

The most important rule throughout development is:

> We do not add convenience by weakening a proven safety property.

If an environment cannot safely support an operation, the package should refuse it clearly rather than silently downgrade the guarantee.
