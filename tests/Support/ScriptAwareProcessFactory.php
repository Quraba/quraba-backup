<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Support;

use Quraba\Backup\Support\Process\ProcessFactory;
use Quraba\Backup\Support\Process\SymfonyProcessFactory;
use Symfony\Component\Process\Process;

/**
 * Test double around the real factory: a "binary" that is a PHP script
 * (starts with `#!/usr/bin/env php`) is run through the current PHP binary,
 * so the fake Restic works identically on Linux CI and Windows machines.
 * Everything else (argument arrays, environment, timeouts) is untouched and
 * goes through the production factory.
 */
final class ScriptAwareProcessFactory implements ProcessFactory
{
    /** @var list<list<string>> */
    public array $commands = [];

    public function make(array $command, ?string $workingDirectory, #[\SensitiveParameter] array $environment, float $timeoutSeconds): Process
    {
        $this->commands[] = $command;

        if (is_file($command[0]) && str_starts_with((string) file_get_contents($command[0], false, null, 0, 32), '#!/usr/bin/env php')) {
            $command = [PHP_BINARY, ...$command];
        }

        return (new SymfonyProcessFactory)->make($command, $workingDirectory, $environment, $timeoutSeconds);
    }
}
