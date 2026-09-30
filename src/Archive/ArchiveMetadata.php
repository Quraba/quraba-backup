<?php

declare(strict_types=1);

namespace Quraba\Backup\Archive;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Quraba\Backup\Archive\Database\DatabaseDump;
use Throwable;

/**
 * Builds the versioned, non-secret quraba-backup.json placed inside every
 * application archive. It never contains APP_KEY, credentials, passwords or
 * any .env value — only fingerprints and identities.
 */
final readonly class ArchiveMetadata
{
    public const int SCHEMA_VERSION = 1;

    public const string FILE_NAME = 'quraba-backup.json';

    public function __construct(
        private Repository $config,
        private Application $app,
        private DatabaseManager $database,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(ArchiveRequest $request, DatabaseDump $dump, string $databaseEntry, bool $includesEnv): array
    {
        $appKey = $this->config->get('app.key');

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'run_uuid' => $request->runUuid,
            'app_id' => $request->identity->appId,
            'environment' => $request->identity->environment,
            'profile' => $request->profile->value,
            'created_at' => CarbonImmutable::now('UTC')->toIso8601ZuluString(),
            'package_version' => $request->packageVersion,
            'laravel_version' => $this->app->version(),
            'php_version' => PHP_VERSION,
            'database' => [
                'connection' => $dump->connection,
                'driver' => $dump->driver,
                'flavor' => $dump->flavor->value,
                'server_version' => $dump->serverVersion,
                'dump_tool' => $dump->tool,
                'dump_tool_version' => $dump->toolVersion,
                'dump_bytes' => $dump->bytes,
                'entry' => $databaseEntry,
                'migration_fingerprint' => $this->migrationFingerprint($dump->connection),
                'schema_fingerprint' => $this->schemaFingerprint($dump->connection),
            ],
            'app_key_fingerprint' => is_string($appKey) && $appKey !== '' ? 'sha256:'.hash('sha256', $appKey) : null,
            'contents' => array_values(array_filter([
                $databaseEntry,
                $includesEnv ? '.env' : null,
                self::FILE_NAME,
            ])),
        ];
    }

    private function migrationFingerprint(string $connection): ?string
    {
        $configured = $this->config->get('database.migrations');
        $table = is_array($configured) ? ($configured['table'] ?? null) : $configured;
        $table = is_string($table) && $table !== '' ? $table : 'migrations';

        try {
            $migrations = $this->database->connection($connection)->table($table)->orderBy('migration')->pluck('migration')->all();
        } catch (Throwable) {
            return null;
        }

        return 'sha256:'.hash('sha256', implode("\n", array_map(self::scalar(...), $migrations)));
    }

    private static function scalar(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function schemaFingerprint(string $connection): ?string
    {
        try {
            $rows = $this->database->connection($connection)->select(
                'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION'
            );
        } catch (Throwable) {
            return null;
        }

        $lines = array_map(static fn (mixed $row): string => implode('|', array_map(self::scalar(...), (array) $row)), $rows);

        return 'sha256:'.hash('sha256', implode("\n", $lines));
    }
}
