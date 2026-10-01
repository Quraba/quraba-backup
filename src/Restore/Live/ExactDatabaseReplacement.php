<?php

declare(strict_types=1);

namespace Quraba\Backup\Restore\Live;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Quraba\Backup\Archive\ArchiveMetadata;
use Quraba\Backup\Archive\Database\MySqlDatabaseDumper;
use Quraba\Backup\Contracts\DatabaseReplacement;
use Quraba\Backup\Database\DatabaseTarget;
use Quraba\Backup\Database\DatabaseToolLocator;
use Quraba\Backup\Database\DefinerFilter;
use Quraba\Backup\Database\DumpInspector;
use Quraba\Backup\Database\MySqlOptionFile;
use Quraba\Backup\Database\MySqlSchema;
use Quraba\Backup\Database\SchemaFingerprinter;
use Quraba\Backup\Database\SchemaInventory;
use Quraba\Backup\Database\ServerFlavor;
use Quraba\Backup\Exceptions\RestoreFailed;
use Quraba\Backup\Support\ConfigValue;
use Quraba\Backup\Support\Process\ChildEnvironment;
use Quraba\Backup\Support\Process\ProcessFactory;
use Quraba\Backup\Workspace\OperationWorkspace;
use Quraba\Backup\Workspace\WorkspaceArea;
use Throwable;

/**
 * Exact replacement of the live MySQL/MariaDB application database.
 *
 * Target proof (repeated before every mutation): the configured production
 * connection, on its PRIMARY, reports via `SELECT DATABASE()` exactly the
 * configured database name; it is not a system schema; it is not the scratch
 * validation database; and connection, database, server and account are the
 * ones recorded at preflight.
 *
 * Clearing drops precisely the inventoried objects, each schema-qualified
 * with the proven database name ({@see MySqlSchema::drop()}); `DROP
 * DATABASE` is never issued. The import streams the validated dump into the
 * client's stdin: an argument array (no shell), credentials only in a
 * proven-private option file, a bounded timeout and a minimal environment.
 * A clean exit is not success: {@see self::verify()} reconnects and proves
 * the result.
 */
