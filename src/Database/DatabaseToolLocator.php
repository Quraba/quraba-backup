<?php

declare(strict_types=1);

namespace Quraba\Backup\Database;

use Illuminate\Contracts\Config\Repository;
use Quraba\Backup\Exceptions\ConfigurationException;
use Quraba\Backup\Support\ConfigValue;
use Quraba\Backup\Support\PathGuard;
use Quraba\Backup\Support\Process\ChildEnvironment;
use Quraba\Backup\Support\Process\ProcessFactory;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

/**
 * Finds the MySQL/MariaDB dump and client executables.
 *
 * An explicitly configured path must work (no silent fallback). Otherwise
 * candidates are searched in PATH plus common hosting locations and each is
 * verified by running `--version` with a minimal environment and no
 * database credentials at all.
 */
final readonly class DatabaseToolLocator
{
    public function __construct(
        private Repository $config,
        private ProcessFactory $processes,
        private ExecutableFinder $finder = new ExecutableFinder,
    ) {}

    public function dumper(ServerFlavor $flavor = ServerFlavor::Unknown): ?DatabaseTool
    {
        return $this->locate(DatabaseToolKind::Dumper, $flavor);
    }

    public function client(ServerFlavor $flavor = ServerFlavor::Unknown): ?DatabaseTool
    {
        return $this->locate(DatabaseToolKind::Client, $flavor);
    }

    public function locate(DatabaseToolKind $kind, ServerFlavor $flavor): ?DatabaseTool
    {
        $configured = $this->config->get($kind->configKey());

        if (is_string($configured) && trim($configured) !== '') {
            $path = trim($configured);

            if (! PathGuard::isAbsolute($path) || ! is_file($path)) {
                throw new ConfigurationException(sprintf('[%s] must point to an existing executable file.', $kind->configKey()));
            }

            $version = $this->probe($path);

            if ($version === null) {
                throw new ConfigurationException(sprintf('The configured %s [%s] could not be executed.', $kind->value, $path));
            }

            return new DatabaseTool($kind, basename($path), $path, $version, true);
        }

        $searchPaths = array_values(array_filter(
            (array) $this->config->get('quraba-backup.database.tool_search_paths', []),
            static fn (mixed $path): bool => is_string($path) && $path !== '',
        ));

        foreach ($kind->candidates($flavor) as $name) {
            $path = $this->finder->find($name, null, $searchPaths);

            if ($path === null) {
                continue;
            }

            $version = $this->probe($path);

            if ($version !== null) {
                return new DatabaseTool($kind, $name, $path, $version, false);
            }
        }

        return null;
    }

    private function probe(string $path): ?string
    {
        $timeout = ConfigValue::positiveInt($this->config->get('quraba-backup.timeouts.tool_probe', 20), 'quraba-backup.timeouts.tool_probe');

        try {
            $process = $this->processes->make([$path, '--version'], null, ChildEnvironment::build(), (float) $timeout);
            $process->run();
        } catch (Throwable) {
            return null;
        }

        if ($process->getExitCode() !== 0) {
            return null;
        }

        $line = trim(strtok($process->getOutput(), "\n") ?: '');

        return $line === '' ? null : mb_substr($line, 0, 200);
    }
}
