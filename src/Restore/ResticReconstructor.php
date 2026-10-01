<?php

declare(strict_types=1);

namespace Quraba\Backup\Restore;

use Closure;
use Quraba\Backup\Exceptions\RestoreFailed;
use Quraba\Backup\Identity\ApplicationIdentity;
use Quraba\Backup\Media\MediaDestination;
use Quraba\Backup\Media\MediaRootResolver;
use Quraba\Backup\Restic\RepositoryIdentityGuard;
use Quraba\Backup\Restic\ResticRepository;
use Quraba\Backup\Restic\ResticRunner;
use Quraba\Backup\Restic\SnapshotIdentity;
use Quraba\Backup\Restic\SnapshotKind;
use Quraba\Backup\Restore\Live\MediaStagingArea;
use Quraba\Backup\Support\LocalCatalog;
use Quraba\Backup\Support\PathGuard;
use Quraba\Backup\Workspace\OperationWorkspace;
use Quraba\Backup\Workspace\WorkspaceArea;

/**
 * Reconstructs an exact full snapshot ID in private staging — never in a
 * live media root — and maps each logical root to its destination.
 *
 * A dry run stages inside the operation workspace. A live restore passes a
 * staging plan (see Live\MediaStaging) so each root is staged on the
 * filesystem of its destination; the identity checks before and after the
 * Restic restore are the same in both cases.
 */
