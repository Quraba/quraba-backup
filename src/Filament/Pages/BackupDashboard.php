<?php

declare(strict_types=1);

namespace Quraba\Backup\Filament\Pages;

use Filament\Pages\Page;
use Quraba\Backup\Enums\ArtifactKind;
use Quraba\Backup\Enums\ArtifactStatus;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Enums\BackupStatus;
use Quraba\Backup\Enums\ConsistencyLevel;
use Quraba\Backup\Filament\BackupPanelAccess;
use Quraba\Backup\Health\BackupHealthService;
use Quraba\Backup\Models\BackupArtifact;
use Quraba\Backup\Models\BackupMaintenanceRun;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Scheduling\BackupScheduler;
use Throwable;

final class BackupDashboard extends Page
{
    protected string $view = 'quraba-backup::filament.dashboard';

    protected static ?string $navigationLabel = 'Backup health';

    public static function canAccess(): bool
    {
        return BackupPanelAccess::allows('view-dashboard');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        BackupPanelAccess::authorize('view-dashboard');

        try {
            $health = app(BackupHealthService::class)->check(true)->toArray();
        } catch (Throwable) {
            $health = ['state' => 'unknown', 'checks' => []];
        }

        return [
            'health' => $health,
            'repository' => collect($health['checks'])->firstWhere('id', 'health.repository'),
            'database' => BackupArtifact::query()->with('run')->where('kind', ArtifactKind::ApplicationArchive->value)->where('status', ArtifactStatus::Verified->value)->latest('id')->first()?->run,
            'media' => BackupArtifact::query()->with('run')->where('kind', ArtifactKind::ResticSnapshot->value)->where('status', ArtifactStatus::Verified->value)->latest('id')->first()?->run,
            'recovery' => BackupRun::query()->where('profile', BackupProfile::Recovery->value)->where('status', BackupStatus::Completed->value)->latest('id')->first(),
            'quiesced' => BackupRun::query()->where('profile', BackupProfile::Recovery->value)->where('status', BackupStatus::Completed->value)->where('consistency', ConsistencyLevel::Quiesced->value)->latest('id')->first(),
            'warnings' => BackupRun::query()->whereIn('status', ['failed', 'partial', 'indeterminate'])->latest('id')->limit(5)->get(),
            'maintenance' => BackupMaintenanceRun::query()->latest('id')->limit(5)->get(),
            'schedules' => app(BackupScheduler::class)->definitions(),
        ];
    }
}
