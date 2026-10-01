<?php

declare(strict_types=1);

namespace Quraba\Backup\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * Whether the package's catalog tables exist on this host.
 *
 * A clean host that is about to be restored has an empty target database:
 * no catalog, no package migrations. Remote discovery, restore source
 * resolution and the live restore itself must work there, so they ask this
 * class instead of assuming the tables exist. Nothing is cached: a restore
 * imports the catalog while it runs.
 *
 * A missing catalog is only ever a reason to rely on remote truth and the
 * restore journal. It is never, by itself, proof of a clean host.
 */
final readonly class LocalCatalog
{
    public const array TABLES = [
        'quraba_backup_runs',
        'quraba_backup_artifacts',
        'quraba_restore_runs',
        'quraba_backup_maintenance_runs',
        'quraba_backup_settings',
        'quraba_backup_repository_identities',
    ];

    public function __construct(
        private DatabaseManager $database,
        private Repository $config,
    ) {}

    public function connectionName(): ?string
    {
        $configured = $this->config->get('quraba-backup.database.catalog_connection');

        return is_string($configured) && $configured !== '' ? $configured : null;
    }

    /**
     * Every catalog table exists and the database is reachable.
     */
    public function available(): bool
    {
        foreach (self::TABLES as $table) {
            if (! $this->has($table)) {
                return false;
            }
        }

        return true;
    }

    public function has(string $table): bool
    {
        try {
            return $this->database->connection($this->connectionName())->getSchemaBuilder()->hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }
}
