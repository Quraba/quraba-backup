# Shared hosting (cPanel)

Shared hosting is a first-class target. The package needs **no** root, sudo, Docker, systemd, Supervisor,
Redis, queue worker or daemon.

## Prerequisites

- PHP 8.5 CLI with `proc_open` enabled (check with `php artisan backup:doctor`)
- `ext-bz2` **or** a `bzip2` binary (only for `backup:install-restic`)
- Writable `storage/` (the package keeps everything under `storage/app/private/quraba-backup`, mode 0700)
- A local filesystem for locks (`flock` must work; the doctor proves it across two processes)
- Outbound HTTPS to the B2 endpoint (and to GitHub once, for the Restic installer)
- MySQL/MariaDB client tools (`mariadb-dump`/`mysqldump`, `mariadb`/`mysql`) — on cPanel usually in
  `/usr/bin`; custom paths via `QURABA_BACKUP_DB_DUMP_BINARY` / `QURABA_BACKUP_DB_CLIENT_BINARY`
- Later phases: one cron entry `* * * * * php /home/account/app/artisan schedule:run` (not needed yet)

## Notes

- Run package commands from SSH/cron (PHP CLI), not from web requests.
- The Restic binary is portable (static Go build); it is installed into the application's private storage
  and executed from there.
- `QURABA_BACKUP_RESTIC_ALLOW_SYSTEM_BINARY=false` by default: a random `restic` in `PATH` is never trusted.
- Package locks are OS file locks. A process killed by the host (CloudLinux limits, OOM, SSH disconnect)
  releases its lock automatically; nothing needs manual unlocking.
- Restic's own repository locks are never removed automatically. `backup:restic:health` reports them.
