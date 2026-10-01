<?php

declare(strict_types=1);

namespace Quraba\Backup\Restore;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Quraba\Backup\Archive\Database\MySqlDatabaseDumper;
use Quraba\Backup\Contracts\RestoreStepObserver;
use Quraba\Backup\Database\DatabaseToolLocator;
use Quraba\Backup\Database\DefinerFilter;
use Quraba\Backup\Database\DumpInspector;
use Quraba\Backup\Database\DumpSchemaFingerprinter;
use Quraba\Backup\Database\MySqlOptionFile;
use Quraba\Backup\Database\MySqlSchema;
use Quraba\Backup\Database\SchemaFingerprinter;
use Quraba\Backup\Database\ServerFlavor;
use Quraba\Backup\Exceptions\RestoreFailed;
use Quraba\Backup\Support\ConfigValue;
use Quraba\Backup\Support\Process\ChildEnvironment;
use Quraba\Backup\Support\Process\ProcessFactory;
use Quraba\Backup\Workspace\OperationWorkspace;
use Quraba\Backup\Workspace\WorkspaceArea;
use Throwable;

/**
 * Artifact/schema checks, with an optional import into a proven separate
 * scratch DB.
 *
 * The scratch database is emptied again after every import, successful or
 * not. If that cleanup fails the validation FAILS with
 * `restore.scratch_cleanup_failed` (never hidden behind the import's own
 * outcome): the scratch database is contaminated, the next validation
 * refuses it because it is not empty, and nothing broader is ever attempted
 * to "fix" it automatically.
 */
