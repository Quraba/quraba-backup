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
    |     php artisan backup:identity --generate
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
    | backup:doctor warns until it is acknowledged.
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
    | Application archive (database dump + .env). Reserved: archive creation
    | arrives in a later phase. Encryption is mandatory; a missing password
    | will refuse the backup rather than produce an unencrypted .env copy.
    */
    'archive' => [
        'password' => env('QURABA_BACKUP_ARCHIVE_PASSWORD'),
        'include_env' => true,
        'encryption' => 'aes256',
        'extra_files' => [],
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
    ],

    'logging' => [
        // null: the application's default log channel.
        'channel' => env('QURABA_BACKUP_LOG_CHANNEL'),
    ],

    /* Reserved: scheduling arrives with backup orchestration. */
    'schedule' => [
        'database' => null,
        'media' => null,
        'recovery' => null,
        'health' => null,
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
