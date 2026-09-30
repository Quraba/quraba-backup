<?php

declare(strict_types=1);

namespace Quraba\Backup\Support\Process;

use InvalidArgumentException;
use Symfony\Component\Process\Process;

final class SymfonyProcessFactory implements ProcessFactory
{
    public function make(array $command, ?string $workingDirectory, #[\SensitiveParameter] array $environment, float $timeoutSeconds): Process
    {
        if ($timeoutSeconds <= 0) {
            throw new InvalidArgumentException('Process timeouts must be positive; unlimited execution is not allowed.');
        }

        foreach ($command as $argument) {
            if (str_contains($argument, "\0")) {
                throw new InvalidArgumentException('Process arguments must not contain NUL bytes.');
            }
        }

        return new Process($command, $workingDirectory, $environment, null, $timeoutSeconds);
    }
}
