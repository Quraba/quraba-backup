<?php

declare(strict_types=1);

namespace Quraba\Backup\Database;

use Illuminate\Database\DatabaseManager;

/** Stable information_schema fingerprint, independent of migration records. */
final readonly class SchemaFingerprinter
{
    public function __construct(private DatabaseManager $database) {}

    public function fingerprint(string $connection): string
    {
        $rows = $this->database->connection($connection)->select(
            'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION'
        );
        $lines = array_map(static fn (mixed $row): string => implode('|', array_map(
            static fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
            (array) $row,
        )), $rows);

        return 'sha256:'.hash('sha256', implode("\n", $lines));
    }
}
