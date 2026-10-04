<?php

declare(strict_types=1);

namespace Quraba\Backup\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Quraba\Backup\Enums\ArtifactKind;
use Quraba\Backup\Enums\ArtifactStatus;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Enums\BackupStatus;
use Quraba\Backup\Models\BackupArtifact;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Operations\PanelTableAvailability;

final class BackupStats extends StatsOverviewWidget
{
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
            $this->stat('Complete Recovery Point', $recovery),
            $this->stat('Database backup', $database),
            $this->stat('Media snapshot', $media),
            $this->stat('Last successful backup', $latest),
        ];
    }

    private function stat(string $label, ?BackupRun $run): Stat
    {
        $time = $run === null ? null : ($run->completed_at ?? $run->requested_at);
        $configuredTimezone = config('app.timezone', 'UTC');
        $timezone = is_string($configuredTimezone) ? $configuredTimezone : 'UTC';

        return Stat::make($label, $time?->setTimezone($timezone)->format('M j, Y H:i') ?? 'None')
            ->description($time === null ? 'No verified backup' : $time->diffForHumans().' · '.$timezone)
            ->color($time === null ? 'warning' : 'success');
    }
}
