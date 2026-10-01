<?php

declare(strict_types=1);

namespace Quraba\Backup\Maintenance;

use Quraba\Backup\Domain\FailureDetails;
use Quraba\Backup\Enums\BackupStatus;
use Quraba\Backup\Enums\MaintenanceOperation;
use Quraba\Backup\Enums\MaintenanceStatus;
use Quraba\Backup\Identity\ApplicationIdentity;
use Quraba\Backup\Models\BackupMaintenanceRun;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Retention\RetentionExecutor;
use Quraba\Backup\Security\SecretRedactor;
use Throwable;

/**
 * Settles interrupted maintenance from physical evidence. Runs inside
 * backup reconciliation, which holds the global and maintenance locks, so
 * any maintenance run still pending or running was interrupted.
 *
 *  - Retention intents (`retention_pending` on a run): when every planned
 *    component is physically absent, the tombstone is written and the
 *    artifacts are marked expired; when something is still present nothing
 *    changes (a later retention pass deletes it). Deleted artifacts are
 *    never recreated.
 *  - Interrupted checks, dry prunes and reconciliation audits are closed as
 *    FAILED (`maintenance.interrupted`). An actual interrupted prune remains
 *    INDETERMINATE because its physical effect cannot be proved here.
 *  - An interrupted retention audit is closed once none of its intents
 *    remain; while one remains it stays indeterminate.
 */
final readonly class MaintenanceReconciler
{
    private const array OPEN = [MaintenanceStatus::Pending, MaintenanceStatus::Running, MaintenanceStatus::Indeterminate];

    public function __construct(
        private RetentionExecutor $retention,
        private SecretRedactor $redactor,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function reconcile(ApplicationIdentity $identity, bool $dryRun, string $currentAuditUuid): array
    {
        $items = [];
        $pendingByMaintenance = [];

        foreach ($this->runsWithRetentionIntent() as $run) {
            $pending = (array) ($run->metadata['retention_pending'] ?? []);
            $maintenanceUuid = is_string($pending['maintenance_run_uuid'] ?? null) ? $pending['maintenance_run_uuid'] : '';

            if ($dryRun) {
                $items[] = ['type' => 'retention_intent', 'run_uuid' => $run->uuid, 'maintenance_run_uuid' => $maintenanceUuid, 'outcome' => 'would_settle_if_absent'];
                $pendingByMaintenance[$maintenanceUuid] = true;

                continue;
            }

            try {
                $evidence = $this->retention->settlePending($run, $identity);
            } catch (Throwable $exception) {
                $evidence = ['run_uuid' => $run->uuid, 'outcome' => 'error', 'error' => $this->redactor->redact($exception->getMessage())];
            }

            if ($evidence['outcome'] !== 'settled') {
                $pendingByMaintenance[$maintenanceUuid] = true;
            }

            $items[] = ['type' => 'retention_intent', 'maintenance_run_uuid' => $maintenanceUuid, ...$evidence];
        }

        $open = BackupMaintenanceRun::query()
            ->whereIn('status', array_map(static fn (MaintenanceStatus $s): string => $s->value, self::OPEN))
            ->where('uuid', '!=', $currentAuditUuid)
            ->orderBy('id')
            ->get();

        foreach ($open as $maintenance) {
            $items[] = $dryRun
                ? ['type' => 'maintenance_run', 'maintenance_run_uuid' => $maintenance->uuid, 'operation' => $maintenance->operation->value, 'before' => $maintenance->status->value, 'after' => $maintenance->status->value]
                : $this->close($maintenance, isset($pendingByMaintenance[$maintenance->uuid]));
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function close(BackupMaintenanceRun $maintenance, bool $retentionIntentRemains): array
    {
        $before = $maintenance->status;
        $interrupted = FailureDetails::make('maintenance.interrupted', 'reconcile.maintenance', sprintf('The %s maintenance run was interrupted before it finished.', $maintenance->operation->value), $this->redactor);

        if ($before === MaintenanceStatus::Pending) {
            $maintenance->markFailed($interrupted);

            return $this->item($maintenance, $before);
        }

        if ($before === MaintenanceStatus::Running) {
            $maintenance->markIndeterminate($interrupted);
        }

        if (($maintenance->operation === MaintenanceOperation::Retention && $retentionIntentRemains)
            || ($maintenance->operation === MaintenanceOperation::ResticPrune && ! $maintenance->dry_run)) {
            return $this->item($maintenance->refresh(), $before);
        }

        $maintenance->refresh()->resolveIndeterminate(MaintenanceStatus::Failed, [
            'reason' => $maintenance->operation === MaintenanceOperation::Retention
                ? 'interrupted retention: every recorded deletion intent is settled; repeat retention to finish the plan'
                : 'interrupted; the operation is safe to repeat',
        ], $maintenance->dry_run ? [] : ($maintenance->affected_items ?? []), $interrupted);

        return $this->item($maintenance->refresh(), $before);
    }

    /**
     * @return list<BackupRun>
     */
    private function runsWithRetentionIntent(): array
    {
        return array_values(BackupRun::query()
            ->whereIn('status', [BackupStatus::Completed->value, BackupStatus::Partial->value])
            ->orderBy('id')
            ->get()
            ->filter(static fn (BackupRun $run): bool => is_array($run->metadata['retention_pending'] ?? null))
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function item(BackupMaintenanceRun $maintenance, MaintenanceStatus $before): array
    {
        return [
            'type' => 'maintenance_run',
            'maintenance_run_uuid' => $maintenance->uuid,
            'operation' => $maintenance->operation->value,
            'before' => $before->value,
            'after' => $maintenance->status->value,
        ];
    }
}
