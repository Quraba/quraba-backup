<?php

declare(strict_types=1);

namespace Quraba\Backup\Backup;

use Quraba\Backup\Domain\Identifiers;
use Quraba\Backup\Enums\ArtifactKind;
use Quraba\Backup\Enums\ArtifactStatus;
use Quraba\Backup\Exceptions\IllegalStateTransition;
use Quraba\Backup\Exceptions\ProcessExecutionFailed;
use Quraba\Backup\Exceptions\QurabaBackupException;
use Quraba\Backup\Exceptions\RepositoryIdentityMismatch;
use Quraba\Backup\Exceptions\ResticSnapshotFailed;
use Quraba\Backup\Identity\IdentityResolver;
use Quraba\Backup\Media\MediaRoot;
use Quraba\Backup\Media\MediaRootResolver;
use Quraba\Backup\Models\BackupArtifact;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Restic\RepositoryIdentityGuard;
use Quraba\Backup\Restic\ResticBackupRequest;
use Quraba\Backup\Restic\ResticErrorClassifier;
use Quraba\Backup\Restic\ResticRepository;
use Quraba\Backup\Restic\ResticResult;
use Quraba\Backup\Restic\ResticRunner;
use Quraba\Backup\Restic\ResticSnapshot;
use Quraba\Backup\Restic\SnapshotIdentity;
use Quraba\Backup\Restic\SnapshotKind;

/**
 * Creates or adopts the Restic media snapshot of one run and proves it.
 *
 *   validate roots → verify repository identity → search this run identity
 *   → 0: create · 1: adopt · >1: refuse (ambiguous)
 *   → exact full ID → re-read that exact snapshot → verify repository and
 *     app/env/kind/run identity → only then mark the artifact verified.
 *
 * A retry after a crash (snapshot created, catalog not updated) finds the
 * existing snapshot by its run identity and adopts it: no second snapshot is
 * ever created for the same run.
 */
