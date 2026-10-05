<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Feature;

use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\FilamentManager;
use Filament\Panel;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Schema;
use Quraba\Backup\Enums\ArtifactKind;
use Quraba\Backup\Enums\ArtifactStatus;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Enums\BackupStatus;
use Quraba\Backup\Enums\BackupTrigger;
use Quraba\Backup\Enums\MaintenanceOperation;
use Quraba\Backup\Enums\MaintenanceStatus;
use Quraba\Backup\Enums\PendingOperationStatus;
use Quraba\Backup\Enums\PendingOperationType;
use Quraba\Backup\Enums\RestoreStatus;
use Quraba\Backup\Filament\Pages\BackupDashboard;
use Quraba\Backup\Filament\Pages\BackupOperations;
use Quraba\Backup\Filament\Pages\BackupRuns;
use Quraba\Backup\Filament\Pages\HealthMaintenance;
use Quraba\Backup\Filament\Pages\Restore;
use Quraba\Backup\Filament\Ui;
use Quraba\Backup\Filament\Widgets\BackupStats;
use Quraba\Backup\Models\BackupArtifact;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Models\PendingOperation;
use Quraba\Backup\Operations\WorkerHeartbeat;
use Quraba\Backup\Restore\Journal\JournalPhase;
use Quraba\Backup\Tests\TestCase;
use ReflectionMethod;

