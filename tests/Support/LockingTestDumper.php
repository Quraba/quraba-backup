<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Support;

use Quraba\Backup\Archive\Database\DatabaseDump;
use Quraba\Backup\Contracts\DatabaseDumper;
use Quraba\Backup\Workspace\OperationWorkspace;
use Quraba\Backup\Workspace\WorkspaceArea;

/**
 * Dumps normally, but leaves a read-only directory in the workspace so that
 * workspace cleanup fails (POSIX only), to prove cleanup failures are
 * warnings that never change a verified backup's status.
 */
final class LockingTestDumper implements DatabaseDumper
{
    /** @var list<string> */
    public array $locked = [];

    /** @var list<resource|false> */
    private array $handles = [];

    public function __construct(private readonly SqliteTestDumper $inner) {}

    public function connectionName(): string
    {
        return $this->inner->connectionName();
    }

    public function dump(OperationWorkspace $workspace): DatabaseDump
    {
        $directory = $workspace->directory(WorkspaceArea::Temp, 'undeletable');
        file_put_contents($directory.'/pinned', 'x');

        if (PHP_OS_FAMILY === 'Windows') {
            // An open handle makes the file undeletable on Windows.
            $this->handles[] = fopen($directory.'/pinned', 'rb');
        } else {
            chmod($directory, 0500);
        }

        $this->locked[] = $directory;

        return $this->inner->dump($workspace);
    }

    public function unlock(): void
    {
        foreach ($this->handles as $handle) {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        foreach ($this->locked as $directory) {
            @chmod($directory, 0700);
        }
    }
}
