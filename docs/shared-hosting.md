# Shared hosting (cPanel)

Shared hosting is a first-class target. The package needs **no** root, sudo, Docker, systemd, Supervisor,
Redis, queue worker or daemon.

## Prerequisites

- PHP 8.5 CLI with `proc_open` enabled (check with `php artisan quraba:backup:doctor`)
- `ext-bz2` **or** a `bzip2` binary (only for `quraba:backup:install-restic`)
- Writable `storage/` (the package keeps everything under `storage/app/private/quraba-backup`, mode 0700)
- A local filesystem for locks (`flock` must work; the doctor proves it across two processes)
- Outbound HTTPS to the B2 endpoint (and to GitHub once, for the Restic installer)
- MySQL/MariaDB client tools (`mariadb-dump`/`mysqldump`, `mariadb`/`mysql`) — on cPanel usually in
  `/usr/bin`; custom paths via `QURABA_BACKUP_DB_DUMP_BINARY` / `QURABA_BACKUP_DB_CLIENT_BINARY`
- One cron entry: `* * * * * cd /home/account/app && php artisan schedule:run` (every 5 minutes is fine;
  package schedule times are validated to 5-minute boundaries)
- PHP `zip` extension with AES-256 support (the doctor checks it)
- Enough free disk in `storage/app/private/quraba-backup/work` for the database dump plus the archive
  (the doctor warns below `QURABA_BACKUP_WORKSPACE_MIN_FREE_MB`, default 1024)

## Notes

- Run package commands from SSH/cron (PHP CLI), not from web requests.
- The Restic binary is portable (static Go build); it is installed into the application's private storage
  and executed from there.
- `QURABA_BACKUP_RESTIC_ALLOW_SYSTEM_BINARY=false` by default: a random `restic` in `PATH` is never trusted.
- Package locks are OS file locks. A process killed by the host (CloudLinux limits, OOM, SSH disconnect)
  releases its lock automatically; nothing needs manual unlocking.
- Restic's own repository locks are never removed automatically. `quraba:backup:restic:health` reports them.
- On POSIX hosts, scheduled backups use Laravel's `runInBackground()` by default, so `schedule:run` can
  continue other tasks. `QURABA_BACKUP_SCHEDULE_BACKGROUND=false` keeps foreground behavior; `true`
  requires supported background execution. Windows development falls back in `auto` mode. Manual CLI
  backups always run in the foreground. No queue worker, Supervisor or Redis is needed.
- Scheduled backups pause during unrelated Laravel maintenance mode by default. Set
  `QURABA_BACKUP_SCHEDULE_IN_MAINTENANCE=true` only when a deployment policy makes that safe.
- A backup killed by the host (CPU/time limits) is
  resolved later by `php artisan quraba:backup:reconcile`, without duplicating anything already uploaded.
- Database dumps stream to disk (`--quick --result-file`) and archives are hashed and uploaded as streams, so
  PHP memory does not grow with the backup size.
- If `php_uname()` is disabled, the Restic installer falls back to `/proc/sys/kernel/arch`.
