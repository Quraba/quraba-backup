<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Support;

use Illuminate\Database\DatabaseManager;
use Quraba\Backup\Archive\Database\DatabaseDump;
use Quraba\Backup\Contracts\DatabaseDumper;
use Quraba\Backup\Database\ServerFlavor;
use Quraba\Backup\Exceptions\ArchiveCreationFailed;
use Quraba\Backup\Workspace\OperationWorkspace;
use Quraba\Backup\Workspace\WorkspaceArea;

/**
 * Test-only dumper for the SQLite test database: writes a real SQL dump of
 * every table so the archive pipeline (Spatie zip, AES-256, verification,
 * upload) runs for real without a MySQL server. Production only supports
 * MySQL/MariaDB (MySqlDatabaseDumper).
 */
final class SqliteTestDumper implements DatabaseDumper
{
    public int $dumps = 0;

    public bool $fail = false;

    public function __construct(private readonly DatabaseManager $database) {}

    public function connectionName(): string
    {
        return 'testing';
    }

    public function dump(OperationWorkspace $workspace): DatabaseDump
    {
        if ($this->fail) {
            throw ArchiveCreationFailed::databaseDumpFailed('simulated dump failure');
        }

        $this->dumps++;
        $connection = $this->database->connection('testing');
        $path = $workspace->path(WorkspaceArea::Database, 'database.sql');
        $handle = fopen($path, 'xb');

        foreach ($connection->select("SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $table) {
            fwrite($handle, $table->sql.";\n");

            foreach ($connection->table($table->name)->get() as $row) {
                $values = array_map(static fn (mixed $v): string => $v === null ? 'NULL' : "'".str_replace("'", "''", (string) $v)."'", (array) $row);
                fwrite($handle, sprintf("INSERT INTO %s VALUES (%s);\n", $table->name, implode(', ', $values)));
            }
        }

        fclose($handle);

        return new DatabaseDump($path, (int) filesize($path), 'testing', 'sqlite', ':memory:', ServerFlavor::Unknown, 'sqlite-test', 'sqlite-test-dumper', 'test');
    }
}
