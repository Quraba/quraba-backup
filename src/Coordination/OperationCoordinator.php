<?php

declare(strict_types=1);

namespace Quraba\Backup\Coordination;

use InvalidArgumentException;
use Quraba\Backup\Contracts\LockManager;
use Throwable;

/**
 * Applies the conservative v1 rule: only one write-affecting Quraba Backup
 * operation at a time.
 *
 * Every write-affecting operation takes the global operation lock first;
 * restores and maintenance additionally take their class lock, so later
 * phases can relax the global rule without changing callers. Read-only
 * inspection (health, doctor, listing) takes no locks.
 */
final readonly class OperationCoordinator
{
    public function __construct(private LockManager $locks) {}

    public function beginWriteOperation(string $purpose, ?LockName $class = null): HeldLocks
    {
        if ($class === LockName::GlobalOperation || $class === LockName::ResticInstaller) {
            throw new InvalidArgumentException('The operation class lock must be Restore or Maintenance.');
        }

        $global = $this->locks->acquire(LockName::GlobalOperation, $purpose);

        if ($class === null) {
            return new HeldLocks([$global]);
        }

        try {
            $specific = $this->locks->acquire($class, $purpose);
        } catch (Throwable $exception) {
            $global->release();

            throw $exception;
        }

        return new HeldLocks([$global, $specific]);
    }

    public function isWriteOperationRunning(): bool
    {
        return $this->locks->isHeldElsewhere(LockName::GlobalOperation);
    }
}
