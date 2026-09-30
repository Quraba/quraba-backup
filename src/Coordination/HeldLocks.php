<?php

declare(strict_types=1);

namespace Quraba\Backup\Coordination;

/**
 * Locks acquired together; released together in reverse order.
 */
final class HeldLocks
{
    /**
     * @param  list<LockHandle>  $handles
     */
    public function __construct(private array $handles) {}

    public function holds(LockName $name): bool
    {
        foreach ($this->handles as $handle) {
            if ($handle->name === $name && ! $handle->isReleased()) {
                return true;
            }
        }

        return false;
    }

    public function release(): void
    {
        foreach (array_reverse($this->handles) as $handle) {
            $handle->release();
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
