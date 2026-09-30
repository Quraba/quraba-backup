<?php

declare(strict_types=1);

namespace Quraba\Backup\Backup;

use Carbon\CarbonImmutable;
use Psr\Log\LoggerInterface;
use Quraba\Backup\Coordination\LockName;
use Quraba\Backup\Coordination\OperationCoordinator;
use Quraba\Backup\Domain\FailureDetails;
use Quraba\Backup\Enums\ArtifactKind;
use Quraba\Backup\Enums\ArtifactStatus;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Enums\BackupStatus;
use Quraba\Backup\Enums\MaintenanceOperation;
use Quraba\Backup\Exceptions\QurabaBackupException;
use Quraba\Backup\Identity\ApplicationIdentity;
use Quraba\Backup\Identity\IdentityResolver;
use Quraba\Backup\Models\BackupArtifact;
use Quraba\Backup\Models\BackupMaintenanceRun;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Restic\ResticRepository;
use Quraba\Backup\Restic\SnapshotIdentity;
use Quraba\Backup\Restic\SnapshotKind;
use Quraba\Backup\Security\SecretRedactor;
use Quraba\Backup\Workspace\OperationWorkspace;
use Quraba\Backup\Workspace\WorkspaceManager;
use Throwable;

/**
 * Resolves interrupted backup runs from physical evidence.
 *
 * Holding the global operation lock proves no backup is running (one
 * write-affecting operation at a time), so every run still preflighting,
 * running, verifying or indeterminate was interrupted. For each:
 *
 *  - archive: the object at the run's deterministic path is downloaded,
 *    decrypted and proven to be this run's archive, then adopted; absent → failed;
 *  - media: the run's exact snapshot identity is searched; one proven match
 *    is adopted (never re-created), none → failed (unless Restic locks show a
 *    process may still be writing), several/conflicting → stays indeterminate;
 *  - a missing manifest is written (identical content is adopted, a
 *    different one is a collision and never overwritten);
 *  - the terminal state follows the same rules as a live run.
 *
 * Every pass is audited as a `reconciliation` maintenance run.
 */
