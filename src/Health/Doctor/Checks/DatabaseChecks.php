<?php

declare(strict_types=1);

namespace Quraba\Backup\Health\Doctor\Checks;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Quraba\Backup\Archive\Database\MySqlDatabaseDumper;
use Quraba\Backup\Database\DatabaseToolKind;
use Quraba\Backup\Database\DatabaseToolLocator;
use Quraba\Backup\Database\ServerFlavor;
use Quraba\Backup\Health\CheckResult;
use Quraba\Backup\Health\Doctor\DoctorCheck;
use Quraba\Backup\Support\ConfigValue;
use Throwable;

/**
 * Application database driver, connectivity (read-only `SELECT VERSION()`)
 * and discovery of the dump/client executables.
 */
final readonly class DatabaseChecks implements DoctorCheck
{
    private const array SUPPORTED_DRIVERS = ['mysql', 'mariadb'];

    public function __construct(
        private Repository $config,
        private DatabaseManager $database,
        private DatabaseToolLocator $tools,
        private MySqlDatabaseDumper $dumper,
    ) {}

    public function name(): string
    {
        return 'database';
    }

    public function run(): array
    {
        $connection = $this->connectionName();
        $driver = $this->config->get("database.connections.{$connection}.driver");

        if (! is_string($driver)) {
            return [CheckResult::fail('database.driver', 'Database driver', sprintf('The database connection [%s] is not configured.', $connection))];
        }

        $results = [];

        if (! in_array($driver, self::SUPPORTED_DRIVERS, true)) {
            $results[] = CheckResult::fail('database.driver', 'Database driver', sprintf('Connection [%s] uses the [%s] driver; only MySQL and MariaDB are supported.', $connection, $driver));
        } else {
            $results[] = CheckResult::pass('database.driver', 'Database driver', sprintf('Connection [%s] uses the %s driver.', $connection, $driver));
        }

        $results[] = extension_loaded('pdo_mysql')
            ? CheckResult::pass('database.pdo_mysql', 'pdo_mysql extension', 'Available.')
            : CheckResult::fail('database.pdo_mysql', 'pdo_mysql extension', 'Missing; the application cannot talk to MySQL/MariaDB.');

        $flavor = ServerFlavor::Unknown;

        if (in_array($driver, self::SUPPORTED_DRIVERS, true)) {
            try {
                $row = $this->database->connection($connection)->selectOne('SELECT VERSION() AS version');
                $version = is_object($row) && isset($row->version) && is_string($row->version) ? $row->version : '';
                $flavor = ServerFlavor::fromVersionString($version);

                $results[] = CheckResult::pass('database.connection', 'Database connection', sprintf('Connected; server reports %s (%s).', $version, $flavor->value), ['server_version' => $version, 'flavor' => $flavor->value]);
                $results[] = $this->events($connection);
                $results[] = $this->objectPrivileges($connection);
            } catch (Throwable $exception) {
                $results[] = CheckResult::fail('database.connection', 'Database connection', 'Could not connect to the application database; check the configured credentials and network.');
            }
        }

        foreach ([DatabaseToolKind::Dumper, DatabaseToolKind::Client] as $kind) {
            $results[] = $this->tool($kind, $flavor);
        }

        return $results;
    }

    private function events(string $connection): CheckResult
    {
        $policy = $this->config->get('quraba-backup.database.event_policy', 'auto');
        if ((bool) $this->config->get('quraba-backup.database.dump_events', false)) {
            $policy = 'required';
        }
        if (! is_string($policy) || ! in_array($policy, ['auto', 'required', 'assume_none'], true)) {
            return CheckResult::fail('database.events', 'Scheduled event protection', 'Invalid database.event_policy.');
        }

        try {
            $name = $this->database->connection($connection)->getDatabaseName();
            $rows = $this->database->connection($connection)->select('SHOW GRANTS');
            foreach ($rows as $row) {
                foreach ((array) $row as $grant) {
                    if (is_string($grant) && MySqlDatabaseDumper::grantIncludesEvents($grant, $name)) {
                        return $policy === 'assume_none'
                            ? CheckResult::warn('database.events', 'Scheduled event protection', 'EVENT privilege is available, but event_policy=assume_none opts out of including events.')
                            : CheckResult::pass('database.events', 'Scheduled event protection', 'EVENT privilege is proven; scheduled events will be included.');
                    }
                }
            }
        } catch (Throwable) {
            // An unreadable grant list cannot prove completeness.
        }

        return $policy === 'required'
            ? CheckResult::fail('database.events', 'Scheduled event protection', 'EVENT privilege cannot be proven; required policy will block database backups.')
            : CheckResult::warn('database.events', 'Scheduled event protection', 'EVENT privilege cannot be proven. Database backups will be marked incomplete unless this is explicitly addressed.');
    }

    private function objectPrivileges(string $connection): CheckResult
    {
        $database = $this->database->connection($connection)->getDatabaseName();
        $proof = $this->dumper->objectPrivileges($connection, $database);
        $missing = array_keys(array_filter($proof, static fn (bool $proven): bool => ! $proven));

        if (! (bool) $this->config->get('quraba-backup.database.dump_routines', true)) {
            $missing[] = 'routines disabled by configuration';
        }

        return $missing === []
            ? CheckResult::pass('database.object_privileges', 'Database object privileges', 'SELECT, SHOW VIEW, TRIGGER and routine visibility are proven by direct grants.')
            : CheckResult::warn('database.object_privileges', 'Database object privileges', 'Could not prove complete dump capability for: '.implode(', ', $missing).'. Dumps will not claim exact object completeness.');
    }

    private function tool(DatabaseToolKind $kind, ServerFlavor $flavor): CheckResult
    {
        $id = 'database.'.$kind->value;
        $label = $kind === DatabaseToolKind::Dumper ? 'Database dump tool' : 'Database client tool';

        try {
            $tool = $this->tools->locate($kind, $flavor);
        } catch (Throwable $exception) {
            return CheckResult::fail($id, $label, $exception->getMessage());
        }

        if ($tool === null) {
            return CheckResult::fail($id, $label, sprintf('None of %s was found. Install the MySQL/MariaDB client tools or set %s.', implode(', ', $kind->candidates($flavor)), strtoupper(str_replace(['quraba-backup.database.', '.'], ['QURABA_BACKUP_DB_', '_'], $kind->configKey()))));
        }

        return CheckResult::pass($id, $label, sprintf('%s at %s (%s)', $tool->name, $tool->path, $tool->version), $tool->toArray());
    }

    private function connectionName(): string
    {
        $configured = $this->config->get('quraba-backup.database.connection');

        return ConfigValue::stringOrNull($configured)
            ?? ConfigValue::stringOrNull($this->config->get('database.default'))
            ?? 'default';
    }
}
