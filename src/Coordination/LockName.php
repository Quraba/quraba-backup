<?php

declare(strict_types=1);

namespace Quraba\Backup\Coordination;

enum LockName: string
{
    /** Held by every write-affecting operation (v1: one at a time). */
    case GlobalOperation = 'global-operation';

    /** Additionally held by restores. */
    case Restore = 'restore';

    /** Additionally held by retention, check, prune and reconciliation. */
    case Maintenance = 'maintenance';

    /** Serializes installation of the managed Restic binary. */
    case ResticInstaller = 'restic-installer';

    public function fileName(): string
    {
        return $this->value.'.lock';
    }
}
