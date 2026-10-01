<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Support;

use Closure;
use Illuminate\Database\DatabaseManager;
use Quraba\Backup\Contracts\DatabaseReplacement;
use Quraba\Backup\Database\DatabaseTarget;
use Quraba\Backup\Database\SchemaInventory;
use Quraba\Backup\Exceptions\RestoreFailed;
use Quraba\Backup\Workspace\OperationWorkspace;
use Throwable;

/**
 * Test-only exact database replacement for the SQLite test database, so the
 * live restore orchestration (journal, boundary, hand-back, verification,
 * reconciliation) runs for real without a MySQL server. It imports the
 * dumps written by SqliteTestDumper. Production only supports MySQL/MariaDB
 * (ExactDatabaseReplacement, covered by the real-database integration tests).
 */
final class SqliteDatabaseReplacement implements DatabaseReplacement
{
    /** Stop the import after this many statements (a client dying mid-import). */
    public ?int $failImportAfterStatements = null;

    /** The client "exits non-zero" without importing anything. */
    public bool $importExitsNonZero = false;

    /** Silently skip this table: a clean exit with an incomplete schema. */
    public ?string $silentlySkipTable = null;

    /** Report another database on the next proof (target confusion). */
    public ?string $pretendDatabase = null;

    public int $imports = 0;

    public int $clears = 0;

    public function __construct(private readonly DatabaseManager $database) {}

    public function target(): DatabaseTarget
    {
        return new DatabaseTarget('testing', $this->pretendDatabase ?? 'main', 'sqlite-memory', 'test', 'sqlite-test');
    }

    public function assertImportable(DatabaseTarget $target, string $dump): array
    {
        $this->assertUnchanged($target);

        return ['definers' => [], 'restoring_account' => 'test', 'definers_rewritten' => false];
    }

    public function inventory(DatabaseTarget $target): SchemaInventory
    {
        $this->assertUnchanged($target);
        $objects = [];

        foreach ($this->database->connection('testing')->select("SELECT name, type FROM sqlite_master WHERE type IN ('table', 'view', 'trigger') AND name NOT LIKE 'sqlite_%'") as $row) {
            $objects[] = ['type' => match ($row->type) {
                'view' => SchemaInventory::VIEW,
                'trigger' => SchemaInventory::TRIGGER,
                default => SchemaInventory::TABLE,
            }, 'name' => (string) $row->name];
        }

        return new SchemaInventory($objects);
    }

    public function fingerprint(DatabaseTarget $target): string
    {
        $this->assertUnchanged($target);
        $rows = $this->database->connection('testing')->select("SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");

        return 'sha256:'.hash('sha256', implode("\n", array_map(static fn (object $row): string => $row->name.'|'.$row->sql, $rows)));
    }

    public function clear(DatabaseTarget $target, SchemaInventory $inventory, Closure $dropped): void
    {
        $this->assertUnchanged($target);
        $this->clears++;
        $connection = $this->database->connection('testing');
        // The suite runs inside a transaction: defer FK checks instead of
        // toggling the pragma, which SQLite ignores mid-transaction.
        $connection->statement('PRAGMA defer_foreign_keys = ON');

        try {
            foreach ($inventory->droppable() as $object) {
                $connection->statement(($object['type'] === SchemaInventory::VIEW ? 'DROP VIEW ' : 'DROP TABLE ').'"'.str_replace('"', '""', $object['name']).'"');
                $dropped($object['type'], $object['name']);
            }
        } catch (RestoreFailed $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if ($exception instanceof SimulatedCrash) {
                throw $exception;
            }

            throw RestoreFailed::databaseApplyFailed('clearing stopped: '.$exception->getMessage(), $exception);
        }
    }

    public function import(DatabaseTarget $target, string $dump, OperationWorkspace $workspace): void
    {
        $this->assertUnchanged($target);
        $this->imports++;

        if ($this->importExitsNonZero) {
            throw RestoreFailed::databaseApplyFailed('the database client exited with code 1');
        }

        $connection = $this->database->connection('testing');
        $connection->statement('PRAGMA defer_foreign_keys = ON');
        $statements = array_values(array_filter(array_map(trim(...), explode(";\n", (string) file_get_contents($dump)))));
        // Like a real dump (which disables FK checks): every table exists
        // before any row references it.
        usort($statements, static fn (string $a, string $b): int => str_starts_with($b, 'CREATE TABLE') <=> str_starts_with($a, 'CREATE TABLE'));

        foreach ($statements as $index => $statement) {
            if ($this->failImportAfterStatements !== null && $index >= $this->failImportAfterStatements) {
                throw RestoreFailed::databaseApplyFailed('the database client exited with code 1 (ERROR 2013 (HY000) at line '.($index + 1).')');
            }

            if ($this->silentlySkipTable !== null && str_contains($statement, $this->silentlySkipTable)) {
                continue;
            }

            $connection->unprepared(rtrim($statement, ';'));
        }
    }

    public function verify(DatabaseTarget $target, string $dump, array $metadata, ?string $scratchFingerprint): array
    {
        $this->assertUnchanged($target);
        preg_match_all('/^CREATE TABLE "?([A-Za-z0-9_]+)"? /m', (string) file_get_contents($dump), $matches);
        $expected = $matches[1];
        $actual = $this->inventory($target)->names(SchemaInventory::TABLE);
        sort($expected);

        if ($expected === [] || $expected !== $actual) {
            throw RestoreFailed::databaseVerificationFailed(sprintf('the database holds %d base table(s) but the dump creates %d; the import is incomplete or the target was changed', count($actual), count($expected)));
        }

        return [
            'database' => $target->database,
            'base_tables' => count($actual),
            'schema_fingerprint' => $this->fingerprint($target),
            'schema_comparison' => 'dump_tables',
        ];
    }

    private function assertUnchanged(DatabaseTarget $target): void
    {
        if (! $this->target()->equals($target)) {
            throw RestoreFailed::databaseTargetUnsafe('the live database target changed since it was proven');
        }
    }
}
