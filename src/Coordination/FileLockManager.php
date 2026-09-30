<?php

declare(strict_types=1);

namespace Quraba\Backup\Coordination;

use Carbon\CarbonImmutable;
use Quraba\Backup\Contracts\LockManager;
use Quraba\Backup\Exceptions\ConfigurationException;
use Quraba\Backup\Exceptions\LockUnavailable;
use Quraba\Backup\Exceptions\OperationBusy;
use Quraba\Backup\Support\PackagePaths;
use Throwable;

/**
 * OS-level advisory locks (flock) in the package-private lock directory.
 *
 * - works across separate PHP CLI/FPM processes on one host;
 * - the kernel releases the lock when the owning process dies, so a crashed
 *   backup never leaves a stale package lock behind;
 * - needs no Redis, database, queue or daemon;
 * - fails closed: if a lock cannot be opened or flock() errors, the
 *   operation is refused rather than run unprotected.
 *
 * Lock files are never deleted (deleting a lock file that another process
 * has open would let two processes "hold" the same lock).
 */
final readonly class FileLockManager implements LockManager
{
    public function __construct(private PackagePaths $paths) {}

    public function acquire(LockName $name, string $purpose): LockHandle
    {
        $path = $this->pathFor($name);
        $handle = $this->open($path);

        $wouldBlock = 0;
        $locked = @flock($handle, LOCK_EX | LOCK_NB, $wouldBlock);

        if (! $locked) {
            fclose($handle);

            if ($wouldBlock === 1 || $this->isHeldByProbe($path)) {
                throw new OperationBusy(sprintf(
                    'Another Quraba Backup operation holds the [%s] lock%s. Only one write-affecting operation may run at a time; retry after it finishes.',
                    $name->value,
                    $this->describeHolder($path),
                ));
            }

            throw new LockUnavailable(sprintf('flock() failed on [%s]; the filesystem may not support process locks. Refusing to run unprotected.', $path));
        }

        $acquiredAt = CarbonImmutable::now('UTC');
        $this->writeOwner($handle, $purpose, $acquiredAt);

        return new LockHandle($name, $path, $purpose, $acquiredAt, $handle);
    }

    public function isHeldElsewhere(LockName $name): bool
    {
        // Read-only: never create the lock directory or file just to probe it.
        $path = $this->paths->locks.'/'.$name->fileName();

        if (! is_file($path)) {
            return false;
        }

        return $this->isHeldByProbe($path);
    }

    /**
     * Probes an arbitrary lock file path with a non-blocking exclusive lock
     * that is released immediately. Used by doctor probes.
     */
    public function isHeldByProbe(string $path): bool
    {
        $handle = $this->open($path);

        try {
            $wouldBlock = 0;

            if (@flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
                flock($handle, LOCK_UN);

                return false;
            }

            if ($wouldBlock === 1 || PHP_OS_FAMILY === 'Windows') {
                return true;
            }

            throw new LockUnavailable(sprintf('flock() failed while probing [%s].', $path));
        } finally {
            fclose($handle);
        }
    }

    public function directory(): string
    {
        try {
            return PackagePaths::ensureDirectory($this->paths->locks);
        } catch (ConfigurationException $exception) {
            throw new LockUnavailable($exception->getMessage());
        }
    }

    private function pathFor(LockName $name): string
    {
        return $this->directory().'/'.$name->fileName();
    }

    /**
     * @return resource
     */
    private function open(string $path)
    {
        if (is_link($path)) {
            throw new LockUnavailable(sprintf('Lock file [%s] is a symbolic link; refusing to use it.', $path));
        }

        $handle = @fopen($path, 'c+');

        if ($handle === false) {
            throw new LockUnavailable(sprintf('Could not open lock file [%s]; check permissions of the lock directory.', $path));
        }

        @chmod($path, 0600);

        return $handle;
    }

    /**
     * @param  resource  $handle
     */
    private function writeOwner($handle, string $purpose, CarbonImmutable $acquiredAt): void
    {
        $owner = json_encode([
            'pid' => getmypid(),
            'purpose' => mb_substr($purpose, 0, 200),
            'acquired_at' => $acquiredAt->toIso8601ZuluString(),
        ]);

        try {
            @ftruncate($handle, 0);
            @rewind($handle);
            @fwrite($handle, (string) $owner);
            @fflush($handle);
        } catch (Throwable) {
            // Owner information is diagnostic only; the kernel lock is what matters.
        }
    }

    private function describeHolder(string $path): string
    {
        // On Windows the file is mandatorily locked and cannot be read.
        $contents = @file_get_contents($path, false, null, 0, 1024);

        if (! is_string($contents) || $contents === '') {
            return '';
        }

        $owner = json_decode($contents, true);

        if (! is_array($owner)) {
            return '';
        }

        $pid = $owner['pid'] ?? null;
        $purpose = $owner['purpose'] ?? null;
        $since = $owner['acquired_at'] ?? null;

        return sprintf(
            ' (pid %s, %s, since %s)',
            is_int($pid) ? (string) $pid : '?',
            is_string($purpose) ? $purpose : 'unknown purpose',
            is_string($since) ? $since : '?',
        );
    }
}
