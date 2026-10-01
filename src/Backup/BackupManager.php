<?php

declare(strict_types=1);

namespace Quraba\Backup\Backup;

use Closure;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Quraba\Backup\Consistency\QuiescenceSession;
use Quraba\Backup\Contracts\QuiescenceProvider;
use Quraba\Backup\Coordination\HeldLocks;
use Quraba\Backup\Coordination\LockName;
use Quraba\Backup\Coordination\OperationCoordinator;
use Quraba\Backup\Domain\FailureDetails;
use Quraba\Backup\Domain\Identifiers;
use Quraba\Backup\Enums\ArtifactKind;
use Quraba\Backup\Enums\ArtifactStatus;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Enums\BackupStatus;
use Quraba\Backup\Enums\BackupTrigger;
use Quraba\Backup\Enums\ConsistencyLevel;
use Quraba\Backup\Exceptions\QuiescenceFailed;
use Quraba\Backup\Exceptions\QurabaBackupException;
use Quraba\Backup\Identity\ApplicationIdentity;
use Quraba\Backup\Identity\IdentityResolver;
use Quraba\Backup\Models\BackupArtifact;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Restic\RepositoryIdentityGuard;
use Quraba\Backup\Restic\ResticRunner;
use Quraba\Backup\Restic\SnapshotKind;
use Quraba\Backup\Security\SecretRedactor;
use Quraba\Backup\Support\PackageVersion;
use Quraba\Backup\Workspace\OperationWorkspace;
use Quraba\Backup\Workspace\WorkspaceManager;
use Throwable;

/**
 * Top-level backup orchestration. It owns the run (UUID, preflight, locks,
 * profile sequencing, component states, terminal state, manifest, cleanup,
 * sanitized failures) and delegates every engine detail: archives to the
 * ApplicationArchiveService (Spatie behind the ArchiveEngine boundary),
 * media to the MediaSnapshotService (Restic through the ResticRunner).
 *
 * Recovery Point order (sequential, v1):
 *   preflight → enter quiescence → archive → verify → upload/prove
 *   → media snapshot → exact verification → leave quiescence (finally)
 *   → immutable manifest → terminal catalog state.
 */
