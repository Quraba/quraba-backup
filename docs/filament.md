# Optional Filament 5 panel

Install `filament/filament:^5.0` in the host application if it is not already installed. The backup core
does not require Filament. Register the plugin on one authenticated Filament panel:

```php
use Quraba\Backup\Filament\QurabaBackupPlugin;

return $panel->plugin(QurabaBackupPlugin::make());
```

The host owns authorization. Define Laravel Gates named `quraba-backup.view-dashboard`,
`quraba-backup.view-details`, `quraba-backup.view-recovery`, `quraba-backup.run-backup`,
`quraba-backup.dry-restore`, and `quraba-backup.plan-retention`. Each receives the panel's authenticated
user. Alternatively, set a callable under `quraba-backup.filament.authorization.<ability>`; it receives
that user and must return exactly `true`. All abilities deny by default. Authorization is checked on page
loads and again for each action. No user model, role package or role name is assumed.

To allow panel backup requests, set `filament.pending_enabled` to `true` in the published package config.
The page writes a pending catalog row; Laravel's scheduler runs `quraba:backup:pending` outside the web
request, one request at a time under the normal operation lock. The host needs its ordinary
`schedule:run` cron entry. No queue worker is needed. Disable this option to remove the request buttons
and stop scheduled pending processing; existing pending rows stay visible for operator review.

The dashboard samples repository health, recent warnings and configured schedules. The runs page offers
pagination and profile/status/trigger/date filters, plus safe artifact details. The operations page can
run an exact restore **dry run** and a read-only retention plan, and shows recent maintenance history.
These operations may take time; use the CLI on hosts with short web request timeouts. Live restore,
retention execution and Restic prune remain CLI-only. Secret values are never edited or displayed; the
panel reports only whether they are configured.
