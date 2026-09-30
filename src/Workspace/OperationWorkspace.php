<?php

declare(strict_types=1);

namespace Quraba\Backup\Workspace;

use Quraba\Backup\Exceptions\WorkspaceViolation;
use Quraba\Backup\Support\PathGuard;

/**
 * A private directory owned by exactly one operation: `op-{ULID}`.
 *
 * Ownership is proven by an exclusive flock on the sibling `op-{ULID}.lock`
 * file, held for the lifetime of this object. Other processes can therefore
 * tell an active workspace from an abandoned one without trusting timestamps,
 * and a crashed owner releases it automatically.
 *
 * The workspace only ever deletes its own tree.
 */
final class OperationWorkspace
{
    /** @var resource|null */
    private $ownerLock;

    private ?CleanupReport $cleanup = null;

    /**
     * @param  resource  $ownerLock
     *
     * @internal created by {@see WorkspaceManager::create()}
     */
    public function __construct(
        public readonly string $id,
        private readonly string $realBase,
        private readonly string $lockPath,
        $ownerLock,
    ) {
        $this->ownerLock = $ownerLock;
    }

    public function root(): string
    {
        return $this->realBase.'/'.WorkspaceManager::DIRECTORY_PREFIX.$this->id;
    }

    public function area(WorkspaceArea $area): string
    {
        $this->assertUsable();

        return $this->root().'/'.$area->value;
    }

    /**
     * A contained path inside an area. The relative part is package-generated,
     * never user input; it is still validated against traversal and symlinks.
     */
    public function path(WorkspaceArea $area, string $relative): string
    {
        $relative = PathGuard::assertSafeRelative($relative);
        $candidate = $this->area($area).'/'.$relative;

        $this->assertContained($candidate);

        return $candidate;
    }

    /**
     * Creates (0700) and returns a contained directory inside an area.
     */
    public function directory(WorkspaceArea $area, string $relative): string
    {
        $path = $this->path($area, $relative);

        if (! is_dir($path) && ! @mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new WorkspaceViolation(sprintf('Could not create workspace directory [%s].', $path));
        }

        $this->assertContained($path);

        return $path;
    }

    public function isCleanedUp(): bool
    {
        return $this->cleanup !== null;
    }

    /**
     * Removes this workspace. Idempotent and never throws: the caller decides
     * how to surface a failure without masking an earlier primary error.
     */
    public function cleanup(): CleanupReport
    {
        if ($this->cleanup !== null) {
            return new CleanupReport($this->id, removed: false, alreadyAbsent: true, errors: $this->cleanup->errors);
        }

        $existed = is_dir($this->root());
        $errors = WorkspaceDeleter::deleteTree($this->root(), $this->realBase);

        $this->releaseOwnership($errors === []);

        return $this->cleanup = new CleanupReport($this->id, removed: $existed && $errors === [], alreadyAbsent: ! $existed, errors: $errors);
    }

    public function __destruct()
    {
        // Keep the directory for diagnosis if the owner never cleaned up, but
        // release the lock so it is reported as abandoned rather than active.
        if ($this->ownerLock !== null) {
            @flock($this->ownerLock, LOCK_UN);
            @fclose($this->ownerLock);
            $this->ownerLock = null;
        }
    }

    private function releaseOwnership(bool $removeLockFile): void
    {
        if ($this->ownerLock !== null) {
            @flock($this->ownerLock, LOCK_UN);
            @fclose($this->ownerLock);
            $this->ownerLock = null;
        }

        if ($removeLockFile && ! is_link($this->lockPath)) {
            @unlink($this->lockPath);
        }
    }

    private function assertUsable(): void
    {
        if ($this->cleanup !== null) {
            throw new WorkspaceViolation(sprintf('Workspace [%s] has already been cleaned up.', $this->id));
        }
    }

    /**
     * The deepest existing ancestor of $path (the path itself when it exists)
     * must really resolve inside the workspace root. This rejects symlinks
     * placed anywhere along the path that point outside.
     */
    private function assertContained(string $path): void
    {
        $existing = PathGuard::nearestExistingAncestor($path);

        if ($existing === null) {
            throw new WorkspaceViolation(sprintf('Path [%s] has no existing ancestor.', $path));
        }

        PathGuard::assertRealWithin($existing, $this->root());

        if (is_link($path)) {
            throw new WorkspaceViolation(sprintf('Path [%s] is a symbolic link; workspace paths must be real entries.', $path));
        }
    }
}