final readonly class ResticReconstructor
{
    public function __construct(
        private ResticRepository $repository,
        private RepositoryIdentityGuard $identityGuard,
        private ResticRunner $runner,
        private MediaRootResolver $roots,
        private LocalCatalog $catalog,
    ) {}

    /**
     * @param  (Closure(list<MediaDestination>, list<string>): list<MediaStagingArea>)|null  $staging  live restores: plans staging for the restored destinations (second argument: every configured root path)
     * @return list<MediaRootMapping>
     */
    public function reconstruct(RestoreSource $source, ApplicationIdentity $identity, OperationWorkspace $workspace, ?Closure $staging = null): array
    {
        if ($source->snapshotId === null || $source->repositoryId === null) {
            throw RestoreFailed::sourceUnavailable('no verified media snapshot');
        }

        $this->assertRepository($source->repositoryId);
        $this->assertSnapshot($source, $identity, listed: true);

        $destinations = $this->destinations($source);
        $staged = [];

        // Every root is restored on its own: the root directory itself and
        // its content, without any of its ancestors.
        if ($staging === null) {
            $base = $workspace->path(WorkspaceArea::Restore, 'snapshot-'.substr($source->snapshotId, 0, 16));

            foreach ($source->mediaRoots as $root) {
                $this->runner->restoreRoot($source->snapshotId, $root['path'], $workspace, $root['name'])->throwIfFailed();
                $staged[$root['name']] = $base.'/'.$root['name'];
            }
        } else {
            $areas = $staging(array_values($destinations), array_map(static fn (MediaDestination $d): string => $d->path, $this->roots->destinations()));

            foreach ($areas as $area) {
                foreach ($area->roots as $name) {
                    $this->runner->restoreRootToStaging($source->snapshotId, $this->snapshotPath($source, $name), $area, $name)->throwIfFailed();
                    $staged[$name] = $area->target.'/'.$name;
                }
            }
        }

        $this->assertRepository($source->repositoryId);
        $this->assertSnapshot($source, $identity, listed: false);

        $mappings = [];

        foreach ($source->mediaRoots as $root) {
            $name = $root['name'];
            $base = $staged[$name] ?? throw RestoreFailed::mappingFailed(sprintf('media root [%s] was not staged', $name));
            $subtree = $base.'/'.basename(ResticRunner::snapshotPath($root['path']));

            if (! is_dir($subtree) || is_link($subtree)) {
                throw RestoreFailed::mappingFailed('an expected restored root is missing or is a link');
            }

            PathGuard::assertRealWithin($subtree, $base);
            $destination = $destinations[$name];
            $mappings[] = new MediaRootMapping($name, $root['path'], $subtree, $destination->path, $destination->exists, $destination->allowSymlinks);
        }

        return $mappings;
    }

    /**
     * The open repository must be the frozen one, before and after.
     */
    public function assertRepository(string $frozenId): void
    {
        $inspection = $this->repository->inspect();
        if (! $inspection->state->isReady() || $inspection->repositoryId === null || ! hash_equals($frozenId, $inspection->repositoryId)) {
            throw RestoreFailed::sourceConflict('the open Restic repository differs from the frozen repository ID');
        }

        // A clean host has no local identity record yet; the frozen ID of
        // the immutable manifest is then the only authority.
        $local = $this->catalog->available() ? $this->identityGuard->expected() : null;
        if ($local !== null && ! hash_equals($local->repository_id, $frozenId)) {
            throw RestoreFailed::sourceConflict('the local immutable repository identity differs from the frozen source');
        }
    }

    /**
     * The exact full snapshot ID must exist with this run's application
     * tags and cover exactly the frozen roots.
     */
    public function assertSnapshot(RestoreSource $source, ApplicationIdentity $identity, bool $listed): void
    {
        $kind = SnapshotKind::tryFrom((string) $source->snapshotKind);
        if ($kind === null || $kind === SnapshotKind::SafetyMedia || $source->snapshotId === null) {
            throw RestoreFailed::sourceConflict('unknown snapshot kind');
        }

        $expected = SnapshotIdentity::for($identity, $kind, $source->runUuid);
        $snapshot = null;

        // Presence is proven from the application listing; an exact-ID query
        // (Restic ignores tag filters next to IDs) re-reads it afterwards.
        foreach ($listed ? $this->repository->snapshots(SnapshotIdentity::applicationSelector($identity)) : $this->repository->snapshots([], [$source->snapshotId]) as $candidate) {
            if ($candidate->id === $source->snapshotId) {
                $snapshot = $candidate;
                break;
            }
        }

        if ($snapshot === null || ! $expected->matches($snapshot)) {
            throw $listed
                ? RestoreFailed::sourceUnavailable('the exact full snapshot ID and its application tags cannot be proven')
                : RestoreFailed::reconstructionFailed('the exact snapshot identity changed during reconstruction');
        }

        // Restic reports Windows paths with backslashes; compare like the backup did.
        $expectedPaths = array_map(PathGuard::comparable(...), array_column($source->mediaRoots, 'path'));
        $actualPaths = array_map(PathGuard::comparable(...), $snapshot->paths);
        sort($expectedPaths);
        sort($actualPaths);
        if ($expectedPaths === [] || $expectedPaths !== $actualPaths) {
            throw RestoreFailed::sourceConflict('snapshot paths differ from the frozen manifest roots');
        }
    }

    /**
     * Every snapshot root must map, by its stable logical name, to exactly
     * one configured destination; every configured non-optional root must
     * be part of the snapshot.
     *
     * @return array<string, MediaDestination> keyed by root name, in snapshot order
     */
    private function destinations(RestoreSource $source): array
    {
        $configured = [];
        foreach ($this->roots->destinations() as $destination) {
            $configured[$destination->name] = $destination;
        }

        $mapped = [];
        foreach ($source->mediaRoots as $root) {
            $name = $root['name'];
            $path = $root['path'];
            if (isset($mapped[$name]) || ! isset($configured[$name])
                || ! PathGuard::isAbsolute($path) || str_contains($path, '/../') || str_ends_with($path, '/..')) {
                throw RestoreFailed::mappingFailed('missing, duplicate or unsafe logical root');
            }
            $mapped[$name] = $configured[$name];
        }

        foreach ($configured as $name => $destination) {
            if (! isset($mapped[$name]) && ! $destination->optional) {
                throw RestoreFailed::mappingFailed(sprintf('configured media root [%s] is not part of the snapshot (mark it optional to leave it untouched)', $name));
            }
        }

        return $mapped;
    }

    private function snapshotPath(RestoreSource $source, string $name): string
    {
        foreach ($source->mediaRoots as $root) {
            if ($root['name'] === $name) {
                return $root['path'];
            }
        }

        throw RestoreFailed::mappingFailed(sprintf('media root [%s] is not part of the snapshot', $name));
    }
}
