<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Feature;

use Filament\Facades\Filament;
use Filament\FilamentManager;
use Filament\Panel;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Blade;
use Quraba\Backup\Filament\BackupPanelAccess;
use Quraba\Backup\Filament\Pages\BackupDashboard;
use Quraba\Backup\Filament\Pages\BackupRuns;
use Quraba\Backup\Filament\Pages\HealthMaintenance;
use Quraba\Backup\Filament\Pages\Restore;
use Quraba\Backup\Filament\QurabaBackupPlugin;
use Quraba\Backup\Tests\TestCase;

final class FilamentIntegrationTest extends TestCase
{
    public function test_plugin_registers_pages_and_denies_unconfigured_access(): void
    {
        $this->app->instance('filament', new FilamentManager);
        $panel = Panel::make()->id('backup-test')->plugin(QurabaBackupPlugin::make());
        Filament::setCurrentPanel($panel);

        self::assertTrue($panel->hasPlugin('quraba-backup'));
        self::assertContains(BackupDashboard::class, $panel->getPages());
        self::assertContains(BackupRuns::class, $panel->getPages());
        self::assertContains(Restore::class, $panel->getPages());
        self::assertContains(HealthMaintenance::class, $panel->getPages());
        self::assertFalse(BackupDashboard::canAccess());

        auth()->guard('web')->setUser(new GenericUser(['id' => 1]));
        self::assertFalse(BackupPanelAccess::allows('run-backup'));
        $this->config()->set('quraba-backup.filament.authorization.run-backup', static fn (): bool => true);
        self::assertTrue(BackupPanelAccess::allows('run-backup'));
    }

    public function test_all_panel_views_compile(): void
    {
        foreach (['dashboard', 'runs', 'restore', 'health-maintenance'] as $view) {
            $source = file_get_contents(__DIR__.'/../../resources/views/filament/'.$view.'.blade.php');
            self::assertIsString($source);
            $source = str_replace(['<x-filament-panels::page>', '</x-filament-panels::page>'], ['<div>', '</div>'], $source);
            $source = preg_replace('/<x-filament::(?:section|badge)(?:\s[^>]*)?>/', '<div>', $source);
            $source = preg_replace('/<\/x-filament::(?:section|badge)>/', '</div>', (string) $source);
            self::assertNotSame('', Blade::compileString((string) $source));
        }
    }
}
