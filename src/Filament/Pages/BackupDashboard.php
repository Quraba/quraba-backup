<?php

declare(strict_types=1);

namespace Quraba\Backup\Filament\Pages;

use Filament\Pages\Page;
use Quraba\Backup\Enums\PendingOperationStatus;
use Quraba\Backup\Enums\PendingOperationType;
use Quraba\Backup\Filament\BackupPanelAccess;
use Quraba\Backup\Filament\Widgets\BackupStats;
use Quraba\Backup\Models\BackupMaintenanceRun;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Models\PendingOperation;
use Quraba\Backup\Operations\PanelTableAvailability;
use Quraba\Backup\Operations\WorkerHeartbeat;
use Quraba\Backup\Restore\Live\RestoreReconciler;
use Quraba\Backup\Scheduling\BackupScheduler;
use Quraba\Backup\Security\SecretRedactor;
use Throwable;

final class BackupDashboard extends Page
{
    protected string $view = 'quraba-backup::filament.dashboard';

    protected static ?string $navigationLabel = 'Backup Dashboard';

    protected function getHeaderWidgets(): array
    {
        $tables = app(PanelTableAvailability::class);

        return $tables->has('runs') && $tables->has('artifacts') ? [BackupStats::class] : [];
    }

    public static function canAccess(): bool
    {
        return BackupPanelAccess::allows('view-dashboard');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        BackupPanelAccess::authorize('view-dashboard');

        $tables = app(PanelTableAvailability::class);
        $operationsAvailable = $tables->has('operations');
        $runsAvailable = $tables->has('runs');
        $maintenanceAvailable = $tables->has('maintenance');
        try {
            $healthRun = $operationsAvailable ? PendingOperation::query()->where('type', PendingOperationType::HealthRefresh->value)
                ->whereIn('status', [PendingOperationStatus::Completed->value, PendingOperationStatus::Failed->value])->latest('id')->first() : null;
        } catch (Throwable) {
            $healthRun = null;
            $operationsAvailable = false;
        }
        $health = $healthRun === null || ! is_array($healthRun->result)
            ? ['state' => 'unknown', 'checks' => [], 'checked_at' => null]
            : $healthRun->result;
        if ($healthRun?->finished_at === null || $healthRun->finished_at->lessThan(now('UTC')->subHour())) {
            $health['state'] = 'unknown';
        } elseif ($healthRun->status === PendingOperationStatus::Failed) {
            $health['state'] = 'failed';
        }
        try {
            $journals = app(RestoreReconciler::class)->overview();
        } catch (Throwable) {
            $journals = ['unresolved' => 0, 'unreadable' => ['unavailable']];
        }
        try {
            $schedules = app(BackupScheduler::class)->definitions();
            $scheduleError = null;
        } catch (Throwable $exception) {
            $schedules = [];
            $scheduleError = app(SecretRedactor::class)->redact($exception->getMessage());
        }

        try {
            $warnings = $runsAvailable ? BackupRun::query()->whereIn('status', ['failed', 'partial', 'indeterminate'])->latest('id')->limit(5)->get() : collect();
            $latestRun = $runsAvailable ? BackupRun::query()->latest('id')->first() : null;
        } catch (Throwable) {
            $warnings = collect();
            $latestRun = null;
            $runsAvailable = false;
        }
        $workerRecent = app(WorkerHeartbeat::class)->recentlyObserved();
        $pendingEnabled = (bool) config('quraba-backup.filament.pending_enabled');
        if ($journals['unresolved'] > 0 || $journals['unreadable'] !== []) {
            $health['state'] = 'failed';
        } elseif ($latestRun?->status?->value === 'indeterminate') {
            $health['state'] = 'failed';
        } elseif (($health['state'] ?? null) === 'healthy' && (in_array($latestRun?->status?->value, ['failed', 'partial'], true) || ($pendingEnabled && ! $workerRecent) || $scheduleError !== null)) {
            $health['state'] = 'degraded';
        }

        try {
            $resticCheck = $maintenanceAvailable ? BackupMaintenanceRun::query()->where('operation', 'restic_check')->where('status', 'completed')->latest('finished_at')->first() : null;
        } catch (Throwable) {
            $resticCheck = null;
            $maintenanceAvailable = false;
        }

        return [
            'health' => $health,
            'repository' => collect(is_array($health['checks'] ?? null) ? $health['checks'] : [])->firstWhere('id', 'health.repository'),
            'warnings' => $warnings,
            'resticCheck' => $resticCheck,
            'schedules' => $schedules,
            'scheduleError' => $scheduleError,
            'journals' => $journals,
            'workerObserved' => app(WorkerHeartbeat::class)->observedAt(),
            'workerRecent' => $workerRecent,
            'pendingEnabled' => $pendingEnabled,
            'operationsAvailable' => $operationsAvailable,
            'catalogAvailable' => $runsAvailable,
            'maintenanceAvailable' => $maintenanceAvailable,
            'environment' => config('quraba-backup.environment') ?: config('app.env'),
            'backupEnabled' => (bool) config('quraba-backup.enabled'),
            'secretsAcknowledged' => (bool) config('quraba-backup.recovery_secrets_acknowledged'),
        ];
    }
}
