<?php

declare(strict_types=1);

namespace Quraba\Backup\Archive\Database;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConfigurationUrlParser;
use Illuminate\Database\DatabaseManager;
use Quraba\Backup\Contracts\DatabaseDumper;
use Quraba\Backup\Database\DatabaseToolLocator;
use Quraba\Backup\Database\ServerFlavor;
use Quraba\Backup\Exceptions\ArchiveCreationFailed;
use Quraba\Backup\Security\SecretRedactor;
use Quraba\Backup\Support\ConfigValue;
use Quraba\Backup\Support\Process\ProcessFactory;
use Quraba\Backup\Workspace\OperationWorkspace;
use Quraba\Backup\Workspace\WorkspaceArea;
use Throwable;

/**
 * Dumps the application's MySQL/MariaDB database into the operation
 * workspace with Spatie's dumper (safe executor, see ArgvMySqlDumper).
 *
 * The primary (write) connection is dumped, never a read replica, so the
 * dump reflects the authoritative state.
 */
final readonly class MySqlDatabaseDumper implements DatabaseDumper
{
    public const array SUPPORTED_DRIVERS = ['mysql', 'mariadb'];

    public function __construct(
        private Repository $config,
        private DatabaseManager $database,
        private DatabaseToolLocator $tools,
        private ProcessFactory $processes,
        private SecretRedactor $redactor,
    ) {}

    public function connectionName(): string
    {
        return ConfigValue::stringOrNull($this->config->get('quraba-backup.database.connection'))
            ?? ConfigValue::stringOrNull($this->config->get('database.default'))
            ?? 'mysql';
    }

    /**
     * @return array<string, mixed>
     */
    public function connectionConfig(string $connection): array
    {
        $raw = $this->config->get("database.connections.{$connection}");

        if (! is_array($raw)) {
            throw ArchiveCreationFailed::databaseDumpFailed(sprintf('the database connection [%s] is not configured', $connection));
        }

        $typed = [];

        foreach ($raw as $key => $value) {
            $typed[(string) $key] = $value;
        }

        $parsed = (new ConfigurationUrlParser)->parseConfiguration($typed);

        if (isset($parsed['write']) && is_array($parsed['write'])) {
            $parsed = array_merge($parsed, $parsed['write']);
        }

        unset($parsed['read'], $parsed['write']);

        return $parsed;
    }

    public function dump(OperationWorkspace $workspace): DatabaseDump
    {
        $connection = $this->connectionName();
        $config = $this->connectionConfig($connection);
        $driver = is_string($config['driver'] ?? null) ? $config['driver'] : '';

        if (! in_array($driver, self::SUPPORTED_DRIVERS, true)) {
            throw ArchiveCreationFailed::databaseDumpFailed(sprintf('the [%s] driver of connection [%s] is not supported; only MySQL and MariaDB are', $driver, $connection));
        }

        $database = is_string($config['database'] ?? null) ? $config['database'] : '';
        $serverVersion = $this->serverVersion($connection);
        $flavor = ServerFlavor::fromVersionString($serverVersion);

        try {
            $tool = $this->tools->dumper($flavor);
        } catch (Throwable $exception) {
            throw ArchiveCreationFailed::databaseDumpFailed($this->redactor->redact($exception->getMessage()));
        }

        if ($tool === null) {
            throw ArchiveCreationFailed::databaseDumpFailed('no mariadb-dump/mysqldump executable was found (run quraba:backup:doctor)');
        }

        $host = $config['host'] ?? null;
        $host = is_array($host) ? reset($host) : $host;
        $host = is_string($host) ? $host : '';

        $dumper = ArgvMySqlDumper::using(
            $this->processes,
            $tool->path,
            $tool->version,
            $workspace->area(WorkspaceArea::Database),
            ConfigValue::positiveInt($this->config->get('quraba-backup.timeouts.database_dump', 3600), 'quraba-backup.timeouts.database_dump'),
        )
            ->setDbName($database)
            ->setUserName(is_string($config['username'] ?? null) ? $config['username'] : '')
            ->setPassword(is_string($config['password'] ?? null) ? $config['password'] : '')
            ->setHost($host === '' ? '127.0.0.1' : $host)
            ->setDefaultCharacterSet(is_string($config['charset'] ?? null) ? $config['charset'] : 'utf8mb4');

        $port = filter_var($config['port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);

        if (is_int($port)) {
            $dumper->setPort($port);
        }

        if (is_string($config['unix_socket'] ?? null) && $config['unix_socket'] !== '') {
            $dumper->setSocket($config['unix_socket']);
        }

        $routinesIncluded = (bool) $this->config->get('quraba-backup.database.dump_routines', true);
        if ($routinesIncluded) {
            $dumper->includeRoutines();
        }

        $eventPolicy = $this->config->get('quraba-backup.database.event_policy', 'auto');
        if (! is_string($eventPolicy) || ! in_array($eventPolicy, ['auto', 'required', 'assume_none'], true)) {
            throw ArchiveCreationFailed::databaseDumpFailed('database.event_policy must be auto, required or assume_none');
        }
        if ((bool) $this->config->get('quraba-backup.database.dump_events', false)) {
            $eventPolicy = 'required';
        }
        $eventPrivilege = $this->hasEventPrivilege($connection, $database);
        $objectPrivileges = $this->objectPrivileges($connection, $database);
        if ($eventPolicy === 'required' && ! $eventPrivilege) {
            throw ArchiveCreationFailed::databaseDumpFailed('EVENT privilege could not be proven for the database; use event_policy=assume_none only after an explicit operator decision');
        }
        $eventsIncluded = $eventPolicy !== 'assume_none' && $eventPrivilege;
        if ($eventsIncluded) {
            $dumper->withEvents();
        }

        $path = $workspace->path(WorkspaceArea::Database, 'database.sql');

        try {
            $dumper->dumpToFile($path);
        } catch (Throwable $exception) {
            if (is_file($path)) {
                @unlink($path);
            }

            throw ArchiveCreationFailed::databaseDumpFailed(mb_substr($this->redactor->redact($exception->getMessage()), 0, 1500));
        }

        $bytes = (int) filesize($path);

        return new DatabaseDump($path, $bytes, $connection, $driver, $database, $flavor, $serverVersion, $tool->name, $tool->version, $eventPolicy, $eventsIncluded, $routinesIncluded, $eventPrivilege, $objectPrivileges);
    }

    public function hasEventPrivilege(string $connection, string $database): bool
    {
        try {
            $rows = $this->database->connection($connection)->select('SHOW GRANTS');
            foreach ($rows as $row) {
                foreach ((array) $row as $statement) {
                    if (is_string($statement) && self::grantIncludesEvents($statement, $database)) {
                        return true;
                    }
                }
            }
        } catch (Throwable) {
            // Unknown capability is never treated as proof.
        }

        return false;
    }

    public static function grantIncludesEvents(string $statement, string $database): bool
    {
        return self::grantIncludesPrivilege($statement, $database, 'EVENT');
    }

    public static function grantIncludesPrivilege(string $statement, string $database, string $privilege, bool $globalOnly = false): bool
    {
        if (preg_match('/^GRANT\s+(.+?)\s+ON\s+(.+?)\s+TO\s+/i', $statement, $matches) !== 1) {
            return false;
        }
        $scope = str_replace('`', '', $matches[2]);
        if ($scope !== '*.*' && ($globalOnly || $scope !== $database.'.*')) {
            return false;
        }

        $grants = strtoupper(str_replace('_', ' ', $matches[1]));

        return preg_match('/\bALL(?:\s+PRIVILEGES)?\b/', $grants) === 1
            || preg_match('/(?:^|,)\s*'.preg_quote(strtoupper(str_replace('_', ' ', $privilege)), '/').'\s*(?:,|$)/', $grants) === 1;
    }

    /** @return array{tables: bool, views: bool, triggers: bool, routines: bool} */
    public function objectPrivileges(string $connection, string $database): array
    {
        $proof = ['tables' => false, 'views' => false, 'triggers' => false, 'routines' => false];

        try {
            foreach ($this->database->connection($connection)->select('SHOW GRANTS') as $row) {
                foreach ((array) $row as $statement) {
                    if (! is_string($statement)) {
                        continue;
                    }
                    $select = self::grantIncludesPrivilege($statement, $database, 'SELECT');
                    $proof['tables'] = $proof['tables'] || $select;
                    $proof['views'] = $proof['views'] || self::grantIncludesPrivilege($statement, $database, 'SHOW VIEW');
                    $proof['triggers'] = $proof['triggers'] || self::grantIncludesPrivilege($statement, $database, 'TRIGGER');
                    // SHOW_ROUTINE is a global dynamic privilege in MySQL.
                    // Database-scoped ALL alone is not proof across engines.
                    $proof['routines'] = $proof['routines'] || self::grantIncludesPrivilege($statement, $database, 'SHOW ROUTINE', true)
                        || self::grantIncludesPrivilege($statement, $database, 'ALL', true);
                }
            }
        } catch (Throwable) {
            // No grant evidence means no exact-completeness claim.
        }

        $proof['views'] = $proof['views'] && $proof['tables'];

        return $proof;
    }

    private function serverVersion(string $connection): string
    {
        try {
            $row = $this->database->connection($connection)->selectOne('SELECT VERSION() AS version');
        } catch (Throwable $exception) {
            throw ArchiveCreationFailed::databaseDumpFailed('the database is not reachable: '.$this->redactor->redact($exception->getMessage()));
        }

        return is_object($row) && isset($row->version) && is_string($row->version) ? $row->version : '';
    }
}
