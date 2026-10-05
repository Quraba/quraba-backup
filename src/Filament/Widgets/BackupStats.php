<?php

declare(strict_types=1);

namespace Quraba\Backup\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Quraba\Backup\Enums\ArtifactKind;
use Quraba\Backup\Enums\ArtifactStatus;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Enums\BackupStatus;
use Quraba\Backup\Filament\Ui;
use Quraba\Backup\Models\BackupArtifact;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Operations\PanelTableAvailability;

final class BackupStats extends StatsOverviewWidget
{
    protected int|array|null $columns = ['default' => 1, 'md' => 2, 'xl' => 4];

    protected function getStats(): array
    {
        $tables = app(PanelTableAvailability::class);
        if (! $tables->has('runs') || ! $tables->has('artifacts')) {
            return [];
        }
        $recovery = BackupRun::query()->where('profile', BackupProfile::Recovery->value)->where('status', BackupStatus::Completed->value)
            ->whereHas('artifacts', fn ($q) => $q->where('kind', ArtifactKind::ApplicationArchive->value)->where('status', ArtifactStatus::Verified->value))
            ->whereHas('artifacts', fn ($q) => $q->where('kind', ArtifactKind::ResticSnapshot->value)->where('status', ArtifactStatus::Verified->value))
            ->latest('requested_at')->first();
        $database = BackupArtifact::query()->where('kind', ArtifactKind::ApplicationArchive->value)->where('status', ArtifactStatus::Verified->value)->with('run')->latest('verified_at')->first()?->run;
        $media = BackupArtifact::query()->where('kind', ArtifactKind::ResticSnapshot->value)->where('status', ArtifactStatus::Verified->value)->with('run')->latest('verified_at')->first()?->run;
        $latest = BackupRun::query()->where('status', BackupStatus::Completed->value)->latest('completed_at')->first();

        return [
            $this->stat(Ui::text('last_successful'), $latest),
            $this->stat(Ui::text('labels.snapshot'), $media),
            $this->stat(Ui::text('actions.database_backup'), $database),
            $this->stat(Ui::text('actions.recovery_backup'), $recovery),
        ];
    }

    private function stat(string $label, ?BackupRun $run): Stat
    {
        $time = $run === null ? null : ($run->completed_at ?? $run->requested_at);
        $configuredTimezone = config('app.timezone', 'UTC');
        $timezone = is_string($configuredTimezone) ? $configuredTimezone : 'UTC';

        return Stat::make($label, $time?->setTimezone($timezone)->translatedFormat('j M Y H:i') ?? Ui::text('empty_states.none'))
            ->description($time === null ? Ui::text('empty_states.no_backup') : $time->diffForHumans().' · '.$timezone)
            ->color($time === null ? 'warning' : 'success');
    }
}
