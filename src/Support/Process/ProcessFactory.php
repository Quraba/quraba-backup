<?php

declare(strict_types=1);

namespace Quraba\Backup\Support\Process;

use Symfony\Component\Process\Process;

/**
 * Creates Symfony processes from argument arrays. There is deliberately no
 * way to create a process from a shell command string.
 */
interface ProcessFactory
{
    /**
     * @param  non-empty-list<string>  $command  executable followed by its arguments
     * @param  array<string, string|false>  $environment
     */
    public function make(array $command, ?string $workingDirectory, #[\SensitiveParameter] array $environment, float $timeoutSeconds): Process;
}