final readonly class MediaSnapshotService
{
    public function __construct(
        private ResticRunner $runner,
        private ResticRepository $repository,
        private MediaRootResolver $roots,
        private RepositoryIdentityGuard $repositoryIdentity,
        private IdentityResolver $identity,
    ) {}

    /**
     * @return list<MediaRoot>
     */
    public function roots(): array
    {
        return $this->roots->resolve();
    }

    /**
     * @param  bool  $allowCreate  false for reconciliation: adopt an existing snapshot only, never create one
     */
    public function snapshot(BackupRun $run, BackupArtifact $artifact, SnapshotKind $kind, bool $allowCreate = true): MediaSnapshotResult
    {
        if ($artifact->kind !== ArtifactKind::ResticSnapshot || $artifact->backup_run_id !== $run->id) {
            throw new IllegalStateTransition('The artifact does not belong to this run\'s media snapshot.');
        }

        $roots = $this->roots->resolve();
        $repositoryId = $this->repositoryIdentity->verifyOpenRepository();
        $run->mergeMetadata(['restic_repository_id' => $repositoryId]);

        $identity = SnapshotIdentity::for($this->identity->current(), $kind, $run->uuid);
        $existing = $this->findRunSnapshot($identity, $artifact);

        if ($artifact->currentStatus() === ArtifactStatus::Pending && $existing === null && $allowCreate) {
            $artifact->markCreating();
        }

        if ($existing !== null) {
            $snapshotId = $existing->id;
            $adopted = true;
        } elseif (! $allowCreate) {
            throw new ResticSnapshotFailed(sprintf('No snapshot exists for run %s and creation is not allowed here.', $run->uuid));
        } else {
            $snapshotId = $this->create($identity, $roots, $artifact);
            $adopted = false;
        }

        $snapshot = $this->prove($identity, $snapshotId, $roots, $repositoryId);

        if ($artifact->currentStatus() !== ArtifactStatus::Verifying) {
            $artifact->markVerifying();
        }

        $artifact->markVerifiedSnapshot($snapshot->id, [
            'kind' => $kind->value,
            'roots' => array_map(static fn (MediaRoot $root): array => $root->toArray(), $roots),
            'repository_id' => $repositoryId,
            'adopted' => $adopted,
        ]);

        return new MediaSnapshotResult($snapshot->id, $repositoryId, $adopted);
    }

    /**
     * The single snapshot carrying this exact run identity, or null.
     *
     * @throws ResticSnapshotFailed when claims conflict or are ambiguous
     */
    public function findRunSnapshot(SnapshotIdentity $identity, ?BackupArtifact $artifact = null): ?ResticSnapshot
    {
        // AND filter: quraba-backup + run:{uuid} (single comma-joined --tag).
        $claims = $this->repository->snapshots($identity->runSelector());
        $exact = array_values(array_filter($claims, static fn (ResticSnapshot $snapshot): bool => $identity->matches($snapshot)));

        if (count($claims) !== count($exact)) {
            throw ResticSnapshotFailed::identityMismatch(sprintf('a snapshot claims run %s with a different app, environment or kind', $identity->runUuid));
        }

        if (count($exact) > 1) {
            throw ResticSnapshotFailed::ambiguous(sprintf('%d snapshots carry run %s: %s', count($exact), $identity->runUuid, implode(', ', array_map(static fn (ResticSnapshot $s): string => $s->id, $exact))));
        }

        $snapshot = $exact[0] ?? null;
        $incomplete = $artifact?->metadata['incomplete_snapshot_id'] ?? null;

        if ($snapshot !== null && $snapshot->id === $incomplete) {
            throw ResticSnapshotFailed::incomplete('the only snapshot of this run was recorded as incomplete and is never adopted');
        }

        return $snapshot;
    }

    /**
     * @param  list<MediaRoot>  $roots
     */
    private function create(SnapshotIdentity $identity, array $roots, BackupArtifact $artifact): string
    {
        $request = ResticBackupRequest::make(
            array_map(static fn (MediaRoot $root): string => $root->path, $roots),
            $identity->tags(),
            $identity->host(),
        );

        try {
            $result = $this->runner->backup($request);
        } catch (ProcessExecutionFailed $exception) {
            // Timeout or launch failure: a snapshot may or may not exist.
            throw $this->afterFailure($identity, $exception->getMessage());
        }

        if ($result->exitCode === ResticResult::EXIT_INCOMPLETE) {
            $partialId = $this->snapshotIdFrom($result);

            if ($partialId !== null) {
                $artifact->mergeMetadata(['incomplete_snapshot_id' => $partialId]);
            }

            throw ResticSnapshotFailed::incomplete($result->stderr);
        }

        if (! $result->successful()) {
            throw $this->afterFailure($identity, $result->stderr, $result);
        }

        return $this->snapshotIdFrom($result)
            ?? throw $this->afterFailure($identity, 'restic reported success without a full snapshot ID');
    }

    /**
     * Re-reads the exact snapshot and proves identity and repository.
     *
     * @param  list<MediaRoot>  $roots
     */
    private function prove(SnapshotIdentity $identity, string $snapshotId, array $roots, string $repositoryId): ResticSnapshot
    {
        // Exact ID only (Restic ignores tag filters next to IDs); the full
        // identity is checked below on the returned document.
        $matches = $this->repository->snapshots([], [$snapshotId]);

        if (count($matches) !== 1 || $matches[0]->id !== $snapshotId || ! $identity->matches($matches[0])) {
            throw ResticSnapshotFailed::identityMismatch(sprintf('snapshot %s could not be re-read with the exact expected identity', $snapshotId));
        }

        $expectedPaths = array_map(static fn (MediaRoot $root): string => self::normalizePath($root->path), $roots);
        $actualPaths = array_map(self::normalizePath(...), $matches[0]->paths);
        sort($expectedPaths);
        sort($actualPaths);

        if ($expectedPaths !== $actualPaths) {
            throw ResticSnapshotFailed::identityMismatch(sprintf('snapshot %s covers different paths than the configured media roots', $snapshotId));
        }

        $current = $this->repository->inspect();

        if ($current->repositoryId === null || ! hash_equals($repositoryId, $current->repositoryId)) {
            throw new RepositoryIdentityMismatch('The repository changed while the snapshot was being verified.');
        }

        return $matches[0];
    }

    private function snapshotIdFrom(ResticResult $result): ?string
    {
        try {
            $id = $result->summary()['snapshot_id'] ?? null;
        } catch (QurabaBackupException) {
            return null;
        }

        return is_string($id) && Identifiers::isFullSnapshotId($id) ? $id : null;
    }

    private function afterFailure(SnapshotIdentity $identity, string $detail, ?ResticResult $result = null): QurabaBackupException
    {
        try {
            $existing = $this->findRunSnapshot($identity);
        } catch (QurabaBackupException $exception) {
            return $exception;
        }

        if ($existing !== null) {
            return ResticSnapshotFailed::uncertain(sprintf('restic failed but snapshot %s exists for this run: %s', $existing->id, $detail));
        }

        if ($result !== null) {
            $classified = $result->successful() ? null : ResticErrorClassifier::classify($result);

            return new ResticSnapshotFailed(sprintf('No snapshot was created: %s', $classified?->getMessage() ?? $detail));
        }

        return new ResticSnapshotFailed('No snapshot was created: '.$detail);
    }

    private static function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return preg_match('~^[A-Za-z]:~', $path) === 1 ? strtoupper($path[0]).substr($path, 1) : $path;
    }
}
