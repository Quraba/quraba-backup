<?php

declare(strict_types=1);

namespace Quraba\Backup\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Quraba\Backup\Filament\Pages\BackupDashboard;
use Quraba\Backup\Filament\Pages\BackupOperations;
use Quraba\Backup\Filament\Pages\BackupRuns;

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
        $panel->pages([BackupDashboard::class, BackupRuns::class, BackupOperations::class]);
    }

    public function boot(Panel $panel): void {}
}
