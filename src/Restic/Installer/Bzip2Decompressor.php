<?php

declare(strict_types=1);

namespace Quraba\Backup\Restic\Installer;

use Quraba\Backup\Exceptions\EnvironmentUnsupported;
use Quraba\Backup\Exceptions\ResticInstallationFailed;
use Quraba\Backup\Support\Process\ChildEnvironment;
use Quraba\Backup\Support\Process\ProcessFactory;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Decompresses the (already checksum-verified) Restic .bz2 release with a
 * hard output size limit. Uses ext-bz2 when available, otherwise a system
 * `bzip2 -dc` executed with an argument array (no shell).
 */
final readonly class Bzip2Decompressor
{
    private const int CHUNK = 1048576;

    private const array SEARCH_DIRECTORIES = ['/usr/bin', '/bin', '/usr/local/bin'];

    public function __construct(
        private ProcessFactory $processes,
        private ExecutableFinder $finder = new ExecutableFinder,
        private bool $allowExtension = true,
    ) {}

    public function isAvailable(): bool
    {
        return $this->canUseExtension() || $this->findBinary() !== null;
    }

    public function method(): ?string
    {
        return $this->canUseExtension() ? 'ext-bz2' : ($this->findBinary() !== null ? 'bzip2 binary' : null);
    }

    public function decompress(string $source, string $destination, int $maxBytes): void
    {
        if ($this->canUseExtension()) {
            $this->withExtension($source, $destination, $maxBytes);

            return;
        }

        $binary = $this->findBinary();

        if ($binary === null) {
            throw new EnvironmentUnsupported('Neither the PHP bz2 extension nor a bzip2 binary is available to decompress the Restic release. Enable ext-bz2 or install bzip2.');
        }

        $this->withBinary($binary, $source, $destination, $maxBytes);
    }

    private function canUseExtension(): bool
    {
        return $this->allowExtension && function_exists('bzopen');
    }

    private function findBinary(): ?string
    {
        return $this->finder->find('bzip2', null, self::SEARCH_DIRECTORIES);
    }

    private function withExtension(string $source, string $destination, int $maxBytes): void
    {
        $input = @bzopen($source, 'r');

        if ($input === false) {
            throw new ResticInstallationFailed('Could not open the downloaded archive for decompression.');
        }

        $output = $this->openDestination($destination);
        $written = 0;

        try {
            while (true) {
                $chunk = bzread($input, self::CHUNK);

                if ($chunk === false) {
                    throw new ResticInstallationFailed('The Restic archive is corrupt (bzip2 read error).');
                }

                // libbzip2 error codes are negative; positive values (e.g.
                // BZ_STREAM_END) are normal progress states.
                $error = bzerror($input);

                if (($error['errno'] ?? 0) < 0) {
                    throw new ResticInstallationFailed('The Restic archive is corrupt: '.($error['errstr'] ?? 'bzip2 error'));
                }

                if ($chunk === '') {
                    break;
                }

                $written += strlen($chunk);

                if ($written > $maxBytes) {
                    throw new ResticInstallationFailed('The decompressed Restic binary exceeds the configured size limit.');
                }

                if (fwrite($output, $chunk) !== strlen($chunk)) {
                    throw new ResticInstallationFailed('Could not write the decompressed Restic binary.');
                }
            }
        } finally {
            bzclose($input);
            fclose($output);
        }

        if ($written === 0) {
            throw new ResticInstallationFailed('The Restic archive decompressed to an empty file.');
        }
    }

    private function withBinary(string $binary, string $source, string $destination, int $maxBytes): void
    {
        $process = $this->processes->make([$binary, '-dc', $source], dirname($destination), ChildEnvironment::build(), 300.0);
        $output = $this->openDestination($destination);
        $written = 0;

        try {
            $process->start();

            foreach ($process->getIterator(Process::ITER_SKIP_ERR) as $chunk) {
                $written += strlen($chunk);

                if ($written > $maxBytes) {
                    $process->stop(0);

                    throw new ResticInstallationFailed('The decompressed Restic binary exceeds the configured size limit.');
                }

                if (fwrite($output, $chunk) !== strlen($chunk)) {
                    $process->stop(0);

                    throw new ResticInstallationFailed('Could not write the decompressed Restic binary.');
                }
            }

            $process->wait();
        } catch (ResticInstallationFailed $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ResticInstallationFailed('bzip2 failed or timed out while decompressing the Restic archive.');
        } finally {
            fclose($output);
        }

        if ($process->getExitCode() !== 0 || $written === 0) {
            throw new ResticInstallationFailed('bzip2 could not decompress the Restic archive.');
        }
    }

    /**
     * @return resource
     */
    private function openDestination(string $destination)
    {
        $output = @fopen($destination, 'xb');

        if ($output === false) {
            throw new ResticInstallationFailed('Could not create the staged Restic binary file.');
        }

        return $output;
    }
}
