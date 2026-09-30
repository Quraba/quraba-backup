<?php

declare(strict_types=1);

namespace Quraba\Backup\Health\Doctor\Checks;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
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
            } catch (Throwable $exception) {
                $results[] = CheckResult::fail('database.connection', 'Database connection', 'Could not connect to the application database: '.$exception->getMessage());
            }
        }

        foreach ([DatabaseToolKind::Dumper, DatabaseToolKind::Client] as $kind) {
            $results[] = $this->tool($kind, $flavor);
        }

        return $results;
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
