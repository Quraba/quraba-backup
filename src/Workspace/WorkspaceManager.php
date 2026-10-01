<?php

declare(strict_types=1);

namespace Quraba\Backup\Workspace;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Quraba\Backup\Domain\Identifiers;
use Quraba\Backup\Exceptions\ConfigurationException;
use Quraba\Backup\Exceptions\WorkspaceViolation;
use Quraba\Backup\Restore\Journal\RestoreJournalStore;
use Quraba\Backup\Support\PackagePaths;
use Quraba\Backup\Support\PathGuard;
use Quraba\Backup\Support\PrivateFile;
use Symfony\Component\Uid\Ulid;
use Throwable;

/**
 * Creates, lists and (on explicit request) cleans up operation workspaces.
 *
 * Listing never deletes anything. Cleanup of abandoned workspaces only
 * removes a workspace after taking its owner lock itself, which proves that
 * no live process owns it.
 */
final readonly class WorkspaceManager
{
    private const string RESTORE_MARKER = '.restore-uuid';

    public const string DIRECTORY_PREFIX = 'op-';

    public const string DIRECTORY_PATTERN = '/^op-[0-9A-HJKMNP-TV-Z]{26}$/';

    private const string LOCK_PATTERN = '/^op-([0-9A-HJKMNP-TV-Z]{26})\.lock$/';

    public function __construct(
        private PackagePaths $paths,
        private LoggerInterface $logger,
    ) {}

    public function create(): OperationWorkspace
    {
        $base = $this->createdRealBase();
        $id = (string) new Ulid;

        $lockPath = $base.'/'.self::DIRECTORY_PREFIX.$id.'.lock';
        $root = $base.'/'.self::DIRECTORY_PREFIX.$id;

        // The owner lock exists (and is held) before the directory exists, so
        // no other process can ever observe an unowned fresh workspace.
        $lock = @fopen($lockPath, 'x+');

        if ($lock === false) {
            throw new WorkspaceViolation(sprintf('Could not create the owner lock for workspace [%s].', $id));
        }

        if (! @flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            @unlink($lockPath);

            throw new WorkspaceViolation(sprintf('Could not lock the new workspace [%s]; refusing to create an unowned workspace.', $id));
        }

        @chmod($lockPath, 0600);

        if (! @mkdir($root, 0700)) {
            @flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($lockPath);

            throw new WorkspaceViolation(sprintf('Could not create workspace directory [%s].', $root));
        }

        foreach (WorkspaceArea::cases() as $area) {
            if (! @mkdir($root.'/'.$area->value, 0700)) {
                $workspace = new OperationWorkspace($id, $base, $lockPath, $lock);
                $workspace->cleanup();

                throw new WorkspaceViolation(sprintf('Could not create the [%s] area of workspace [%s].', $area->value, $id));
            }
        }

        return new OperationWorkspace($id, $base, $lockPath, $lock);
    }

    /**
     * Runs $operation inside a fresh workspace and always cleans it up.
     *
     * A cleanup failure is logged; it never replaces an exception thrown by
     * the operation and never turns a successful result into a failure.
     *
     * @template T
     *
     * @param  callable(OperationWorkspace): T  $operation
     * @return T
     */
    public function using(callable $operation): mixed
    {
        $workspace = $this->create();

        try {
            return $operation($workspace);
        } finally {
            $report = $workspace->cleanup();

            if (! $report->succeeded()) {
                $this->logger->warning('Quraba Backup workspace cleanup failed.', [
                    'workspace' => $report->workspaceId,
                    'errors' => $report->errors,
                ]);
            }
        }
    }

    /**
     * @return list<WorkspaceInfo>
     */
    public function list(int $abandonedAfterSeconds): array
    {
        $this->assertNonNegative($abandonedAfterSeconds);

        $base = $this->realBase(create: false);

        if ($base === null) {
            return [];
        }

        $now = CarbonImmutable::now('UTC');
        $workspaces = [];

        foreach ($this->directoryEntries($base) as $entry) {
            if (preg_match(self::DIRECTORY_PATTERN, $entry) !== 1 || ! is_dir($base.'/'.$entry) || is_link($base.'/'.$entry)) {
                continue;
            }

            $id = substr($entry, strlen(self::DIRECTORY_PREFIX));
            $createdAt = $this->createdAt($id);
            $age = max(0, $now->getTimestamp() - $createdAt->getTimestamp());
            $active = $this->isActive($base, $id);

            $workspaces[] = new WorkspaceInfo(
                id: $id,
                path: $base.'/'.$entry,
                createdAt: $createdAt,
                ageSeconds: $age,
                active: $active,
                abandoned: ! $active && $age >= $abandonedAfterSeconds,
            );
        }

        usort($workspaces, static fn (WorkspaceInfo $a, WorkspaceInfo $b): int => strcmp($a->id, $b->id));

        return $workspaces;
    }

    /**
     * @return list<WorkspaceInfo>
     */
    public function abandoned(int $abandonedAfterSeconds): array
    {
        return array_values(array_filter(
            $this->list($abandonedAfterSeconds),
            static fn (WorkspaceInfo $workspace): bool => $workspace->abandoned,
        ));
    }

    /**
     * Deletes abandoned workspaces. Each deletion first takes the workspace's
     * owner lock; a workspace whose lock is held is skipped as still active.
     *
     * @return list<CleanupReport>
     */
    public function cleanupAbandoned(int $abandonedAfterSeconds): array
    {
        $base = $this->realBase(create: false);

        if ($base === null) {
            return [];
        }

        $reports = [];

        foreach ($this->abandoned($abandonedAfterSeconds) as $workspace) {
            $reports[] = $this->deleteAbandoned($base, $workspace->id);
        }

        $this->removeOrphanLockFiles($base);

        return $reports;
    }

    public function markRestore(OperationWorkspace $workspace, string $restoreUuid): void
    {
        $restoreUuid = Identifiers::assertUuid($restoreUuid, 'The restore UUID');
        $path = $workspace->root().'/'.self::RESTORE_MARKER;
        $handle = PrivateFile::create($path);
        try {
            if (fwrite($handle, $restoreUuid."\n") !== strlen($restoreUuid) + 1 || ! fflush($handle) || ! fsync($handle)) {
                throw new WorkspaceViolation('The restore workspace marker could not be written durably.');
            }
            PrivateFile::assertStillPrivate($path, $handle);
        } finally {
            fclose($handle);
        }
    }

    /** @return list<array{restore_uuid: string, workspace_id: string, active: bool}> */
    public function retainedRestores(): array
    {
        $retained = [];
        foreach ($this->list(0) as $workspace) {
            $marker = $this->restoreMarker($workspace->path);
            if ($marker !== null) {
                $retained[] = ['restore_uuid' => $marker, 'workspace_id' => $workspace->id, 'active' => $workspace->active];
            }
        }

        return $retained;
    }

    public function cleanupRestore(string $restoreUuid, RestoreJournalStore $journals): CleanupReport
    {
        $restoreUuid = Identifiers::assertUuid($restoreUuid, 'The restore UUID');
        $journal = $journals->find($restoreUuid);
        if ($journal === null || $journal->isUnresolved()) {
            return new CleanupReport($restoreUuid, false, false, ['The restore journal is missing or unresolved; evidence must be retained.']);
        }

        $base = $this->realBase(create: false);
        if ($base === null) {
            return new CleanupReport($restoreUuid, false, true, []);
        }

        $matches = array_values(array_filter($this->list(0), fn (WorkspaceInfo $workspace): bool => $this->restoreMarker($workspace->path) === $restoreUuid));
        if (count($matches) !== 1) {
            return new CleanupReport($restoreUuid, false, count($matches) === 0, count($matches) === 0 ? [] : ['More than one workspace claims this restore UUID.']);
        }

        return $this->deleteAbandoned($base, $matches[0]->id, $restoreUuid);
    }

    public function baseDirectory(): string
    {
        return $this->paths->workspaces;
    }

    private function deleteAbandoned(string $base, string $id, ?string $authorizedRestoreUuid = null): CleanupReport
    {
        $marker = $this->restoreMarker($base.'/'.self::DIRECTORY_PREFIX.$id);
        if ($marker !== null && $marker !== $authorizedRestoreUuid) {
            return new CleanupReport($id, false, false, ['A restore workspace requires exact restore UUID cleanup after its journal is resolved.']);
        }
        $lockPath = $base.'/'.self::DIRECTORY_PREFIX.$id.'.lock';

        if (is_link($lockPath)) {
            return new CleanupReport($id, false, false, ['Owner lock is a symbolic link; refusing to touch this workspace.']);
        }

        // Owners create their lock before the directory and remove it last,
        // so a missing lock means manual tampering: inactivity cannot be proven.
        if (! is_file($lockPath)) {
            return new CleanupReport($id, false, false, ['The owner lock file is missing, so inactivity cannot be proven. Remove this workspace manually after verifying no operation uses it.']);
        }

        $lock = @fopen($lockPath, 'r+');

        if ($lock === false) {
            return new CleanupReport($id, false, false, ['Could not open the workspace owner lock.']);
        }

        if (! @flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return new CleanupReport($id, false, false, ['Workspace became active again; skipped.']);
        }

        try {
            $errors = WorkspaceDeleter::deleteTree($base.'/'.self::DIRECTORY_PREFIX.$id, $base);
        } catch (Throwable $exception) {
            $errors = ['Unexpected cleanup error: '.$exception->getMessage()];
        }

        @flock($lock, LOCK_UN);
        fclose($lock);

        if ($errors === []) {
            @unlink($lockPath);
        }

        return new CleanupReport($id, $errors === [], false, $errors);
    }

    private function restoreMarker(string $root): ?string
    {
        $path = $root.'/'.self::RESTORE_MARKER;
        if (is_link($path)) {
            return 'invalid-marker';
        }
        if (! is_file($path)) {
            return file_exists($path) ? 'invalid-marker' : null;
        }
        $value = @file_get_contents($path, false, null, 0, 64);
        if (! is_string($value) || ! Identifiers::isUuid(trim($value))) {
            return 'invalid-marker';
        }

        return trim($value);
    }

    private function removeOrphanLockFiles(string $base): void
    {
        foreach ($this->directoryEntries($base) as $entry) {
            if (preg_match(self::LOCK_PATTERN, $entry, $matches) !== 1) {
                continue;
            }

            $lockPath = $base.'/'.$entry;

            if (is_link($lockPath) || is_dir($base.'/'.self::DIRECTORY_PREFIX.$matches[1])) {
                continue;
            }

            $lock = @fopen($lockPath, 'r+');

            if ($lock === false) {
                continue;
            }

            $free = @flock($lock, LOCK_EX | LOCK_NB);

            if ($free) {
                @flock($lock, LOCK_UN);
            }

            fclose($lock);

            // A lock without a directory whose owner is gone: the owner died
            // between creating the lock and the directory, or during cleanup.
            if ($free && ! is_dir($base.'/'.self::DIRECTORY_PREFIX.$matches[1])) {
                @unlink($lockPath);
            }
        }
    }

    private function isActive(string $base, string $id): bool
    {
        $lockPath = $base.'/'.self::DIRECTORY_PREFIX.$id.'.lock';

        if (! file_exists($lockPath) || is_link($lockPath)) {
            return false;
        }

        $lock = @fopen($lockPath, 'r+');

        if ($lock === false) {
            // Cannot prove it is inactive: treat as active (never delete).
            return true;
        }

        try {
            if (@flock($lock, LOCK_EX | LOCK_NB)) {
                flock($lock, LOCK_UN);

                return false;
            }

            return true;
        } finally {
            fclose($lock);
        }
    }

    private function createdAt(string $id): CarbonImmutable
    {
        try {
            return CarbonImmutable::instance(Ulid::fromString($id)->getDateTime())->utc();
        } catch (Throwable) {
            return CarbonImmutable::createFromTimestampUTC(0);
        }
    }

    /**
     * @return list<string>
     */
    private function directoryEntries(string $base): array
    {
        $entries = @scandir($base);

        return $entries === false ? [] : $entries;
    }

    private function createdRealBase(): string
    {
        try {
            PackagePaths::ensureDirectory($this->paths->workspaces);
        } catch (ConfigurationException $exception) {
            throw new WorkspaceViolation($exception->getMessage());
        }

        return $this->realBase(create: true) ?? throw new WorkspaceViolation('The workspace directory could not be created.');
    }

    private function realBase(bool $create = false): ?string
    {
        $base = $this->paths->workspaces;

        if (is_link($base)) {
            throw new WorkspaceViolation(sprintf('The workspace directory [%s] must not be a symbolic link.', $base));
        }

        if (! $create && ! is_dir($base)) {
            return null;
        }

        $real = PathGuard::real($base);

        if ($real === null) {
            throw new WorkspaceViolation(sprintf('The workspace directory [%s] cannot be resolved.', $base));
        }

        return $real;
    }

    private function assertNonNegative(int $seconds): void
    {
        if ($seconds < 0) {
            throw new InvalidArgumentException('The abandonment threshold must not be negative.');
        }
    }
}