final readonly class BackupReconciler
{
    private const array OPEN_STATUSES = [BackupStatus::Preflighting, BackupStatus::Running, BackupStatus::Verifying, BackupStatus::Indeterminate];

    public function __construct(
        private OperationCoordinator $coordinator,
        private WorkspaceManager $workspaces,
        private IdentityResolver $identities,
        private ApplicationArchiveService $archives,
        private MediaSnapshotService $media,
        private ResticRepository $repository,
        private RunFinalizer $finalizer,
        private SecretRedactor $redactor,
        private LoggerInterface $logger,
    ) {}

    public function reconcile(bool $dryRun = false): ReconciliationReport
    {
        $identity = $this->identities->current();
        $locks = $this->coordinator->beginWriteOperation('backup reconciliation', LockName::Maintenance);

        try {
            $runs = BackupRun::query()
                ->whereIn('status', array_map(static fn (BackupStatus $s): string => $s->value, self::OPEN_STATUSES))
                ->orderBy('id')
                ->get();

            $audit = BackupMaintenanceRun::plan(MaintenanceOperation::Reconciliation, $dryRun, array_values($runs->map(static fn (BackupRun $run): array => [
                'run_uuid' => $run->uuid,
                'status' => $run->status->value,
            ])->all()));
            $audit->markRunning();

            $items = [];

            try {
                foreach ($runs as $run) {
                    $items[] = $dryRun ? $this->inspect($run, $identity) : $this->reconcileRun($run, $identity);
                }
            } catch (Throwable $exception) {
                $audit->markFailed(FailureDetails::fromThrowable($exception, 'reconcile', $this->redactor));

                throw $exception;
            }

            $audit->markCompleted($dryRun ? [] : array_values(array_filter($items, static fn (array $item): bool => $item['before'] !== $item['after'])));

            return new ReconciliationReport($audit->uuid, $dryRun, $items);
        } finally {
            $locks->release();
        }
    }

    /**
     * @return array{run_uuid: string, profile: string, before: string, after: string, components: array<string, array<string, mixed>>}
     */
    private function reconcileRun(BackupRun $run, ApplicationIdentity $identity): array
    {
        $before = $run->status;

        if ($run->status === BackupStatus::Preflighting) {
            $run->markFailed(FailureDetails::make('backup.interrupted', 'preflight', 'The run was interrupted during preflight; no artifact was produced.', $this->redactor));

            return $this->item($run, $before, []);
        }

        $workspace = $this->workspaces->create();
        $components = [];

        try {
            foreach (RunFinalizer::requiredKinds($run->profile) as $kind) {
                $components[$kind->value] = $kind === ArtifactKind::ApplicationArchive
                    ? $this->reconcileArchive($run->refresh(), $identity, $workspace)
                    : $this->reconcileMedia($run->refresh(), $identity);
            }

            $this->finalizer->finalize($run->refresh(), $identity, $components, [
                'reconciled_at' => CarbonImmutable::now('UTC')->toIso8601ZuluString(),
                'components' => array_map(static fn (ComponentOutcome $c): array => $c->toArray(), $components),
            ]);
        } finally {
            $report = $workspace->cleanup();

            if (! $report->succeeded()) {
                $this->logger->warning('Quraba Backup reconciliation workspace cleanup failed.', ['errors' => $report->errors]);
            }
        }

        return $this->item($run->refresh(), $before, $components);
    }

    private function reconcileArchive(BackupRun $run, ApplicationIdentity $identity, OperationWorkspace $workspace): ComponentOutcome
    {
        $artifact = $this->artifact($run, ArtifactKind::ApplicationArchive);

        if ($artifact !== null && ($known = $this->known($artifact)) !== null) {
            return $known;
        }

        $artifact ??= $run->addArtifact(ArtifactKind::ApplicationArchive);

        try {
            $adopted = $this->archives->adoptRemote($run, $artifact, $identity, $workspace);
        } catch (Throwable $exception) {
            return ComponentOutcome::uncertain(FailureDetails::fromThrowable($exception, 'reconcile.archive', $this->redactor));
        }

        if ($adopted !== null) {
            return $adopted;
        }

        $failure = FailureDetails::make('archive.missing_after_interruption', 'reconcile.archive', 'No archive exists at this run\'s deterministic remote path.', $this->redactor);
        $artifact->refresh()->markFailed($failure);

        return ComponentOutcome::failed($failure);
    }

    private function reconcileMedia(BackupRun $run, ApplicationIdentity $identity): ComponentOutcome
    {
        $artifact = $this->artifact($run, ArtifactKind::ResticSnapshot);

        if ($artifact !== null && ($known = $this->known($artifact)) !== null) {
            return $known;
        }

        $artifact ??= $run->addArtifact(ArtifactKind::ResticSnapshot);
        $kind = $run->profile === BackupProfile::Recovery ? SnapshotKind::RecoveryMedia : SnapshotKind::Media;

        try {
            $existing = $this->media->findRunSnapshot(SnapshotIdentity::for($identity, $kind, $run->uuid), $artifact);

            if ($existing === null) {
                if ($this->repository->lockIds() !== []) {
                    return ComponentOutcome::uncertain(FailureDetails::make('restic.snapshot_uncertain', 'reconcile.media', 'No snapshot yet, but Restic locks are present: a backup process may still be writing. Retry reconciliation later.', $this->redactor));
                }

                $failure = FailureDetails::make('restic.snapshot_missing_after_interruption', 'reconcile.media', 'No snapshot carries this run\'s identity.', $this->redactor);
                $artifact->refresh()->markFailed($failure);

                return ComponentOutcome::failed($failure);
            }

            $result = $this->media->snapshot($run, $artifact->refresh(), $kind, allowCreate: false);

            return ComponentOutcome::verified($result->snapshotId);
        } catch (Throwable $exception) {
            return ComponentOutcome::uncertain(FailureDetails::fromThrowable($exception, 'reconcile.media', $this->redactor));
        }
    }

    /**
     * Terminal artifacts are already settled facts.
     */
    private function known(BackupArtifact $artifact): ?ComponentOutcome
    {
        return match ($artifact->status) {
            ArtifactStatus::Verified => ComponentOutcome::verified((string) ($artifact->snapshot_id ?? $artifact->locator)),
            ArtifactStatus::Failed => ComponentOutcome::failed(FailureDetails::make(
                $artifact->failure_code ?? 'artifact.failed',
                'reconcile',
                $artifact->failure_message ?? 'The component failed.',
                $this->redactor,
            )),
            default => null,
        };
    }

    /**
     * Read-only view of what reconciliation would find.
     *
     * @return array{run_uuid: string, profile: string, before: string, after: string, components: array<string, array<string, mixed>>}
     */
    private function inspect(BackupRun $run, ApplicationIdentity $identity): array
    {
        $components = [];

        foreach (RunFinalizer::requiredKinds($run->profile) as $kind) {
            $artifact = $this->artifact($run, $kind);

            try {
                $evidence = $kind === ArtifactKind::ApplicationArchive
                    ? ['remote_object_exists' => $this->archives->remoteExists($run)]
                    : ['matching_snapshot' => $this->media->findRunSnapshot(SnapshotIdentity::for($identity, $run->profile === BackupProfile::Recovery ? SnapshotKind::RecoveryMedia : SnapshotKind::Media, $run->uuid), $artifact)?->id];
            } catch (QurabaBackupException $exception) {
                $evidence = ['error' => $this->redactor->redact($exception->getMessage())];
            }

            $components[$kind->value] = ['catalog_status' => $artifact?->status->value ?? 'missing', ...$evidence];
        }

        return [
            'run_uuid' => $run->uuid,
            'profile' => $run->profile->value,
            'before' => $run->status->value,
            'after' => $run->status->value,
            'components' => $components,
        ];
    }

    private function artifact(BackupRun $run, ArtifactKind $kind): ?BackupArtifact
    {
        return $run->artifacts()->where('kind', $kind->value)->first();
    }

    /**
     * @param  array<string, ComponentOutcome>  $components
     * @return array{run_uuid: string, profile: string, before: string, after: string, components: array<string, array<string, mixed>>}
     */
    private function item(BackupRun $run, BackupStatus $before, array $components): array
    {
        return [
            'run_uuid' => $run->uuid,
            'profile' => $run->profile->value,
            'before' => $before->value,
            'after' => $run->status->value,
            'components' => array_map(static fn (ComponentOutcome $c): array => $c->toArray(), $components),
        ];
    }
}
