# Security model (foundation)

- **One process boundary for Restic.** Only `ResticRunner` executes Restic, only through typed operations
  (version, init, cat config, snapshots, list locks, stats, backup, restore, check, forget, prune). There is no
  arbitrary-command API.
- **No shell.** All processes are Symfony `Process` argument arrays. (On Linux, PHP executes them directly
  without a shell.)
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