final class FilamentPresentationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance('filament', new FilamentManager);
        Filament::setCurrentPanel(Panel::make()->id('backup-test'));
        auth()->guard('web')->setUser(new GenericUser(['id' => 1]));
        foreach (['view-dashboard', 'view-details', 'view-recovery'] as $ability) {
            $this->config()->set('quraba-backup.filament.authorization.'.$ability, static fn (): bool => true);
        }
    }

    public function test_old_failure_remains_in_history_but_newer_healthy_run_does_not_degrade_health(): void
    {
        $this->healthyRefresh();
        $old = BackupRun::request(BackupProfile::Recovery, BackupTrigger::Manual);
        BackupRun::query()->whereKey($old->id)->update(['status' => 'failed']);
        $new = BackupRun::request(BackupProfile::Recovery, BackupTrigger::Manual);
        BackupRun::query()->whereKey($new->id)->update(['status' => 'completed']);

        $data = $this->viewData(new BackupDashboard);
        self::assertSame('healthy', $data['health']['state']);
        self::assertCount(1, $data['warnings']);
        self::assertSame($old->uuid, $data['warnings'][0]->uuid);

        BackupRun::query()->whereKey($new->id)->update(['status' => 'indeterminate']);
        self::assertSame('failed', $this->viewData(new BackupDashboard)['health']['state']);
    }

    public function test_panel_worker_health_only_applies_when_feature_enabled(): void
    {
        $this->healthyRefresh();
        $this->config()->set('quraba-backup.schedules.enabled', false);
        $this->config()->set('quraba-backup.filament.pending_enabled', false);
        self::assertSame('healthy', $this->viewData(new BackupDashboard)['health']['state']);
        $this->config()->set('quraba-backup.filament.pending_enabled', true);
        self::assertSame('degraded', $this->viewData(new BackupDashboard)['health']['state']);
        $this->app->make(WorkerHeartbeat::class)->beat();
        self::assertSame('healthy', $this->viewData(new BackupDashboard)['health']['state']);
    }

    public function test_panel_pages_explain_missing_operation_table_without_querying_it(): void
    {
        Schema::drop('quraba_pending_operations');
        self::assertFalse($this->viewData(new BackupDashboard)['operationsAvailable']);
        self::assertFalse($this->viewData(new Restore)['operationsAvailable']);
        self::assertCount(0, $this->viewData(new Restore)['requests']);
        self::assertFalse($this->viewData(new HealthMaintenance)['operationsAvailable']);
        self::assertTrue($this->viewData(new BackupRuns)['catalogAvailable']);
    }

    public function test_recovery_point_components_show_not_applicable_for_other_profiles(): void
    {
        $page = new BackupRuns;
        $method = new ReflectionMethod($page, 'componentStatus');
        $database = BackupRun::request(BackupProfile::Database, BackupTrigger::Manual);
        $media = BackupRun::request(BackupProfile::Media, BackupTrigger::Manual);
        self::assertSame('not_applicable', $method->invoke($page, $database, ArtifactKind::ResticSnapshot));
        self::assertSame('not_applicable', $method->invoke($page, $media, ArtifactKind::ApplicationArchive));
        self::assertSame('missing_failed', $method->invoke($page, $database, ArtifactKind::ApplicationArchive));
    }

    public function test_package_translations_and_navigation_follow_the_active_locale(): void
    {
        $pages = [BackupDashboard::class, BackupRuns::class, Restore::class, HealthMaintenance::class, BackupOperations::class];
        foreach (['en' => 'Backup', 'ar' => 'النسخ الاحتياطي'] as $locale => $group) {
            $this->app->setLocale($locale);
            foreach ($pages as $page) {
                self::assertSame($group, $page::getNavigationGroup());
                self::assertNotSame('', $page::getNavigationLabel());
                self::assertSame($page::getNavigationLabel(), (new $page)->getTitle());
            }
        }

        $this->app->setLocale('ar');
        self::assertSame('مكتمل', Ui::value('completed'));
        self::assertSame('لم يُشغَّل بعد', Ui::text('empty_states.never_run'));
        self::assertSame('غير مضبوط', Ui::value('missing'));
    }

    public function test_arabic_and_english_package_keys_have_matching_structure(): void
    {
        $english = require __DIR__.'/../../resources/lang/en/filament.php';
        $arabic = require __DIR__.'/../../resources/lang/ar/filament.php';
        self::assertSame($this->translationKeys($english), $this->translationKeys($arabic));
    }

    public function test_all_recorded_state_and_operation_badges_have_arabic_labels(): void
    {
        $this->app->setLocale('ar');
        foreach ([ArtifactStatus::cases(), BackupStatus::cases(), MaintenanceStatus::cases(), PendingOperationStatus::cases(), RestoreStatus::cases()] as $states) {
            foreach ($states as $state) {
                self::assertNotSame($state->value, Ui::value($state));
            }
        }
        foreach (MaintenanceOperation::cases() as $operation) {
            self::assertNotSame($operation->value, Ui::value($operation, 'maintenance_operations'));
        }
        foreach (JournalPhase::cases() as $phase) {
            self::assertNotSame($phase->value, Ui::value($phase, 'journal_phases'));
        }
    }

    public function test_arabic_dashboard_renders_translated_sections_and_empty_states(): void
    {
        $this->app->setLocale('ar');
        $data = $this->viewData(new BackupDashboard);
        $source = file_get_contents(__DIR__.'/../../resources/views/filament/dashboard.blade.php');
        self::assertIsString($source);
        $source = str_replace(['<x-filament-panels::page>', '</x-filament-panels::page>'], ['<div>', '</div>'], $source);
        $source = preg_replace('/<x-filament::(?:section|badge)(?:\s[^>]*)?>/', '<div>', $source);
        $source = preg_replace('/<\/x-filament::(?:section|badge)>/', '</div>', (string) $source);
        $html = Blade::render((string) $source, $data);

        self::assertStringContainsString('لا توجد مشكلات حديثة في النسخ', $html);
        self::assertStringContainsString('لم يُفحص بعد', $html);
    }

    public function test_dashboard_has_four_ordered_arabic_summary_cards(): void
    {
        $this->app->setLocale('ar');
        $widget = new BackupStats;
        $stats = (new ReflectionMethod($widget, 'getStats'))->invoke($widget);
        self::assertIsArray($stats);
        $labels = [];
        foreach ($stats as $stat) {
            self::assertInstanceOf(Stat::class, $stat);
            $labels[] = $stat->getLabel();
        }
        self::assertSame(['آخر نسخة ناجحة', 'لقطة الوسائط', 'نسخ قاعدة البيانات', 'نقطة استعادة كاملة'], $labels);
    }

    public function test_health_actions_are_grouped_by_diagnostics_and_schedule(): void
    {
        $groups = (new ReflectionMethod(new HealthMaintenance, 'getHeaderActions'))->invoke(new HealthMaintenance);
        self::assertIsArray($groups);
        self::assertCount(2, $groups);
        foreach ($groups as $group) {
            self::assertInstanceOf(ActionGroup::class, $group);
        }
        self::assertCount(4, $groups[0]->getActions());
        self::assertCount(2, $groups[1]->getActions());
    }

    public function test_known_restore_sources_require_a_complete_recovery_run_and_both_verified_components(): void
    {
        $page = new Restore;
        $eligible = new ReflectionMethod($page, 'eligibleSourceQuery');
        $complete = new ReflectionMethod(new BackupRuns, 'complete');
        $run = BackupRun::request(BackupProfile::Recovery, BackupTrigger::Manual);
        BackupRun::query()->whereKey($run->id)->update(['status' => 'completed']);
        $run->refresh();
        $archive = BackupArtifact::createFor($run, ArtifactKind::ApplicationArchive);
        BackupArtifact::query()->whereKey($archive->id)->update(['status' => 'verified']);
        $run->load('artifacts');
        self::assertFalse($complete->invoke(new BackupRuns, $run));
        self::assertFalse($eligible->invoke($page)->where('uuid', $run->uuid)->exists());
        $sourceFields = (new ReflectionMethod($page, 'sourceFields'))->invoke($page);
        self::assertArrayNotHasKey($run->uuid, $sourceFields[0]->getOptions());

        $snapshot = BackupArtifact::createFor($run, ArtifactKind::ResticSnapshot);
        BackupArtifact::query()->whereKey($snapshot->id)->update(['status' => 'verified']);
        $run->load('artifacts');
        self::assertTrue($complete->invoke(new BackupRuns, $run));
        self::assertTrue($eligible->invoke($page)->where('uuid', $run->uuid)->exists());
        self::assertArrayHasKey($run->uuid, $sourceFields[0]->getOptions());
    }

    private function healthyRefresh(): void
    {
        $operation = PendingOperation::request(PendingOperationType::HealthRefresh, GenericUser::class, '1', null, null, 'health');
        $operation->move(PendingOperationStatus::Pending, PendingOperationStatus::Claimed);
        $operation->move(PendingOperationStatus::Claimed, PendingOperationStatus::Running);
        $operation->finish(PendingOperationStatus::Completed, ['state' => 'healthy', 'checks' => [], 'checked_at' => now('UTC')->toIso8601String()]);
    }

    /** @return array<string, mixed> */
    private function viewData(object $page): array
    {
        return (new ReflectionMethod($page, 'getViewData'))->invoke($page);
    }

    /** @param array<string, mixed> $translations
     * @return list<string>
     */
    private function translationKeys(array $translations, string $prefix = ''): array
    {
        $keys = [];
        foreach ($translations as $key => $value) {
            $path = $prefix.$key;
            if (is_array($value)) {
                array_push($keys, ...$this->translationKeys($value, $path.'.'));
            } else {
                $keys[] = $path;
            }
        }
        sort($keys);

        return $keys;
    }
}
