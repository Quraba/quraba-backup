<?php

declare(strict_types=1);

namespace Quraba\Backup\Contracts;

use Quraba\Backup\Coordination\LockHandle;
use Quraba\Backup\Coordination\LockName;
use Quraba\Backup\Exceptions\LockUnavailable;
use Quraba\Backup\Exceptions\OperationBusy;

interface LockManager
{
    /**
     * Acquires an exclusive lock without waiting.
     *
     * @throws OperationBusy when another process holds the lock
     * @throws LockUnavailable when locking cannot be established (fail closed)
     */
    public function acquire(LockName $name, string $purpose): LockHandle;

    /**
     * Whether another process currently holds the lock. Read-only probe; it
     * never blocks and never keeps the lock.
     *
     * @throws LockUnavailable
     */
    public function isHeldElsewhere(LockName $name): bool;
}