final readonly class RestoreDatabaseValidator
{
    public function __construct(
        private Repository $config,
        private DatabaseManager $database,
        private MySqlDatabaseDumper $dumper,
        private DatabaseToolLocator $tools,
        private ProcessFactory $processes,
        private SchemaFingerprinter $schemas,
        private MySqlSchema $schema,
        private RestoreStepObserver $steps,
    ) {}

    /** @param array<string, mixed> $metadata
     * @return array{level: string, dump_bytes: int, dump_tables: int, dump_definers: list<string>, dump_schema_fingerprint: ?string, live_schema_fingerprint: ?string, scratch_schema_fingerprint: ?string}
     */
    public function validate(string $dump, array $metadata, DbValidationLevel $level, OperationWorkspace $workspace): array
    {
        $size = @filesize($dump);
        if ($size === false || $size < 1) {
            throw RestoreFailed::reconstructionFailed('the reconstructed SQL dump is empty or missing');
        }

        $dbMeta = $metadata['database'] ?? null;
        if (! is_array($dbMeta) || array_is_list($dbMeta)) {
            throw RestoreFailed::reconstructionFailed('the archive has no database metadata');
        }
        $databaseMetadata = [];
        foreach ($dbMeta as $key => $value) {
            if (is_string($key)) {
                $databaseMetadata[$key] = $value;
            }
        }
        $recordedSize = $dbMeta['dump_bytes'] ?? null;
        if (is_int($recordedSize) && $recordedSize !== $size) {
            throw RestoreFailed::reconstructionFailed('the SQL dump size differs from archive metadata');
        }

        // Every level: the dump must be a plain single-database dump.
        $inspection = DumpInspector::inspect($dump);
        $tables = $inspection['tables'];

        $fingerprint = null;
        $scratchFingerprint = null;
        if ($level !== DbValidationLevel::Artifact) {
            $fingerprint = DumpSchemaFingerprinter::fingerprint($dump);
            $recorded = $dbMeta['dump_schema_fingerprint'] ?? null;
            if (! is_string($recorded) || $recorded === '' || $fingerprint === null) {
                throw RestoreFailed::reconstructionFailed('schema-level validation requires a backup with a complete dump schema fingerprint');
            }
            if (! hash_equals($recorded, $fingerprint)) {
                throw RestoreFailed::reconstructionFailed('the dump schema fingerprint differs from archive metadata');
            }
        }

        if ($level === DbValidationLevel::ScratchImport) {
            $scratchFingerprint = $this->scratchImport($dump, $databaseMetadata, $workspace);
            $expected = $dbMeta['schema_fingerprint'] ?? null;
            if (is_string($expected) && ! hash_equals($expected, $scratchFingerprint)) {
                throw RestoreFailed::scratchImportFailed('the imported schema differs from the backup schema fingerprint');
            }
        }

        return [
            'level' => $level->value,
            'dump_bytes' => $size,
            'dump_tables' => count($tables),
            'dump_definers' => $inspection['definers'],
            'dump_schema_fingerprint' => $fingerprint,
            'live_schema_fingerprint' => is_string($dbMeta['schema_fingerprint'] ?? null) ? $dbMeta['schema_fingerprint'] : null,
            'scratch_schema_fingerprint' => $scratchFingerprint,
        ];
    }

    /** @param array<string, mixed> $metadata */
    private function scratchImport(string $dump, array $metadata, OperationWorkspace $workspace): string
    {
        $scratchName = $this->config->get('quraba-backup.restore.scratch_connection');
        if (! is_string($scratchName) || $scratchName === '') {
            throw RestoreFailed::scratchUnsafe('configure a dedicated scratch_connection first');
        }
        $productionName = $this->dumper->connectionName();
        if ($scratchName === $productionName) {
            throw RestoreFailed::scratchUnsafe('the scratch and production connection names are identical');
        }

        $production = $this->database->connection($productionName)->selectOne('SELECT DATABASE() AS db');
        $scratch = $this->database->connection($scratchName)->selectOne('SELECT DATABASE() AS db');
        $productionDb = is_object($production) ? ($production->db ?? null) : null;
        $scratchDb = is_object($scratch) ? ($scratch->db ?? null) : null;
        if (! is_string($productionDb) || $productionDb === '' || ! is_string($scratchDb) || $scratchDb === '' || $productionDb === $scratchDb) {
            throw RestoreFailed::scratchUnsafe('SELECT DATABASE() did not prove a distinct scratch database');
        }

        $connection = $this->dumper->connectionConfig($scratchName);
        $configuredDb = $connection['database'] ?? null;
        if ($configuredDb !== $scratchDb) {
            throw RestoreFailed::scratchUnsafe('the scratch connection points to a different database than configured');
        }
        $this->assertScratchPrivileges($productionName, $scratchName, $scratchDb);
        $existing = $this->schema->inventory($scratchName);
        if (! $existing->isEmpty()) {
            throw RestoreFailed::scratchUnsafe(sprintf('the dedicated scratch database is not empty (%d object(s)); it may be contaminated by an earlier validation whose cleanup failed. Empty it manually', $existing->count()));
        }

        $version = $metadata['server_version'] ?? null;
        $flavor = is_string($version) ? ServerFlavor::fromVersionString($version) : ServerFlavor::Unknown;
        $tool = $this->tools->client($flavor) ?? throw RestoreFailed::scratchUnsafe('no MySQL/MariaDB client executable was found');
        $host = $connection['host'] ?? '127.0.0.1';
        $host = is_string($host) && $host !== '' ? $host : '127.0.0.1';
        $port = $connection['port'] ?? null;
        $socket = $connection['unix_socket'] ?? null;
        $optionFile = MySqlOptionFile::write(
            $workspace->area(WorkspaceArea::Restore),
            is_string($connection['username'] ?? null) ? $connection['username'] : '',
            is_string($connection['password'] ?? null) ? $connection['password'] : '',
            $host,
            is_numeric($port) ? (int) $port : null,
            is_string($socket) ? $socket : null,
        );
        $stream = @fopen($dump, 'rb');
        if ($stream === false) {
            MySqlOptionFile::destroy($optionFile);
            throw RestoreFailed::scratchImportFailed('the private SQL dump cannot be opened');
        }

        $imported = null;
        $importFailure = null;

        try {
            $this->steps->reached('scratch.import_starting');
            $process = $this->processes->make(
                [$tool->path, '--defaults-extra-file='.$optionFile, '--binary-mode', '--default-character-set=utf8mb4', $scratchDb],
                $workspace->area(WorkspaceArea::Restore), ChildEnvironment::build(),
                (float) ConfigValue::positiveInt($this->config->get('quraba-backup.timeouts.database_dump', 3600), 'quraba-backup.timeouts.database_dump'),
            );
            // The scratch account is deliberately another, confined account:
            // it can only create views, triggers and routines as itself.
            $process->setInput(DefinerFilter::stream($stream, strip: true));
            $process->run();
            if ($process->getExitCode() !== 0) {
                throw RestoreFailed::scratchImportFailed('the database client rejected the dump');
            }

            $imported = $this->schemas->fingerprint($scratchName);
            $this->steps->reached('scratch.import_finished');
        } catch (RestoreFailed $exception) {
            $importFailure = $exception;
        } catch (Throwable) {
            $importFailure = RestoreFailed::scratchImportFailed('the database client or schema inspection failed');
        } finally {
            fclose($stream);
            MySqlOptionFile::destroy($optionFile);
        }

        // The cleanup outcome is reported in its own right. One exact attempt:
        // no retries, nothing broader than the objects found in the scratch DB.
        try {
            $this->steps->reached('scratch.cleanup_starting');
            $this->schema->drop($scratchName, $scratchDb, $this->schema->inventory($scratchName));
        } catch (Throwable $cleanupFailure) {
            throw RestoreFailed::scratchCleanupFailed(sprintf(
                'after a %s import %s. The live application database was not touched. Empty the scratch database manually; the next scratch validation refuses it while it is not empty%s',
                $importFailure === null ? 'successful' : 'failed',
                $this->contamination($scratchName),
                $importFailure === null ? '' : '. The import itself also failed: '.$importFailure->getMessage(),
            ), $cleanupFailure);
        }

        if ($importFailure !== null) {
            throw $importFailure;
        }

        if ($imported === null) {
            throw RestoreFailed::scratchImportFailed('no schema fingerprint was produced');
        }

        return $imported;
    }

    private function contamination(string $scratchName): string
    {
        try {
            return sprintf('it still holds %d object(s)', $this->schema->inventory($scratchName)->count());
        } catch (Throwable) {
            return 'its remaining content could not be inspected';
        }
    }

    private function assertScratchPrivileges(string $productionName, string $scratchName, string $scratchDb): void
    {
        // A SQL dump can contain qualified names and USE statements. The
        // command-line database argument alone cannot confine its effects.

        $production = $this->database->connection($productionName)->selectOne('SELECT CURRENT_USER() AS account');
        $scratch = $this->database->connection($scratchName)->selectOne('SELECT CURRENT_USER() AS account');
        $productionAccount = is_object($production) ? ($production->account ?? null) : null;
        $scratchAccount = is_object($scratch) ? ($scratch->account ?? null) : null;
        if (! is_string($productionAccount) || ! is_string($scratchAccount) || $productionAccount === $scratchAccount) {
            throw RestoreFailed::scratchUnsafe('the scratch import must use a distinct database account');
        }

        $statements = [];
        foreach ($this->database->connection($scratchName)->select('SHOW GRANTS') as $row) {
            $values = is_object($row) ? array_values((array) $row) : [];
            $grant = $values[0] ?? null;
            if (! is_string($grant)) {
                throw RestoreFailed::scratchUnsafe('the scratch account grants could not be inspected');
            }
            $statements[] = $grant;
        }
        ScratchAccountGrants::assertConfined($statements, $scratchDb);
    }
}
