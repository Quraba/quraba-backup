<?php

declare(strict_types=1);

namespace Quraba\Backup\Restic;

use Closure;
use Quraba\Backup\Exceptions\ProcessExecutionFailed;
use Quraba\Backup\Exceptions\ResticCommandFailed;
use Quraba\Backup\Exceptions\ResticUnavailable;
use Quraba\Backup\Exceptions\ResticVersionMismatch;
use Quraba\Backup\Support\PathGuard;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Chooses the Restic binary deliberately:
 *
 *   1. an explicitly configured binary — if it fails verification the
 *      resolution fails; it never silently falls back to something else;
 *   2. the package-managed binary — same rule when it exists;
 *   3. a system `restic` from PATH, only when explicitly allowed.
 *
 * PATH alone is never trusted: every candidate must be a regular executable
 * file that reports exactly the pinned version. Execution of the version
 * probe is delegated back to the ResticRunner, the single process boundary.
 */
final readonly class ResticBinaryResolver
{
    public function __construct(
        private ResticConfig $config,
        private ExecutableFinder $finder = new ExecutableFinder,
    ) {}

    /**
     * @param  Closure(string): ResticVersionInfo  $probe
     */
    public function resolve(Closure $probe): ResticBinary
    {
        if ($this->config->configuredBinary !== null) {
            return $this->verify($this->config->configuredBinary, BinarySource::Configured, $probe);
        }

        $managed = $this->config->managedBinary;

        if (file_exists($managed) || is_link($managed)) {
            return $this->verify($managed, BinarySource::Managed, $probe);
        }

        if ($this->config->allowSystemBinary) {
            $system = $this->finder->find('restic');

            if ($system !== null) {
                $real = realpath($system);

                return $this->verify($real === false ? $system : $real, BinarySource::System, $probe);
            }
        }

        throw new ResticUnavailable(sprintf(
            'No Restic binary is available. Run "php artisan backup:install-restic" to install the pinned Restic %s into [%s].',
            $this->config->version,
            $managed,
        ));
    }

    /**
     * @param  Closure(string): ResticVersionInfo  $probe
     */
    public function verify(string $path, BinarySource $source, Closure $probe): ResticBinary
    {
        if ($source === BinarySource::Managed && is_link($path)) {
            throw new ResticUnavailable(sprintf('The managed Restic binary [%s] is a symbolic link; the installer only ever writes a regular file. Reinstall with "php artisan backup:install-restic --force".', $path));
        }

        if (! is_file($path)) {
            throw new ResticUnavailable(sprintf('The %s Restic binary [%s] does not exist or is not a regular file.', $source->value, $path));
        }

        // Windows has no executable bit; executability is proven by the probe.
        if (! PathGuard::isWindows() && ! is_executable($path)) {
            throw new ResticUnavailable(sprintf('The %s Restic binary [%s] is not executable (chmod 0700).', $source->value, $path));
        }

        try {
            $version = $probe($path);
        } catch (ProcessExecutionFailed|ResticCommandFailed $exception) {
            throw new ResticUnavailable(sprintf('The %s Restic binary [%s] could not be verified: %s', $source->value, $path, $exception->getMessage()));
        }

        if (! $version->matches($this->config->version)) {
            throw new ResticVersionMismatch(sprintf(
                'The %s Restic binary [%s] reports version %s, but this package is pinned to %s.%s',
                $source->value,
                $path,
                $version->version,
                $this->config->version,
                $source === BinarySource::Managed ? ' Run "php artisan backup:install-restic --force" to install the pinned version.' : '',
            ));
        }

        return new ResticBinary($path, $source, $version);
    }
}