final readonly class ExactDatabaseReplacement implements DatabaseReplacement
{
    public function __construct(
        private Repository $config,
        private DatabaseManager $database,
        private MySqlDatabaseDumper $dumper,
        private DatabaseToolLocator $tools,
        private ProcessFactory $processes,
        private MySqlSchema $schema,
        private SchemaFingerprinter $schemas,
        private ArchiveMetadata $metadata,
    ) {}

    public function target(): DatabaseTarget
    {
        $connection = $this->dumper->connectionName();

        try {
            $settings = $this->dumper->connectionConfig($connection);
        } catch (Throwable) {
            throw RestoreFailed::databaseTargetUnsafe(sprintf('the production connection [%s] is not configured', $connection));
        }

        if (! in_array($settings['driver'] ?? null, MySqlDatabaseDumper::SUPPORTED_DRIVERS, true)) {
            throw RestoreFailed::databaseTargetUnsafe(sprintf('connection [%s] is not a MySQL/MariaDB connection', $connection));
        }

        $expected = $settings['database'] ?? null;

        if (! is_string($expected) || $expected === '') {
            throw RestoreFailed::databaseTargetUnsafe('the production connection names no database');
        }

        $facts = $this->facts($connection);

        if ($facts === null) {
            throw RestoreFailed::databaseTargetUnsafe('the production database is not reachable or did not report its identity');
        }

        if (! self::sameName($facts['db'], $expected, $facts['folds_case'])) {
            throw RestoreFailed::databaseTargetUnsafe(sprintf('SELECT DATABASE() reports [%s] but connection [%s] is configured for [%s]', $facts['db'], $connection, $expected));
        }

        if (in_array(strtolower($facts['db']), MySqlSchema::SYSTEM_SCHEMAS, true)) {
            throw RestoreFailed::databaseTargetUnsafe(sprintf('[%s] is a system schema and is never a restore target', $facts['db']));
        }

        $target = new DatabaseTarget($connection, $facts['db'], $facts['server'], $facts['account'], $facts['version']);
        $this->assertNotScratch($target, $settings, $facts['folds_case']);

        if ($this->tools->client(ServerFlavor::fromVersionString($target->version)) === null) {
            throw RestoreFailed::databaseTargetUnsafe('no mysql/mariadb client executable was found, so a dump could not be imported (run quraba:backup:doctor)');
        }

        return $target;
    }

    public function assertImportable(DatabaseTarget $target, string $dump): array
    {
        $this->assertUnchanged($target);
        $definers = DumpInspector::inspect($dump)['definers'];
        [$user, $host] = array_pad(explode('@', $target->account, 2), 2, '');
        $account = $user.'@'.strtolower($host);
        $foreign = array_values(array_filter($definers, static fn (string $definer): bool => $definer !== $account));
        $rewrite = (bool) $this->config->get('quraba-backup.restore.rewrite_definers', false);

        if ($foreign !== [] && ! $rewrite && ! $this->mayDefineForOthers($target->connection)) {
            throw RestoreFailed::databaseTargetUnsafe(sprintf(
                'the dump defines views, triggers or routines as %s, but the restoring account is %s and may not create objects for another account, so the import would fail halfway. Set quraba-backup.restore.rewrite_definers=true to create them as the restoring account',
                implode(', ', array_slice($foreign, 0, 5)),
                $account,
            ));
        }

        return ['definers' => $definers, 'restoring_account' => $account, 'definers_rewritten' => $rewrite && $definers !== []];
    }

    public function inventory(DatabaseTarget $target): SchemaInventory
    {
        $this->assertUnchanged($target);

        try {
            return $this->schema->inventory($target->connection);
        } catch (Throwable $exception) {
            throw RestoreFailed::databaseTargetUnsafe('the live schema inventory could not be built completely: '.$exception->getMessage());
        }
    }

    public function fingerprint(DatabaseTarget $target): string
    {
        $this->assertUnchanged($target);

        try {
            return $this->schemas->fingerprint($target->connection);
        } catch (Throwable $exception) {
            throw RestoreFailed::databaseVerificationFailed('the schema fingerprint could not be computed: '.$exception->getMessage());
        }
    }

    public function clear(DatabaseTarget $target, SchemaInventory $inventory, Closure $dropped): void
    {
        $this->assertUnchanged($target);

        try {
            $this->schema->drop($target->connection, $target->database, $inventory, $dropped);
        } catch (RestoreFailed $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw RestoreFailed::databaseApplyFailed('clearing the proven target schema stopped: '.$exception->getMessage(), $exception);
        }
    }

    public function import(DatabaseTarget $target, string $dump, OperationWorkspace $workspace): void
    {
        $settings = $this->dumper->connectionConfig($target->connection);
        $tool = $this->tools->client(ServerFlavor::fromVersionString($target->version))
            ?? throw RestoreFailed::databaseApplyFailed('no mysql/mariadb client executable was found');
        $host = $settings['host'] ?? null;
        $host = is_array($host) ? reset($host) : $host;
        $port = filter_var($settings['port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        $socket = $settings['unix_socket'] ?? null;
        $charset = $settings['charset'] ?? null;
        $timeout = ConfigValue::positiveInt($this->config->get('quraba-backup.timeouts.database_import', 7200), 'quraba-backup.timeouts.database_import');

        $stream = @fopen($dump, 'rb');

        if ($stream === false) {
            throw RestoreFailed::databaseApplyFailed('the validated SQL dump cannot be opened');
        }

        $optionFile = null;

        try {
            $optionFile = MySqlOptionFile::write(
                $workspace->area(WorkspaceArea::Restore),
                is_string($settings['username'] ?? null) ? $settings['username'] : '',
                is_string($settings['password'] ?? null) ? $settings['password'] : '',
                is_string($host) ? $host : null,
                is_int($port) ? $port : null,
                is_string($socket) && $socket !== '' ? $socket : null,
            );

            $process = $this->processes->make([
                $tool->path,
                // Must be the first option; the password is never an argument.
                '--defaults-extra-file='.$optionFile,
                '--binary-mode',
                '--default-character-set='.(is_string($charset) && preg_match('/^[A-Za-z0-9_]+$/', $charset) === 1 ? $charset : 'utf8mb4'),
                '--database='.$target->database,
            ], $workspace->area(WorkspaceArea::Restore), ChildEnvironment::build(), (float) $timeout);
            // Streamed, never loaded into memory. DEFINER clauses are only
            // removed when the operator configured it (see assertImportable()).
            $process->setInput(DefinerFilter::stream($stream, (bool) $this->config->get('quraba-backup.restore.rewrite_definers', false)));

            // The production target is re-proven immediately before launch.
            $this->assertUnchanged($target);
            $process->run();
        } catch (RestoreFailed $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw RestoreFailed::databaseApplyFailed('the database client could not finish the import ('.class_basename($exception).')', $exception);
        } finally {
            fclose($stream);

            if ($optionFile !== null) {
                MySqlOptionFile::destroy($optionFile);
            }
        }

        if ($process->getExitCode() !== 0) {
            // Client errors can quote row data; only the error position is kept.
            $position = preg_match('/ERROR \d+ \([0-9A-Z]+\) at line \d+/', $process->getErrorOutput(), $match) === 1 ? ' ('.$match[0].')' : '';

            throw RestoreFailed::databaseApplyFailed(sprintf('the database client exited with code %s%s', (string) $process->getExitCode(), $position));
        }
    }

    public function verify(DatabaseTarget $target, string $dump, array $metadata, ?string $scratchFingerprint): array
    {
        // A fresh session: nothing cached from before the import is trusted.
        $this->database->purge($target->connection);
        $this->assertUnchanged($target);

        $facts = $this->facts($target->connection);
        $fold = $facts['folds_case'] ?? false;
        $normalize = static fn (string $name): string => $fold ? strtolower($name) : $name;

        try {
            $inventory = $this->schema->inventory($target->connection);
            $fingerprint = $this->schemas->fingerprint($target->connection);
        } catch (Throwable $exception) {
            throw RestoreFailed::databaseVerificationFailed('the restored schema could not be inspected: '.$exception->getMessage());
        }

        $expectedTables = array_map($normalize, DumpInspector::inspect($dump)['tables']);
        $actualTables = array_map($normalize, $inventory->names(SchemaInventory::TABLE));
        sort($expectedTables, SORT_STRING);
        sort($actualTables, SORT_STRING);

        if ($expectedTables === [] || $expectedTables !== $actualTables) {
            throw RestoreFailed::databaseVerificationFailed(sprintf('the database holds %d base table(s) but the dump creates %d; the import is incomplete or the target was changed', count($actualTables), count($expectedTables)));
        }

        // Schema fingerprints depend on the server version, so they are only
        // compared against a fingerprint taken on a comparable server.
        $recorded = is_string($metadata['schema_fingerprint'] ?? null) ? $metadata['schema_fingerprint'] : null;
        $sameServer = is_string($metadata['server_version'] ?? null) && $metadata['server_version'] === $target->version;
        $comparison = 'not_comparable';

        if ($scratchFingerprint !== null) {
            $comparison = 'scratch_import';

            if (! hash_equals($scratchFingerprint, $fingerprint)) {
                throw RestoreFailed::databaseVerificationFailed('the restored schema differs from the schema validated by the scratch import');
            }
        } elseif ($recorded !== null && $sameServer) {
            $comparison = 'backup_metadata';

            if (! hash_equals($recorded, $fingerprint)) {
                throw RestoreFailed::databaseVerificationFailed('the restored schema fingerprint differs from the fingerprint recorded in the backup');
            }
        }

        $recordedMigrations = is_string($metadata['migration_fingerprint'] ?? null) ? $metadata['migration_fingerprint'] : null;
        $migrations = $this->metadata->migrationFingerprint($target->connection);

        if ($recordedMigrations !== null && ($migrations === null || ! hash_equals($recordedMigrations, $migrations))) {
            throw RestoreFailed::databaseVerificationFailed('the restored migration history differs from the history recorded in the backup');
        }

        return [
            'database' => $target->database,
            'objects' => $inventory->summary(),
            'base_tables' => count($actualTables),
            'schema_fingerprint' => $fingerprint,
            'schema_comparison' => $comparison,
            'migration_fingerprint' => $migrations,
            'migration_comparison' => $recordedMigrations === null ? 'not_recorded' : 'match',
        ];
    }

    /**
     * Whether the restoring account holds a global privilege that allows
     * creating objects with another account as DEFINER.
     */
    private function mayDefineForOthers(string $connection): bool
    {
        try {
            foreach ($this->database->connection($connection)->select('SHOW GRANTS', [], false) as $row) {
                $grant = array_values((array) $row)[0] ?? null;

                if (is_string($grant) && preg_match('/^GRANT\s+(.+?)\s+ON\s+\*\.\*\s+TO\s/i', $grant, $matches) === 1
                    && preg_match('/\b(ALL PRIVILEGES|SUPER|SET USER|SET_USER_ID|SET_ANY_DEFINER)\b/i', $matches[1]) === 1) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    private function assertUnchanged(DatabaseTarget $target): void
    {
        $current = $this->target();

        if (! $current->equals($target)) {
            throw RestoreFailed::databaseTargetUnsafe(sprintf('the live database target changed since it was proven (was %s on %s, now %s on %s)', $target->database, $target->server, $current->database, $current->server));
        }
    }

    /**
     * @return array{db: string, server: string, account: string, version: string, folds_case: bool}|null
     */
    private function facts(string $connection): ?array
    {
        try {
            $row = $this->database->connection($connection)->selectOne(
                'SELECT DATABASE() AS db, @@hostname AS host, @@port AS port, CURRENT_USER() AS account, VERSION() AS version, @@lower_case_table_names AS fold',
                [],
                // The primary, never a read replica.
                false,
            );
        } catch (Throwable) {
            return null;
        }

        if (! is_object($row)) {
            return null;
        }

        $values = (array) $row;
        $db = $values['db'] ?? null;
        $host = $values['host'] ?? null;
        $port = $values['port'] ?? null;
        $account = $values['account'] ?? null;
        $version = $values['version'] ?? null;
        $fold = $values['fold'] ?? 0;

        if (! is_string($db) || $db === '' || ! is_scalar($host) || ! is_scalar($port) || ! is_string($account) || ! is_string($version)) {
            return null;
        }

        return ['db' => $db, 'server' => $host.':'.$port, 'account' => $account, 'version' => $version, 'folds_case' => is_numeric($fold) && (int) $fold !== 0];
    }

    /**
     * The scratch validation database must never be the live target.
     *
     * @param  array<string, mixed>  $settings  production connection settings
     */
    private function assertNotScratch(DatabaseTarget $target, array $settings, bool $foldsCase): void
    {
        $scratchName = $this->config->get('quraba-backup.restore.scratch_connection');

        if (! is_string($scratchName) || $scratchName === '') {
            return;
        }

        if ($scratchName === $target->connection) {
            throw RestoreFailed::databaseTargetUnsafe('the scratch connection and the production connection are the same');
        }

        try {
            $scratch = $this->dumper->connectionConfig($scratchName);
        } catch (Throwable) {
            return;
        }

        $sameEndpoint = ($scratch['host'] ?? null) === ($settings['host'] ?? null)
            && (string) (is_scalar($scratch['port'] ?? null) ? $scratch['port'] : '') === (string) (is_scalar($settings['port'] ?? null) ? $settings['port'] : '')
            && ($scratch['unix_socket'] ?? null) === ($settings['unix_socket'] ?? null);

        if ($sameEndpoint && is_string($scratch['database'] ?? null) && self::sameName($scratch['database'], $target->database, $foldsCase)) {
            throw RestoreFailed::databaseTargetUnsafe('the scratch connection is configured for the live database');
        }

        // What the scratch server itself reports, when it can be asked.
        $facts = $this->facts($scratchName);

        if ($facts !== null && $facts['server'] === $target->server && self::sameName($facts['db'], $target->database, $foldsCase)) {
            throw RestoreFailed::databaseTargetUnsafe('the scratch connection resolves to the live database');
        }
    }

    private static function sameName(string $a, string $b, bool $foldsCase): bool
    {
        return $foldsCase ? strtolower($a) === strtolower($b) : $a === $b;
    }
}
