<?php

declare(strict_types=1);

namespace Quraba\Backup\Filament\Pages;

use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Quraba\Backup\Enums\ArtifactKind;
use Quraba\Backup\Enums\ArtifactStatus;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Enums\BackupStatus;
use Quraba\Backup\Enums\PendingOperationStatus;
use Quraba\Backup\Enums\PendingOperationType;
use Quraba\Backup\Filament\BackupPanelAccess;
use Quraba\Backup\Filament\OperatorStatus;
use Quraba\Backup\Filament\Ui;
use Quraba\Backup\Models\BackupMaintenanceRun;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Models\PendingOperation;
use Quraba\Backup\Operations\PanelTableAvailability;
use Quraba\Backup\Operations\PendingOperationRequest;
use Quraba\Backup\Operations\WorkerHeartbeat;
use Quraba\Backup\Restore\Live\RestoreReconciler;
use Quraba\Backup\Scheduling\BackupScheduler;
use Quraba\Backup\Scheduling\ScheduleDefinition;
use Quraba\Backup\Scheduling\ScheduleSettings;
use Quraba\Backup\Security\SecretRedactor;
use Throwable;

final class BackupDashboard extends Page
{
    protected string $view = 'quraba-backup::filament.dashboard';

    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string
    {
        return Ui::text('navigation.dashboard');
    }

    public static function getNavigationGroup(): string
    {
        return Ui::text('navigation.group');
    }

    public function getTitle(): string
    {
        return Ui::text('navigation.dashboard');
    }

    public function getSubheading(): string
    {
        return Ui::text('visual.overview_subtitle');
    }

    public function getPageClasses(): array
    {
        return ['qb-design', 'qb-overview'];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create_backup')->label(Ui::text('operator.create_backup'))->icon('heroicon-o-circle-stack')->url(BackupRuns::getUrl())->visible(fn (): bool => BackupPanelAccess::allows('view-details')),
            Action::make('restore')->label(Ui::text('operator.start_restore'))->icon('heroicon-o-arrow-uturn-left')->color('gray')->url(Restore::getUrl())->visible(fn (): bool => BackupPanelAccess::allows('view-recovery')),
            Action::make('check_now')->label(Ui::text('operator.check_now'))->icon('heroicon-o-heart')->color('gray')->visible(fn (): bool => BackupPanelAccess::allows('run-health-check') && (bool) config('quraba-backup.filament.pending_enabled'))->action(function (): void {
                BackupPanelAccess::authorize('run-health-check');
                try {
                    $actor = Filament::auth()->user();
                    abort_unless($actor instanceof Authenticatable, 403);
                    app(PendingOperationRequest::class)->submit(PendingOperationType::HealthRefresh, $actor);
                    Notification::make()->title(Ui::text('pages.health.check_requested'))->body(Ui::text('pages.health.check_queued'))->success()->send();
                } catch (Throwable $exception) {
                    Notification::make()->title(Ui::text('pages.health.request_refused'))->body(OperatorStatus::failure(null, $exception->getMessage()))->danger()->send();
                }
            }),
        ];
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

        $latestUsable = ['full' => null, 'database' => null, 'media' => null];
        $active = null;
        $activeOperation = null;
        if ($operationsAvailable) {
            try {
                $activeOperation = PendingOperation::query()->whereIn('status', [PendingOperationStatus::Pending->value, PendingOperationStatus::Claimed->value, PendingOperationStatus::Running->value])->latest('id')->first();
            } catch (Throwable) {
                $operationsAvailable = false;
            }
        }
        if ($runsAvailable && $tables->has('artifacts')) {
            try {
                $verified = static fn (ArtifactKind $kind): \Closure => static fn (Builder $query): Builder => $query->where('kind', $kind->value)->where('status', ArtifactStatus::Verified->value);
                $completed = BackupRun::query()->where('status', BackupStatus::Completed->value);
                $latestUsable['full'] = (clone $completed)->where('profile', BackupProfile::Recovery->value)
                    ->whereHas('artifacts', $verified(ArtifactKind::ApplicationArchive))
                    ->whereHas('artifacts', $verified(ArtifactKind::ResticSnapshot))->latest('requested_at')->first();
                $latestUsable['database'] = (clone $completed)->whereIn('profile', [BackupProfile::Database->value, BackupProfile::Recovery->value])
                    ->whereHas('artifacts', $verified(ArtifactKind::ApplicationArchive))->latest('requested_at')->first();
                $latestUsable['media'] = (clone $completed)->whereIn('profile', [BackupProfile::Media->value, BackupProfile::Recovery->value])
                    ->whereHas('artifacts', $verified(ArtifactKind::ResticSnapshot))->latest('requested_at')->first();
                $active = BackupRun::query()->whereIn('status', [BackupStatus::Pending->value, BackupStatus::Preflighting->value, BackupStatus::Running->value, BackupStatus::Verifying->value])->latest('id')->first();
            } catch (Throwable) {
                $latestUsable = ['full' => null, 'database' => null, 'media' => null];
            }
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
            'latestUsable' => $latestUsable,
            'activeBackup' => $active,
            'activeOperation' => $activeOperation,
            'nextBackup' => $this->nextBackup($schedules),
        ];
    }

    /**
     * @param  list<ScheduleDefinition>  $schedules
     * @return array{at: CarbonImmutable, profile: BackupProfile}|null
     */
    private function nextBackup(array $schedules): ?array
    {
        try {
            $timezone = app(ScheduleSettings::class)->effective('timezone')['value'];
            $fallbackTimezone = config('app.timezone', 'UTC');
            $now = CarbonImmutable::now(is_string($timezone) && $timezone !== '' ? $timezone : (is_string($fallbackTimezone) ? $fallbackTimezone : 'UTC'));
            $next = null;
            foreach ($schedules as $definition) {
                if ($definition->profile === null) {
                    continue;
                }
                [$hour, $minute] = array_map(intval(...), explode(':', $definition->time));
                $candidate = $now->setTime($hour, $minute);
                if ($definition->frequency === 'weekly') {
                    while ($candidate->dayOfWeek !== $definition->day || $candidate->lessThanOrEqualTo($now)) {
                        $candidate = $candidate->addDay();
                    }
                } elseif ($definition->frequency === 'monthly') {
                    $candidate = $candidate->setDay((int) $definition->day);
                    if ($candidate->lessThanOrEqualTo($now)) {
                        $candidate = $candidate->addMonthNoOverflow();
                    }
                } elseif ($candidate->lessThanOrEqualTo($now)) {
                    $candidate = $candidate->addDay();
                }
                if ($next === null || $candidate->lessThan($next['at'])) {
                    $next = ['at' => $candidate, 'profile' => $definition->profile];
                }
            }

            return $next;
        } catch (Throwable) {
            return null;
        }
    }
}
