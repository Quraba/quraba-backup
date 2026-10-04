<?php

declare(strict_types=1);

namespace Quraba\Backup\Operations;

use Illuminate\Contracts\Config\Repository;
use Quraba\Backup\Contracts\PendingOperationActorResolver;
use Quraba\Backup\Enums\PendingOperationStatus;
use Quraba\Backup\Enums\PendingOperationType;
use Quraba\Backup\Enums\RestoreProfile;
use Quraba\Backup\Health\BackupHealthService;
use Quraba\Backup\Health\Doctor\DoctorService;
use Quraba\Backup\Health\ResticHealthService;
use Quraba\Backup\Maintenance\ResticMaintenanceService;
use Quraba\Backup\Models\PendingOperation;
use Quraba\Backup\Restore\Live\LiveRestoreAuthorization;
use Quraba\Backup\Restore\Live\LiveRestoreService;
use Quraba\Backup\Restore\RestoreDryRunService;
use Quraba\Backup\Retention\RetentionExecutor;
use Quraba\Backup\Security\SecretRedactor;
use Quraba\Backup\Support\PackagePaths;
use Throwable;

final readonly class PendingOperationProcessor
{
    public function __construct(
        private Repository $config,
        private PendingOperationActorResolver $actors,
        private OperationLease $leases,
        private WorkerHeartbeat $heartbeat,
        private PackagePaths $paths,
        private LiveApprovalStore $approvals,
        private SecretRedactor $redactor,
        private RestoreDryRunService $dryRestores,
        private LiveRestoreService $liveRestores,
        private DoctorService $doctor,
        private BackupHealthService $health,
        private ResticHealthService $resticHealth,
        private ResticMaintenanceService $restic,
        private RetentionExecutor $retention,
    ) {}

    public function runOne(): ?PendingOperation
    {
        $this->heartbeat->beat();
        $this->markInterrupted();
        $candidate = PendingOperation::query()->where('status', PendingOperationStatus::Pending->value)->orderBy('id')->first();
        if ($candidate === null) {
            return null;
        }
        // Hold the lease before the database claim. Otherwise a second worker
        // could observe a claimed row without a lease and mark it interrupted.
        try {
            $lease = $this->leases->acquire($candidate->uuid);
        } catch (\RuntimeException) {
            return null;
        }
        try {
            if (! $candidate->move(PendingOperationStatus::Pending, PendingOperationStatus::Claimed, ['claimed_at' => now('UTC'), 'heartbeat_at' => now('UTC')])) {
                return null;
            }
            $candidate->move(PendingOperationStatus::Claimed, PendingOperationStatus::Running, ['started_at' => now('UTC'), 'heartbeat_at' => now('UTC')]);
            $actor = $this->actors->resolve($candidate->actor_type, $candidate->actor_id);
            if ($actor === null || ! OperationAccess::allows($actor, $candidate->type->ability())) {
                throw new \RuntimeException('The requesting actor is no longer authorized.');
            }
            if (! $this->config->get('quraba-backup.enabled', true) || ! $this->config->get('quraba-backup.filament.pending_enabled')) {
                throw new \RuntimeException('Panel background operations were disabled before execution.');
            }
            if ($candidate->type === PendingOperationType::LiveRestore && ! $this->config->get('quraba-backup.filament.live_restore_enabled')) {
                throw new \RuntimeException('Panel live restore was disabled before execution.');
            }
            $result = $this->dispatch($candidate);
            $state = ($result['status'] ?? null) === 'indeterminate' ? PendingOperationStatus::Indeterminate
                : (($result['ok'] ?? true) === true ? PendingOperationStatus::Completed : PendingOperationStatus::Failed);
            $candidate->finish($state, $result);
        } catch (Throwable $exception) {
            $candidate->finish(PendingOperationStatus::Failed, [], 'operation.failed', $this->redactor->redact($exception->getMessage()));
        } finally {
            flock($lease, LOCK_UN);
            fclose($lease);
        }

        return PendingOperation::query()->where('uuid', $candidate->uuid)->first() ?? $candidate;
    }

    /** @return array<string, mixed> */
    private function dispatch(PendingOperation $operation): array
    {
        $profile = $operation->restore_profile;
        if (in_array($operation->type, [PendingOperationType::DryRestore, PendingOperationType::LiveRestore], true) && ! $profile instanceof RestoreProfile) {
            throw new \RuntimeException('The queued restore profile is invalid.');
        }

        return match ($operation->type) {
            PendingOperationType::DryRestore => $this->dryResult($this->dryRestores->run((string) $operation->source_run_uuid, $profile)),
            PendingOperationType::LiveRestore => $this->liveResult($operation),
            PendingOperationType::Doctor => $this->checkResult($this->doctor->run()->toArray()),
            PendingOperationType::HealthRefresh => $this->healthResult(),
            PendingOperationType::ResticCheck => $this->resticResult($this->restic->check(false)->toArray()),
            PendingOperationType::RetentionPlan => $this->retentionResult($this->retention->run(false)->toArray()),
        };
    }

    /** @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    private function dryResult(array $report): array
    {
        $keys = ['ok', 'restore_run_uuid', 'run_uuid', 'requested_profile', 'archive_verified', 'archive_sha256', 'app_key_compatibility', 'release_compatibility', 'db_validation_level', 'repository_id', 'snapshot_id', 'required_disk_bytes', 'free_disk_bytes', 'atomic_rename', 'warnings', 'blockers', 'notice', 'error'];

        return $this->redactor->redactArray(array_intersect_key($report, array_flip($keys)));
    }

    /** @return array<string, mixed> */
    private function liveResult(PendingOperation $operation): array
    {
        $profile = $operation->restore_profile ?? throw new \RuntimeException('Restore profile missing.');
        $proof = ConsumedLiveApproval::consume($operation, $this->paths);
        $authorization = LiveRestoreAuthorization::fromConsumedApproval($proof);
        $report = $this->liveRestores->run(
            (string) $operation->source_run_uuid,
            $profile,
            $authorization,
            fn (string $restoreUuid) => $this->approvals->linkRestore($operation->uuid, $restoreUuid),
        );

        return $this->redactor->redactArray(array_intersect_key($report, array_flip(['ok', 'status', 'restore_uuid', 'run_uuid', 'requested_profile', 'warnings', 'blockers', 'notice'])));
    }

    /** @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    private function checkResult(array $report): array
    {
        $checks = is_array($report['checks'] ?? null) ? $report['checks'] : [];

        return $this->redactor->redactArray([
            'ok' => ($report['state'] ?? null) !== 'failed',
            'state' => $report['state'] ?? 'unknown',
            'checked_at' => $report['checked_at'] ?? null,
            'counts' => $report['counts'] ?? [],
            'checks' => array_values(array_map(static fn (array $check): array => array_intersect_key($check, array_flip(['id', 'label', 'status', 'message'])), array_filter($checks, 'is_array'))),
        ]);
    }

    /** @return array<string, mixed> */
    private function healthResult(): array
    {
        $backup = $this->health->check(true)->toArray();
        $restic = $this->resticHealth->check()->toArray();
        $combined = $this->checkResult($backup);
        $combined['restic_state'] = $restic['state'];
        $backupChecks = is_array($combined['checks'] ?? null) ? $combined['checks'] : [];
        $resticResult = $this->checkResult($restic);
        $resticChecks = is_array($resticResult['checks'] ?? null) ? $resticResult['checks'] : [];
        $combined['checks'] = [...$backupChecks, ...$resticChecks];
        if ($restic['state'] === 'failed') {
            $combined['state'] = 'failed';
            $combined['ok'] = false;
        } elseif ($restic['state'] === 'degraded' && ($combined['state'] ?? null) === 'healthy') {
            $combined['state'] = 'degraded';
        }

        return $combined;
    }

    /** @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    private function resticResult(array $report): array
    {
        return $this->redactor->redactArray(array_intersect_key($report, array_flip(['maintenance_run_uuid', 'status', 'mode', 'summary', 'failure'])) + ['ok' => ($report['status'] ?? null) === 'completed']);
    }

    /** @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    private function retentionResult(array $report): array
    {
        return $this->redactor->redactArray(array_intersect_key($report, array_flip(['maintenance_run_uuid', 'status', 'plan', 'inspection_error', 'failure'])) + ['ok' => ($report['status'] ?? null) === 'completed']);
    }

    private function markInterrupted(): void
    {
        foreach (PendingOperation::query()->whereIn('status', [PendingOperationStatus::Claimed->value, PendingOperationStatus::Running->value])->get() as $operation) {
            try {
                if ($this->leases->isHeld($operation->uuid)) {
                    continue;
                }
                $operation->move($operation->status, PendingOperationStatus::Interrupted, ['finished_at' => now('UTC'), 'failure_code' => 'operation.interrupted', 'failure_message' => 'The worker stopped before recording an outcome. Review the restore journal before any new live restore.']);
            } catch (Throwable) {
                // A held or inaccessible lease cannot prove the process stopped.
            }
        }
    }
}
