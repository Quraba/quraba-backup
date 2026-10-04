<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Quraba Backup
|--------------------------------------------------------------------------
|
| Package configuration. Secrets are only ever read from the environment;
| they are never stored in the database and never written anywhere by the
| package. Sections marked "reserved" document the contract for later
| phases of the master plan and are not read by this version.
|
*/

return [

    /*
    | Master switch for automated backup activity (scheduler, future
    | backup runs). Diagnostic commands keep working when disabled.
    */
    'enabled' => (bool) env('QURABA_BACKUP_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Application identity
    |--------------------------------------------------------------------------
    |
    | `app_id` is a stable UUID generated once per application and kept for
    | its whole lifetime. It is never derived from APP_NAME, APP_URL, the
    | hostname, the domain or the directory name. Generate one with:
    |
    |     php artisan quraba:backup:identity --generate
    |
    | `environment` separates production from staging backups. When null the
    | Laravel application environment (APP_ENV) is used.
    |
    */
    'app_id' => env('QURABA_BACKUP_APP_ID'),

    'environment' => env('QURABA_BACKUP_ENVIRONMENT'),

    /*
    | Set to true once the recovery secrets (B2 key ID + application key,
    | bucket/endpoint, archive password, Restic password, QURABA_BACKUP_APP_ID)
    | are stored outside this server. The package cannot verify this itself;
    | quraba:backup:doctor warns until it is acknowledged.
    */
    'recovery_secrets_acknowledged' => (bool) env('QURABA_BACKUP_RECOVERY_SECRETS_ACKNOWLEDGED', false),

    /*
    |--------------------------------------------------------------------------
    | Private package storage
    |--------------------------------------------------------------------------
    |
    | Everything the package writes locally lives below `root`, which must
    | never be web-accessible. Operation workspaces (`op-{ULID}`) are created
    | below `workspaces`; process-safe flock files live in `locks`.
    |
    */
    'paths' => [
        'root' => env('QURABA_BACKUP_STORAGE_ROOT', storage_path('app/private/quraba-backup')),
        'workspaces' => env('QURABA_BACKUP_WORKSPACE_PATH'),   // null: {root}/work
        'locks' => env('QURABA_BACKUP_LOCK_PATH'),             // null: {root}/locks
    ],

    'workspace' => [
        // Workspaces older than this, whose owner no longer holds them, are
        // reported as abandoned. Cleanup never deletes an active workspace.
        'abandoned_after_hours' => (int) env('QURABA_BACKUP_WORKSPACE_ABANDONED_AFTER_HOURS', 24),
        // quraba:backup:doctor warns when less free disk than this remains for workspaces.
        'min_free_mb' => (int) env('QURABA_BACKUP_WORKSPACE_MIN_FREE_MB', 1024),
    ],

    'locking' => [
        // Only OS-level flock() is supported in v1: no Redis, no database locks.
        'driver' => 'file',
    ],

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    |
    | `connection`: the application database to back up (null: default).
    | `catalog_connection`: where the package catalog tables live (null: default).
    | Dump/client binaries are discovered automatically (mariadb-dump, then
    | mysqldump; mariadb, then mysql) unless explicit paths are configured.
    |
    */
    'database' => [
        'connection' => env('QURABA_BACKUP_DB_CONNECTION'),
        'catalog_connection' => env('QURABA_BACKUP_CATALOG_CONNECTION'),
        'dump_binary' => env('QURABA_BACKUP_DB_DUMP_BINARY'),
        'client_binary' => env('QURABA_BACKUP_DB_CLIENT_BINARY'),
        // Include stored procedures/functions in the dump (triggers are always included).
        'dump_routines' => (bool) env('QURABA_BACKUP_DB_DUMP_ROUTINES', true),
        // auto includes events when SHOW GRANTS proves EVENT/ALL on this DB;
        // required fails the backup unless that privilege is proven;
        // assume_none is an explicit shared-hosting opt-out. In auto mode a
        // backup without proven EVENT is marked incomplete, never silently exact.
        'event_policy' => env('QURABA_BACKUP_DB_EVENT_POLICY', 'auto'),
        // Legacy true remains a request to require event protection.
        'dump_events' => (bool) env('QURABA_BACKUP_DB_DUMP_EVENTS', false),
        'tool_search_paths' => [
            '/usr/bin',
            '/usr/local/bin',
            '/usr/local/mysql/bin',
            '/opt/cpanel/ea-mariadb/bin',
        ],
    ],

    // Optional operational alerts. A host may set callback to a callable
    // receiving OperationalNotice, or notifiable to a callable returning a
    // Laravel notifiable. No mail/channel is assumed by default.
    'notifications' => [
        'enabled' => false,
        'callback' => null,
        'notifiable' => null,
        'channels' => [],
        'notify_recovery' => false,
    ],

    // Register QurabaBackupPlugin explicitly in an authenticated Filament 5
    // panel. Every ability defaults to denied until a host Gate/callback grants it.
    'filament' => [
        'pending_enabled' => false,
        'live_restore_enabled' => false,
        'authorization' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Backblaze B2 (S3-compatible API)
    |--------------------------------------------------------------------------
    |
    | The B2 account and its credentials belong to the customer. Use a
    | bucket-scoped application key, never the account master key.
    | Remote layout:  {bucket}/{prefix}/{app_id}/{archives|manifests|restic}/...
    |
    */
    'storage' => [
        'b2' => [
            'endpoint' => env('QURABA_BACKUP_B2_ENDPOINT'),        // e.g. https://s3.us-west-004.backblazeb2.com
            'region' => env('QURABA_BACKUP_B2_REGION'),            // null: derived from the endpoint
            'bucket' => env('QURABA_BACKUP_B2_BUCKET'),
            'key_id' => env('QURABA_BACKUP_B2_KEY_ID'),
            'application_key' => env('QURABA_BACKUP_B2_APPLICATION_KEY'),
            'prefix' => env('QURABA_BACKUP_PREFIX', 'quraba-backup'),
        ],
    ],

    /*
    | Application archive: database dump + .env + quraba-backup.json, always
    | AES-256 encrypted. A missing or blank password refuses the backup before
    | anything is created; there is no unencrypted fallback. Keep the password
    | outside this server — nobody can open the archives without it.
    */
    'archive' => [
        'password' => env('QURABA_BACKUP_ARCHIVE_PASSWORD'),
        'include_env' => (bool) env('QURABA_BACKUP_ARCHIVE_INCLUDE_ENV', true),
        'release_id' => env('QURABA_BACKUP_RELEASE_ID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Recovery Point consistency
    |--------------------------------------------------------------------------
    |
    | `provider`: none | laravel_maintenance. Without proven quiescence a
    | Recovery Point is `best_effort`. Laravel maintenance mode only blocks
    | HTTP; the capture is recorded as `quiesced` only when you also declare
    | that no queue workers, scheduled tasks or other CLI writers change data
    | (`no_background_writers`).
    |
    | `require_quiesced`: refuse Recovery Points that cannot be quiesced,
    | unless `allow_downgrade` explicitly permits recording them as best_effort.
    |
    */
    'consistency' => [
        'provider' => env('QURABA_BACKUP_QUIESCENCE_PROVIDER', 'none'),
        'no_background_writers' => (bool) env('QURABA_BACKUP_NO_BACKGROUND_WRITERS', false),
        'require_quiesced' => (bool) env('QURABA_BACKUP_REQUIRE_QUIESCED', false),
        'allow_downgrade' => (bool) env('QURABA_BACKUP_ALLOW_CONSISTENCY_DOWNGRADE', false),
        'maintenance_retry_after' => 60,
    ],

    /*
    | Operational timeouts in seconds. Zero or negative values are rejected;
    | nothing is ever interpreted as "unlimited".
    */
    'timeouts' => [
        'http_connect' => (int) env('QURABA_BACKUP_HTTP_CONNECT_TIMEOUT', 10),
        'http_probe' => (int) env('QURABA_BACKUP_HTTP_PROBE_TIMEOUT', 20),
        'download' => (int) env('QURABA_BACKUP_DOWNLOAD_TIMEOUT', 600),
        'tool_probe' => (int) env('QURABA_BACKUP_TOOL_PROBE_TIMEOUT', 20),
        'database_dump' => (int) env('QURABA_BACKUP_DB_DUMP_TIMEOUT', 3600),
        // Importing a dump into the live database during a live restore.
        'database_import' => (int) env('QURABA_BACKUP_DB_IMPORT_TIMEOUT', 7200),
    ],

    'logging' => [
        // null: the application's default log channel.
        'channel' => env('QURABA_BACKUP_LOG_CHANNEL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduling
    |--------------------------------------------------------------------------
    |
    | Registered with Laravel's scheduler; the host needs ONE cron entry:
    |
    |     * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
    |
    | (running it every 5 minutes is fine on shared hosting). Times are HH:MM on a
    | 5-minute boundary. frequency: daily | weekly (day 0-6, 0 = Sunday) |
    | monthly (day 1-28). Disable a profile with enabled=false. Overlapping
    | runs are refused by the package's flock operation lock.
    |
    */
    'schedule' => [
        'enabled' => (bool) env('QURABA_BACKUP_SCHEDULE_ENABLED', true),
        'background' => env('QURABA_BACKUP_SCHEDULE_BACKGROUND', 'auto'),
        'even_in_maintenance_mode' => (bool) env('QURABA_BACKUP_SCHEDULE_IN_MAINTENANCE', false),
        'timezone' => env('QURABA_BACKUP_SCHEDULE_TIMEZONE'),    // null: app timezone
        'database' => [
            'enabled' => (bool) env('QURABA_BACKUP_SCHEDULE_DATABASE', true),
            'frequency' => 'daily',
            'time' => env('QURABA_BACKUP_SCHEDULE_DATABASE_TIME', '02:00'),
        ],
        'media' => [
            'enabled' => (bool) env('QURABA_BACKUP_SCHEDULE_MEDIA', true),
            'frequency' => 'daily',
            'time' => env('QURABA_BACKUP_SCHEDULE_MEDIA_TIME', '02:30'),
        ],
        'recovery' => [
            'enabled' => (bool) env('QURABA_BACKUP_SCHEDULE_RECOVERY', true),
            'frequency' => 'weekly',
            'day' => 0,
            'time' => env('QURABA_BACKUP_SCHEDULE_RECOVERY_TIME', '03:30'),
        ],
    ],

    /* Retention plans are read-only unless --execute is supplied. */
    'retention' => [
        'execute_scheduled' => false,
        'database' => ['keep_latest' => 7, 'keep_daily' => 14, 'keep_weekly' => 8, 'keep_monthly' => 12, 'keep_yearly' => 2],
        'media' => ['keep_latest' => 7, 'keep_daily' => 14, 'keep_weekly' => 8, 'keep_monthly' => 12, 'keep_yearly' => 2],
        'recovery' => ['keep_latest' => 4, 'keep_weekly' => 8, 'keep_monthly' => 12, 'keep_yearly' => 2],
        // How long the pre-change safety backup of a SETTLED live restore is
        // kept. While a restore is unresolved (or failed and not yet
        // acknowledged) its safety backup is kept indefinitely.
        'safety_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Restore
    |--------------------------------------------------------------------------
    |
    | `quraba:backup:restore` is a dry run unless BOTH --force and
    | --confirm=<confirmation_phrase> are given. A live restore additionally
    | requires a quiescence provider that PROVES writers are stopped
    | (consistency.provider=laravel_maintenance with no_background_writers),
    | takes a verified pre-change safety backup first, and leaves the
    | application in maintenance mode afterwards (`auto_up` false): bring it
    | up yourself after verifying it.
    |
    | `db_validation_level`: artifact | schema | scratch_import (the last one
    | imports the dump into the dedicated `scratch_connection` first).
    |
    */
    'restore' => [
        'require_atomic_media_swap' => true,
        'safety_margin_percent' => 20,
        'db_validation_level' => env('QURABA_BACKUP_RESTORE_DB_VALIDATION', 'artifact'),
        'require_complete_database' => (bool) env('QURABA_BACKUP_RESTORE_REQUIRE_COMPLETE_DATABASE', false),
        'scratch_connection' => env('QURABA_BACKUP_SCRATCH_CONNECTION'),
        'auto_up' => (bool) env('QURABA_BACKUP_RESTORE_AUTO_UP', false),
        // A dump names the account that defined each view, trigger and
        // routine. Restoring as ANOTHER account (typically on a new host)
        // is refused before anything is changed, unless this is enabled:
        // those objects are then created as the restoring account.
        'rewrite_definers' => (bool) env('QURABA_BACKUP_RESTORE_REWRITE_DEFINERS', false),
        'confirmation_phrase' => 'RESTORE_APPLICATION',
    ],

    /* Reserved: recovery health thresholds (hours since last verified artifact). */
    'health' => [
        'partial_window_hours' => 170,
        'max_consecutive_failures' => 3,
        'retention_max_age_hours' => 192,
        'check_max_age_days' => 35,
        'restore_diagnostic_days' => 7,
        'max_age_hours' => [
            'database' => 26,
            'media' => 26,
            'recovery' => 170,
        ],
    ],

];
