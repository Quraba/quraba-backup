# Security model (foundation)

- **One process boundary for Restic.** Only `ResticRunner` executes Restic, only through typed operations
  (version, init, cat config, snapshots, list locks, stats, backup, restore, check, forget, prune). There is no
  arbitrary-command API.
- **No assembled shell commands.** All processes use Symfony `Process` argument arrays. On Linux, PHP
  executes them directly without a shell; Symfony handles Windows process creation and argument escaping.
- **Minimal child environment.** Everything inherited from the parent — including every `.env` value Laravel
  loaded — is stripped; only `PATH`, `HOME`, locale/timezone variables and the explicitly injected
  `RESTIC_REPOSITORY`, `RESTIC_PASSWORD_FILE`, `AWS_*`, `RESTIC_CACHE_DIR`, `TMPDIR` reach Restic.
- **Secrets never in argv, logs, output or the catalog.** Process output is redacted by value (all
  package-known secrets and their URL/JSON encodings) and by pattern (credentialed URLs, signed query strings,
  `*_PASSWORD=`/`*_SECRET=` assignments, JSON credential members) before it is returned, logged, thrown or
  persisted. Failure messages are bounded and stripped of control characters.
- **Pinned, verified Restic.** Version pinned in code; the installer requires the package-pinned SHA-256 and
  the official manifest to agree before anything is decompressed or executed, verifies `restic version`,
  and installs by atomic rename. Every binary is version-checked before use; `PATH` is never trusted alone.
- **No accidental repository creation.** Only Restic's dedicated exit code 10 means "no repository";
  network errors, typos, bad credentials, wrong passwords and locks never lead to initialization, which is
  always an explicit, confirmed, locked operation followed by a read-back.
- **Workspaces.** `op-{ULID}` directories with generated names only, real-path containment checks, symlinks
  removed as links and never followed, deletion limited to the owner's own tree, ownership proven by `flock`.
- **Locking.** OS `flock` locks fail closed and are released by the kernel if the process dies.
- **State machines.** Catalog statuses change only through declared transitions, written with a
  compare-and-set so concurrent writers cannot both win; indeterminate states are resolved only by
  reconciliation with evidence.
- **UTC everywhere.** Catalog instants are stored as UTC `DATETIME` values regardless of `app.timezone`.

## Backups (this release)

- **Encryption is mandatory.** Archives (which contain `.env`) are AES-256; a blank password refuses the
  backup before any data is produced, AES-256 support is checked first, and the verifier rejects any entry
  that is not AES-256 encrypted — there is no silent plaintext fallback.
- **Plaintext lifetime.** The SQL dump and metadata exist only inside the run's private operation workspace
  (0700 on Linux; inherited NTFS ACLs on Windows) and
  are deleted as soon as the encrypted archive is written; `.env` is read in place and never copied. The
  workspace is removed in a `finally` block.
- **Spatie isolation.** Only `SpatieArchiveEngine` (and the dumper subclass) touch Spatie. Spatie's zip task
  gets a private config instance holding the run's password, bound only while the archive is built and
  removed in `finally`; the host's own `backup` configuration is never modified. Spatie's db-dumper executor
  (a shell string with an unlimited default timeout) is not used: dumps run as argument arrays with a
  bounded timeout, credentials only in a temporary private option file (0600 on Linux;
  operator-restricted NTFS ACLs on Windows).
- **Remote objects.** Archive and manifest paths are deterministic per run; nothing is overwritten. Existing
  objects are adopted only after size and full SHA-256 (archives) or canonical content (manifests) match;
  S3 objects are only visible once complete, so partial uploads can never be adopted. The Restic prefix is
  refused by the object storage itself. B2 credentials are never registered as a Laravel disk, and provider
  errors are sanitized and never chained.
- **Snapshots.** Identity tags are generated centrally; identity filters use Restic's AND form (one
  comma-joined `--tag`); only full snapshot IDs are used; a retry adopts the run's single snapshot, and more
  than one is refused rather than resolved by "newest".
- **Repository identity.** A different repository at the configured location is refused; it is never
  adopted, and initialization is refused while the application is bound to a repository.
- **Truthful state.** The manifest is written before the catalog becomes `completed`; unprovable outcomes are
  `indeterminate` and resolved only from physical evidence; `quiesced` is never claimed because of
  maintenance mode alone.

## Retention, restore and disaster recovery

- **Exact deletion only.** Retention deletes one exact archive object and forgets exact full snapshot IDs,
  proves absence, records it remotely per component, and only then marks the artifact expired.
- **Two gates for a live restore.** `--force` and the exact configured phrase; the live services are only
  reachable through an authorization object that cannot be constructed otherwise. A dry run depends on none
  of the live services.
- **Journal first.** The restore journal lives outside the database, is private (0600 in a 0700 directory
  on Linux; operator-restricted NTFS ACLs on Windows), written with temporary file, file fsync, rename and
  read-back (plus directory fsync on Linux), never followed through a symbolic link, and
  forward-only: sequence numbers, phases, component states and frozen identities are validated on every
  write. Each destructive step is recorded before it happens; a journal that cannot be written stops the
  restore before that step.
- **Proven quiescence, verified safety backup.** No live restore at `best_effort`; nothing is changed
  before a pre-change safety backup was physically verified (or the declared clean host was proven empty).
- **Database target.** The configured production connection on its primary, the database the server
  reports, not a system schema, not the scratch database, unchanged since preflight — proven again before
  every mutation. Drops name inventoried objects, qualified with the proven database; `DROP DATABASE` is
  never issued. Credentials reach the client only through a proven-private option file, never argv or the
  environment; the dump is streamed to stdin. Client errors are reduced to their position because they can
  quote row data.
- **Media.** Staged privately on the destination's filesystem, never in the public web directory;
  replaced by two renames with no copy or overlay fallback; links leaving a root are refused; live and
  staged directories are identified by device and inode where the host reports useful values, plus a real
  rename probe and an exact tree fingerprint; parked trees are only removed on request, after a
  completed restore, and only at the exact journaled path.
- **No automatic rollback.** After the destructive boundary a failure is `indeterminate`: nothing is
  re-imported or renamed back, the application stays in maintenance mode, and reconciliation never mutates
  application data.
- **Stale state is not trusted.** The source is resolved again before the boundary and after the restore;
  the dump is re-hashed before it is imported; a restored catalog's own rows (the source run frozen mid-run,
  older restore rows) never override the journal or the immutable manifest.
- **`.env`.** A restore never extracts or writes `.env`. Only `quraba:backup:bootstrap-env` does: from the
  remote manifest, after SHA-256 and full archive verification, into a file proven private before a byte is
  written, never over an existing file without `--overwrite --confirm=OVERWRITE_ENV`, never inside the
  public directory, never displayed.
- **Catalog rebuild** adopts only what was observed physically and refuses a repository whose identity does
  not match.
- **Optional Filament panel.** All pages and actions require host-defined authorization. Backup buttons
  write pending catalog requests for the scheduler; web requests cannot invoke the destructive live restore,
  retention execution or Restic prune. The UI only reports secret presence, never secret values.
