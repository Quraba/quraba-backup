# Optional operational notifications

The package dispatches `Quraba\Backup\Notifications\OperationalNotice` for failed, partial and
indeterminate backups, indeterminate live restores, repository identity mismatches, failed integrity checks, retention that stops
indeterminate, and meaningful health changes. The event contains only a condition, exact run or restore
identity, and safe failure codes. Laravel listeners may use it without enabling delivery.

Delivery is off by default. Configure `quraba-backup.notifications.enabled=true` in a published config,
then set `callback` to a callable receiving `OperationalNotice`. The callable can post to the operator's
existing alerting system. Alternatively, set `notifiable` to a callable returning a Laravel notifiable and
`channels` to `['mail']` or `['database']`. The host must configure those channels. No mail setup is required
for backups to work. The package does not send success alerts by default.

`quraba:backup:health` stores its last observed state in a private local file when delivery is enabled.
Repeated checks in the same state do not alert again. A transition into degraded, failed or unknown alerts;
set `notify_recovery=true` to also alert on a return to healthy. Losing the local state may cause one
extra alert after a rebuild. Listener and transport exceptions are sanitized and logged; they never alter
the backup, restore or maintenance outcome.