final readonly class BackupManager
{
    public function __construct(
        private OperationCoordinator $coordinator,
        private WorkspaceManager $workspaces,
        private IdentityResolver $identities,
        private ApplicationArchiveService $archives,
        private MediaSnapshotService $media,
        private RepositoryIdentityGuard $repositoryIdentity,
        private ResticRunner $restic,
        private RunFinalizer $finalizer,
        private QuiescenceProvider $quiescence,
        private Repository $config,
        private SecretRedactor $redactor,
        private LoggerInterface $logger,
    ) {}

    public function run(BackupProfile $profile, BackupTrigger $trigger = BackupTrigger::Manual): BackupRunResult
    {
        // Identity is validated before anything is recorded.
        $identity = $this->identities->current();

        // One write-affecting operation at a time; a busy lock creates no run.
        $locks = $this->coordinator->beginWriteOperation('backup '.$profile->value);

        try {
            $run = BackupRun::request($profile, $trigger, ConsistencyLevel::None, [
                'package_version' => PackageVersion::current(),
                'app_id' => $identity->appId,
                'environment' => $identity->environment,
            ]);

            return $this->execute($run, $identity);
        } finally {
            $locks->release();
        }
    }

    /**
     * The verified pre-change safety backup of a LIVE restore.
     *
     * It runs through exactly the same pipeline as every other backup, with
     * three differences: it runs under the locks the restore already holds
     * (the restore owns the global and restore locks for its whole
     * destructive lifecycle), its trigger is `pre_restore` with the restore
     * UUID in its metadata, and it is pinned the moment it is requested so
     * retention can never remove it while the restore is unresolved.
     *
     * @param  Closure(BackupRun): void  $announce  called with the requested run BEFORE it executes (the restore journal records it)
     */
    public function runSafetyBackup(BackupProfile $profile, HeldLocks $locks, string $restoreUuid, Closure $announce): BackupRunResult
    {
        if (! $locks->holds(LockName::GlobalOperation) || ! $locks->holds(LockName::Restore)) {
            throw new InvalidArgumentException('A safety backup may only run under the global and restore locks of its live restore.');
        }

        $identity = $this->identities->current();

        $run = BackupRun::request($profile, BackupTrigger::PreRestore, ConsistencyLevel::None, [
            'package_version' => PackageVersion::current(),
            'app_id' => $identity->appId,
            'environment' => $identity->environment,
            'restore_uuid' => Identifiers::assertUuid($restoreUuid, 'The restore UUID'),
            'safety_backup' => true,
        ]);
        $run->pin(BackupRun::safetyPinHorizon(), 'Pre-change safety backup of restore '.$restoreUuid.'; protected while that restore is unresolved.');
        $announce($run);

        return $this->execute($run, $identity);
    }

    private function execute(BackupRun $run, ApplicationIdentity $identity): BackupRunResult
    {
        $run->markPreflighting();

        try {
            $this->preflight($run->profile);
        } catch (Throwable $exception) {
            $run->markFailed(FailureDetails::fromThrowable($exception, 'preflight', $this->redactor));

            return new BackupRunResult($run->refresh(), BackupStatus::Failed, [], [], null);
        }

        $run->markRunning();
        $warnings = [];
        $components = [];
        $releaseFailed = false;
        $workspace = null;

        try {
            $workspace = $this->workspaces->create();
            $session = null;

            try {
                if ($run->profile === BackupProfile::Recovery) {
                    // Assigned before anything can throw, so the finally block
                    // below always releases it through one logged path.
                    $session = $this->quiescence->enter();
                    $this->recordQuiescence($run, $session);
                }

                if ($run->profile !== BackupProfile::Media) {
                    $components[ArtifactKind::ApplicationArchive->value] = $this->archives->create(
                        $run,
                        $run->addArtifact(ArtifactKind::ApplicationArchive),
                        $identity,
                        $workspace,
                    );
                }

                if ($run->profile !== BackupProfile::Database) {
                    $components[ArtifactKind::ResticSnapshot->value] = $this->mediaComponent($run);
                }

                if ($session !== null) {
                    $this->confirmQuiescence($run, $session, $warnings);
                }
            } finally {
                if ($session !== null) {
                    $releaseFailed = ! $this->leaveQuiescence($run, $session, $warnings);
                }
            }

            $finalized = $this->finalizer->finalize($run->refresh(), $identity, $components);
        } catch (Throwable $exception) {
            // Unexpected failure outside the component boundaries.
            $finalized = $this->abort($run, $exception);
        } finally {
            if ($workspace instanceof OperationWorkspace) {
                $report = $workspace->cleanup();

                if (! $report->succeeded()) {
                    $warnings[] = 'Workspace cleanup failed: '.implode(' ', $report->errors);
                    $this->logger->warning('Quraba Backup workspace cleanup failed.', ['run_uuid' => $run->uuid, 'errors' => $report->errors]);
                }
            }
        }

        if ($warnings !== []) {
            $run->refresh()->mergeMetadata(['warnings' => $warnings]);
        }

        return new BackupRunResult($run->refresh(), $finalized->status, $components, $warnings, $finalized->manifestLocator, $releaseFailed);
    }

    /**
     * Fails fast, before any data is produced.
     */
    private function preflight(BackupProfile $profile): void
    {
        if ($profile !== BackupProfile::Media) {
            $this->archives->assertReady();
        }

        if ($profile !== BackupProfile::Database) {
            $this->media->roots();
            $this->restic->binary();
            $this->repositoryIdentity->verifyOpenRepository();
        }

        if ($profile === BackupProfile::Recovery && $this->requiresQuiesced() && ! $this->allowsDowngrade() && $this->quiescence->name() === 'none') {
            throw new QuiescenceFailed('A quiesced Recovery Point is required, but no quiescence provider is configured.');
        }
    }

    private function mediaComponent(BackupRun $run): ComponentOutcome
    {
        $kind = $run->profile === BackupProfile::Recovery ? SnapshotKind::RecoveryMedia : SnapshotKind::Media;
        $artifact = $run->addArtifact(ArtifactKind::ResticSnapshot);

        try {
            $result = $this->media->snapshot($run->refresh(), $artifact, $kind);

            return ComponentOutcome::verified($result->snapshotId);
        } catch (Throwable $exception) {
            return $this->mediaFailure($artifact->refresh(), $exception);
        }
    }

    private function mediaFailure(BackupArtifact $artifact, Throwable $exception): ComponentOutcome
    {
        $failure = FailureDetails::fromThrowable($exception, 'media.snapshot', $this->redactor);
        $code = $exception instanceof QurabaBackupException ? $exception->failureCode() : 'unexpected.error';

        // Nothing was attempted yet, or Restic proved no snapshot was written.
        $definite = $artifact->status === ArtifactStatus::Pending
            || in_array($code, ['restic.snapshot_failed', 'restic.snapshot_incomplete'], true);

        if ($definite) {
            if (! $artifact->status->isTerminal()) {
                $artifact->markFailed($failure);
            }

            return ComponentOutcome::failed($failure);
        }

        // A snapshot may exist: leave the artifact open for reconciliation.
        return ComponentOutcome::uncertain($failure);
    }

    /**
     * Records the consistency the session can truthfully claim, or refuses
     * (nothing captured yet) when quiesced is required but not provable.
     */
    private function recordQuiescence(BackupRun $run, QuiescenceSession $session): void
    {
        if ($session->level !== ConsistencyLevel::Quiesced && $this->requiresQuiesced()) {
            if (! $this->allowsDowngrade()) {
                throw new QuiescenceFailed(sprintf('A quiesced Recovery Point is required, but the %s provider cannot prove quiescence: %s', $session->provider, $session->explanation));
            }

            $run->recordConsistency(ConsistencyLevel::BestEffort, 'Quiesced was requested but could not be proven; downgraded to best_effort as configuration allows. '.$session->explanation);

            return;
        }

        $run->recordConsistency($session->level, $session->explanation);
    }

    /**
     * @param  list<string>  $warnings
     */
    private function confirmQuiescence(BackupRun $run, QuiescenceSession $session, array &$warnings): void
    {
        try {
            $session->assertStillQuiesced();
        } catch (QuiescenceFailed $exception) {
            // Never keep a claim that stopped being true.
            $run->refresh()->recordConsistency(ConsistencyLevel::BestEffort, 'Quiescence was lost during capture: '.$exception->getMessage());
            $warnings[] = $exception->getMessage();
        }
    }

    /**
     * @param  list<string>  $warnings
     */
    private function leaveQuiescence(BackupRun $run, QuiescenceSession $session, array &$warnings): bool
    {
        try {
            $session->leave();

            return true;
        } catch (Throwable $exception) {
            $message = 'Releasing quiescence failed; the application may still be in maintenance mode: '.$this->redactor->redact($exception->getMessage());
            $warnings[] = $message;
            $this->logger->critical('Quraba Backup could not release quiescence.', ['run_uuid' => $run->uuid, 'error' => $message]);

            return false;
        }
    }

    private function abort(BackupRun $run, Throwable $exception): FinalizedRun
    {
        $run->refresh();
        $failure = FailureDetails::fromThrowable($exception, 'backup', $this->redactor);

        if ($run->status->isTerminal()) {
            return new FinalizedRun($run->status, null);
        }

        // Physical artifacts may exist if any component got past creation.
        $mayExist = $run->artifacts()->get()->contains(static fn (BackupArtifact $a): bool => $a->status !== ArtifactStatus::Pending && $a->status !== ArtifactStatus::Failed);

        if ($mayExist) {
            $run->markIndeterminate($failure);

            return new FinalizedRun(BackupStatus::Indeterminate, null);
        }

        $run->markFailed($failure);

        return new FinalizedRun(BackupStatus::Failed, null);
    }

    private function requiresQuiesced(): bool
    {
        return (bool) $this->config->get('quraba-backup.consistency.require_quiesced', false);
    }

    private function allowsDowngrade(): bool
    {
        return (bool) $this->config->get('quraba-backup.consistency.allow_downgrade', false);
    }
}
