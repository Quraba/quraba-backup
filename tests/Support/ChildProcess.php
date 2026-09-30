<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Starts tests/Fixtures/hold-lock.php in a separate PHP process and waits
 * until it reports its state.
 */
final class ChildProcess
{
    public static function start(string $privateRoot, string $argument, string $mode = 'lock'): array
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__).'/Fixtures/hold-lock.php', $privateRoot, $argument, $mode], null, null, null, 120);
        $process->start();

        $deadline = microtime(true) + 30;

        while (microtime(true) < $deadline) {
            $output = $process->getOutput();

            if (str_contains($output, "\n")) {
                return [$process, trim($output)];
            }

            if (! $process->isRunning()) {
                throw new RuntimeException('Child process exited early: '.$process->getErrorOutput().$process->getOutput());
            }

            usleep(20000);
        }

        $process->stop(0);

        throw new RuntimeException('Child process did not report in time.');
    }

    public static function kill(Process $process): void
    {
        $process->stop(0);

        $deadline = microtime(true) + 10;
        while ($process->isRunning() && microtime(true) < $deadline) {
            usleep(20000);
        }
    }
}
