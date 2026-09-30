<?php

declare(strict_types=1);

namespace Quraba\Backup\Coordination;

use Carbon\CarbonImmutable;

/**
 * An acquired OS-level lock. Released explicitly, on destruction, or by the
 * kernel when the owning process dies.
 */
final class LockHandle
{
    /** @var resource|null */
    private $handle;

    /**
     * @param  resource  $handle
     */
    public function __construct(
        public readonly LockName $name,
        public readonly string $path,
        public readonly string $purpose,
        public readonly CarbonImmutable $acquiredAt,
        $handle,
    ) {
        $this->handle = $handle;
    }

    public function isReleased(): bool
    {
        return $this->handle === null;
    }

    /**
     * Idempotent. Lock files are intentionally never deleted: unlinking a
     * lock file while another process waits on it would split the lock.
     */
    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }

        $handle = $this->handle;
        $this->handle = null;

        @ftruncate($handle, 0);
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }

    public function __destruct()
    {
        $this->release();
    }
}
