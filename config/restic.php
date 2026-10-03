<?php

declare(strict_types=1);

use Quraba\Backup\Restic\ResticRelease;

/*
|--------------------------------------------------------------------------
| Restic
|--------------------------------------------------------------------------
|
| Restic is executed exclusively by the package's ResticRunner, with argument
| arrays (never a shell), a minimal controlled environment, the repository
| password supplied through RESTIC_PASSWORD_FILE and B2 credentials supplied
| through the environment of the child process only.
|
*/

return [

    'enabled' => (bool) env('QURABA_BACKUP_RESTIC_ENABLED', true),

    /*
    | The Restic version is pinned by the package. This value must equal
    | ResticRelease::VERSION; a different value is refused, so a published
    | copy of this file cannot silently drift from the tested release.
    */
    'version' => ResticRelease::VERSION,

    /*
    | Binary resolution order:
    |   1. `binary` when explicitly configured (must pass verification; no fallback),
    |   2. the package-managed binary installed by `php artisan quraba:backup:install-restic`,
    |   3. a system `restic` from PATH, only when `allow_system_binary` is true.
    | Every candidate must be executable and report exactly the pinned version.
    */
    'binary' => env('QURABA_BACKUP_RESTIC_BINARY'),

    'managed_binary' => env('QURABA_BACKUP_RESTIC_MANAGED_BINARY', storage_path('app/private/quraba-backup/bin/'.(PHP_OS_FAMILY === 'Windows' ? 'restic.exe' : 'restic'))),

    'allow_system_binary' => (bool) env('QURABA_BACKUP_RESTIC_ALLOW_SYSTEM_BINARY', false),

    /*
    |--------------------------------------------------------------------------
    | Repository
    |--------------------------------------------------------------------------
    |
    | By default the repository is derived from the B2 settings in
    | config/quraba-backup.php:
    |
    |     s3:{endpoint}/{bucket}/{prefix}/{app_id}/{repository.prefix}
    |
    | `url` may override this with an explicit `s3:https://...` location (or an
    | absolute local path, intended for tests only). Repository locations must
    | never contain credentials.
    |
    | `key_id`/`application_key` default to the shared B2 credentials.
    |
    */
    'repository' => [
        'url' => env('QURABA_BACKUP_RESTIC_REPOSITORY'),
        'prefix' => env('QURABA_BACKUP_RESTIC_PREFIX', 'restic'),
        'format_version' => ResticRelease::REPOSITORY_VERSION,
        'key_id' => env('QURABA_BACKUP_RESTIC_B2_KEY_ID'),
        'application_key' => env('QURABA_BACKUP_RESTIC_B2_APPLICATION_KEY'),
    ],

    /*
    | File containing the repository password, outside the public directory.
    | On Linux it must be private (0600); Windows uses inherited filesystem
    | ACLs, which PHP cannot prove equivalent to Unix permissions. Keep an
    | off-server copy: without it the repository cannot be recovered.
    */
    'password_file' => env(
        'QURABA_BACKUP_RESTIC_PASSWORD_FILE',
        storage_path('app/private/quraba-secrets/restic-password'),
    ),

    // null: {quraba-backup.paths.root}/cache/restic
    'cache_dir' => env('QURABA_BACKUP_RESTIC_CACHE_DIR'),

    /*
    | Environment identity used in snapshot tags is always the application's
    | quraba-backup.environment identity; it is intentionally not configurable
    | separately here so the two can never disagree.
    */

    /*
    | Timeouts in seconds, per operation class. Every value must be a positive
    | integer; zero or negative values are refused (never "unlimited").
    */
    'timeouts' => [
        'version' => (int) env('QURABA_BACKUP_RESTIC_TIMEOUT_VERSION', 30),
        'query' => (int) env('QURABA_BACKUP_RESTIC_TIMEOUT_QUERY', 300),
        'init' => (int) env('QURABA_BACKUP_RESTIC_TIMEOUT_INIT', 300),
        'backup' => (int) env('QURABA_BACKUP_RESTIC_TIMEOUT_BACKUP', 21600),
        'restore' => (int) env('QURABA_BACKUP_RESTIC_TIMEOUT_RESTORE', 21600),
        'check' => (int) env('QURABA_BACKUP_RESTIC_TIMEOUT_CHECK', 21600),
        'forget' => (int) env('QURABA_BACKUP_RESTIC_TIMEOUT_FORGET', 1800),
        'prune' => (int) env('QURABA_BACKUP_RESTIC_TIMEOUT_PRUNE', 21600),
    ],

    /*
    | Media roots backed up by Restic. The key is the root's stable logical
    | name (recorded in manifests); `path` must be an application-owned
    | directory. Refused: `/`, HOME, the application root or its parents, the
    | whole storage/ or public/ directory, the package's private storage, a
    | local Restic repository, overlapping roots, and (unless allow_symlinks)
    | paths that traverse a symlink. `public/storage` is Laravel's recreatable
    | link — back up `storage/app/public` instead. `optional` roots are skipped
    | when missing. Symlinks inside a root are stored as links, never followed.
    */
    'media' => [
        'roots' => [
            'public' => [
                'path' => storage_path('app/public'),
                'allow_symlinks' => false,
                'optional' => false,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Installer
    |--------------------------------------------------------------------------
    |
    | `quraba:backup:install-restic` downloads the official compressed release, checks
    | it against the pinned SHA-256 below AND the release's SHA256SUMS file,
    | decompresses it, verifies `restic version`, and atomically replaces the
    | managed binary. A mirror may be configured through `download_base_url`
    | (https only); the pinned checksums still decide what is accepted.
    |
    */
    'installer' => [
        'download_base_url' => env('QURABA_BACKUP_RESTIC_DOWNLOAD_BASE_URL', ResticRelease::DOWNLOAD_BASE_URL),
        'archive_template' => ResticRelease::ARCHIVE_TEMPLATE,
        'checksum_manifest_template' => ResticRelease::CHECKSUM_MANIFEST_TEMPLATE,
        'checksums' => ResticRelease::CHECKSUMS,
        'max_archive_bytes' => 64 * 1024 * 1024,
        'max_binary_bytes' => 256 * 1024 * 1024,
    ],

];
