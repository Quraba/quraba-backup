<?php

declare(strict_types=1);

namespace Quraba\Backup\Restore;

use Quraba\Backup\Exceptions\RestoreFailed;
use Quraba\Backup\Identity\ApplicationIdentity;
use Quraba\Backup\Media\MediaRootResolver;
use Quraba\Backup\Restic\RepositoryIdentityGuard;
use Quraba\Backup\Restic\ResticRepository;
use Quraba\Backup\Restic\ResticRunner;
use Quraba\Backup\Restic\SnapshotIdentity;
use Quraba\Backup\Restic\SnapshotKind;
use Quraba\Backup\Support\PathGuard;
use Quraba\Backup\Workspace\OperationWorkspace;
use Quraba\Backup\Workspace\WorkspaceArea;

/** Restores an exact full snapshot ID only under OperationWorkspace::Restore. */
final readonly class ResticReconstructor
{
    public function __construct(
        private ResticRepository $repository,
        private RepositoryIdentityGuard $identityGuard,
        private ResticRunner $runner,
        private MediaRootResolver $roots,
    ) {}

    /** @return list<MediaRootMapping> */
    public function reconstruct(RestoreSource $source, ApplicationIdentity $identity, OperationWorkspace $workspace): array
    {
        if ($source->snapshotId === null || $source->repositoryId === null) {
            throw RestoreFailed::sourceUnavailable('no verified media snapshot');
        }

        $this->assertRepository($source->repositoryId);
        $kind = SnapshotKind::tryFrom((string) $source->snapshotKind);
        if ($kind === null || $kind === SnapshotKind::SafetyMedia) {
            throw RestoreFailed::sourceConflict('unknown snapshot kind');
        }
        $expected = SnapshotIdentity::for($identity, $kind, $source->runUuid);
        $snapshot = null;
        foreach ($this->repository->snapshots(SnapshotIdentity::applicationSelector($identity)) as $listed) {
            if ($listed->id === $source->snapshotId) {
                $snapshot = $listed;
                break;
            }
        }
        if ($snapshot === null || ! $expected->matches($snapshot)) {
            throw RestoreFailed::sourceUnavailable('the exact full snapshot ID and its application tags cannot be proven');
        }

        $expectedPaths = array_column($source->mediaRoots, 'path');
        $actualPaths = $snapshot->paths;
        sort($expectedPaths);
        sort($actualPaths);
        if ($expectedPaths === [] || $expectedPaths !== $actualPaths) {
            throw RestoreFailed::sourceConflict('snapshot paths differ from the frozen manifest roots');
        }

        $this->runner->restore($source->snapshotId, $workspace)->throwIfFailed();
        $this->assertRepository($source->repositoryId);
        $again = $this->repository->snapshots([], [$source->snapshotId]);
        if (count($again) !== 1 || $again[0]->id !== $source->snapshotId || ! $expected->matches($again[0])) {
            throw RestoreFailed::reconstructionFailed('the exact snapshot identity changed during reconstruction');
        }

        return $this->mapRoots($source, $workspace);
    }

    private function assertRepository(string $frozenId): void
    {
        $inspection = $this->repository->inspect();
        if (! $inspection->state->isReady() || $inspection->repositoryId === null || ! hash_equals($frozenId, $inspection->repositoryId)) {
            throw RestoreFailed::sourceConflict('the open Restic repository differs from the frozen repository ID');
        }

        $local = $this->identityGuard->expected();
        if ($local !== null && ! hash_equals($local->repository_id, $frozenId)) {
            throw RestoreFailed::sourceConflict('the local immutable repository identity differs from the frozen source');
        }
    }

    /** @return list<MediaRootMapping> */
    private function mapRoots(RestoreSource $source, OperationWorkspace $workspace): array
    {
        $configured = [];
        foreach ($this->roots->resolve() as $root) {
            $configured[$root->name] = $root->path;
        }

        if (count($configured) !== count($source->mediaRoots)) {
            throw RestoreFailed::mappingFailed('configured and snapshotted root counts differ');
        }

        $target = $workspace->path(WorkspaceArea::Restore, 'snapshot-'.substr((string) $source->snapshotId, 0, 16));
        $seen = [];
        $mappings = [];
        foreach ($source->mediaRoots as $root) {
            $name = $root['name'];
            $path = $root['path'];
            if (isset($seen[$name]) || ! isset($configured[$name])
                || ! PathGuard::isAbsolute($path) || str_contains($path, '/../') || str_ends_with($path, '/..')) {
                throw RestoreFailed::mappingFailed('missing, duplicate or unsafe logical root');
            }
            $seen[$name] = true;
            $relative = ltrim(str_replace('\\', '/', $path), '/');
            $relative = preg_replace('~^([A-Za-z]):/~', '$1/', $relative);
            if (! is_string($relative)) {
                throw RestoreFailed::mappingFailed('invalid restored path');
            }
            $relative = PathGuard::assertSafeRelative($relative);
            $subtree = $target.'/'.$relative;
            if (! is_dir($subtree) || is_link($subtree)) {
                throw RestoreFailed::mappingFailed('an expected restored root is missing or is a link');
            }
            PathGuard::assertRealWithin($subtree, $target);
            $mappings[] = new MediaRootMapping($name, $path, $subtree, $configured[$name]);
        }

        return $mappings;
    }
}
