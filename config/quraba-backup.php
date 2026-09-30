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
        'tool_search_paths' => [
            '/usr/bin',
            '/usr/local/bin',
            '/usr/local/mysql/bin',
            '/opt/cpanel/ea-mariadb/bin',
        ],
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

    /* Reserved: retention is plan-only by default when it arrives. */
    'retention' => [
        'execute_scheduled' => false,
        'database' => ['keep_latest' => 7, 'keep_daily' => 14, 'keep_weekly' => 8, 'keep_monthly' => 12, 'keep_yearly' => 2],
        'media' => ['keep_latest' => 7, 'keep_daily' => 14, 'keep_weekly' => 8, 'keep_monthly' => 12, 'keep_yearly' => 2],
        'recovery' => ['keep_latest' => 4, 'keep_weekly' => 8, 'keep_monthly' => 12, 'keep_yearly' => 2],
        'safety_days' => 30,
    ],

    /* Reserved: restore arrives in later phases; these are its fixed safety defaults. */
    'restore' => [
        'require_atomic_media_swap' => true,
        'auto_up' => false,
        'confirmation_phrase' => 'RESTORE_APPLICATION',
    ],

    /* Reserved: recovery health thresholds (hours since last verified artifact). */
    'health' => [
        'max_age_hours' => [
            'database' => 26,
            'media' => 26,
            'recovery' => 170,
        ],
    ],

];
