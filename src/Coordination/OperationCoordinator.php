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

    /**
     * Restore PREPARATION (dry run): only the restore lock. Preparation reads
     * backups into private workspaces and never changes the live
     * application, so it must not block backups or application writes; it
     * only excludes other restore preparation (and, later, live restores,
     * which will take the global AND restore locks). Retention protects the
     * sources of unresolved restores, so a concurrent retention pass cannot
     * remove the backup being reconstructed.
     */
    public function beginRestorePreparation(string $purpose): HeldLocks
    {
        return new HeldLocks([$this->locks->acquire(LockName::Restore, $purpose)]);
    }

    /**
     * LIVE restore: the global write lock AND the restore lock, held through
     * the whole destructive lifecycle. No backup, retention, check, prune,
     * reconciliation, dry run or other restore can overlap it; read-only
     * health needs no lock and keeps working.
     */
    public function beginLiveRestore(string $purpose): HeldLocks
    {
        return $this->beginWriteOperation($purpose, LockName::Restore);
    }

    public function isRestorePreparationRunning(): bool
    {
        return $this->locks->isHeldElsewhere(LockName::Restore);
    }

    public function isWriteOperationRunning(): bool
    {
        return $this->locks->isHeldElsewhere(LockName::GlobalOperation);
    }
}
