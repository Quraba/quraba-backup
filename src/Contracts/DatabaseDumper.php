<?php

declare(strict_types=1);

namespace Quraba\Backup\Contracts;

use Quraba\Backup\Archive\Database\DatabaseDump;
use Quraba\Backup\Exceptions\ArchiveCreationFailed;
use Quraba\Backup\Workspace\OperationWorkspace;

/**
 * Dumps the application database into an operation workspace.
 */
interface DatabaseDumper
{
    public function connectionName(): string;

    /**
     * @throws ArchiveCreationFailed
     */
    public function dump(OperationWorkspace $workspace): DatabaseDump;
}
