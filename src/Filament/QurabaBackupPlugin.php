<?php

declare(strict_types=1);

namespace Quraba\Backup\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Quraba\Backup\Filament\Pages\BackupDashboard;
use Quraba\Backup\Filament\Pages\BackupRuns;
use Quraba\Backup\Filament\Pages\HealthMaintenance;
use Quraba\Backup\Filament\Pages\Restore;

final class QurabaBackupPlugin implements Plugin
{
    public static function make(): self
    {
        return new self;
    }

    public function getId(): string
    {
        return 'quraba-backup';
    }

    public function register(Panel $panel): void
    {
        $panel->pages([BackupDashboard::class, BackupRuns::class, Restore::class, HealthMaintenance::class]);
    }

    public function boot(Panel $panel): void {}
}
