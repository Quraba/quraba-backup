<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Feature;

use Carbon\CarbonImmutable;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\FilamentManager;
use Filament\Panel;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Auth\GenericUser;
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
use Quraba\Backup\Enums\RestoreProfile;
use Quraba\Backup\Enums\RestoreStatus;
use Quraba\Backup\Filament\OperatorStatus;
use Quraba\Backup\Filament\Pages\BackupDashboard;
use Quraba\Backup\Filament\Pages\BackupOperations;
use Quraba\Backup\Filament\Pages\BackupRuns;
use Quraba\Backup\Filament\Pages\HealthMaintenance;
use Quraba\Backup\Filament\Pages\Restore;
use Quraba\Backup\Filament\RestoreSources;
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
        self::assertSame('تفعيل جداول النسخ', Ui::value('enabled', 'schedule'));
        self::assertSame('أسبوعياً يوم الأحد عند 03:30', Ui::scheduleSetting(['enabled' => true, 'frequency' => 'weekly', 'day' => 0, 'time' => '03:30']));

        $this->app->setLocale('en');
        self::assertSame('Weekly on Sunday at 03:30', Ui::scheduleSetting(['enabled' => true, 'frequency' => 'weekly', 'day' => 0, 'time' => '03:30']));
    }

    public function test_recovery_point_detail_dates_and_maintenance_mode_are_localized(): void
    {
        $this->config()->set('app.timezone', 'Asia/Aden');
        $time = CarbonImmutable::parse('2026-10-04 08:45:29', 'UTC');

        $this->app->setLocale('ar');
        self::assertSame('4 أكتوبر 2026 11:45', Ui::dateTime($time));
        self::assertSame('للقراءة فقط', Ui::maintenanceMode(true));
        self::assertSame('قابل للتنفيذ', Ui::maintenanceMode(false));

        $this->app->setLocale('en');
        self::assertSame('4 Oct 2026 11:45', Ui::dateTime($time));
        self::assertSame('—', Ui::dateTime(null));
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

    public function test_dashboard_exposes_concise_translated_operator_summary(): void
    {
        $this->app->setLocale('ar');
        $data = $this->viewData(new BackupDashboard);
        self::assertSame(['full', 'database', 'media'], array_keys($data['latestUsable']));
        self::assertSame('أحدث نسخة موقع كاملة', Ui::text('operator.latest_full'));
        self::assertSame('لم يُفحص بعد', Ui::text('empty_states.not_checked'));
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
        self::assertSame(['آخر نسخة ناجحة', 'لقطة الوسائط', 'نسخة قاعدة البيانات', 'نسخة موقع كاملة'], $labels);
    }

    public function test_health_has_one_primary_check_and_secondary_actions(): void
    {
        $groups = (new ReflectionMethod(new HealthMaintenance, 'getHeaderActions'))->invoke(new HealthMaintenance);
        self::assertIsArray($groups);
        self::assertCount(3, $groups);
        self::assertSame('check_now', $groups[0]->getName());
        self::assertInstanceOf(ActionGroup::class, $groups[1]);
        self::assertInstanceOf(ActionGroup::class, $groups[2]);
        self::assertCount(3, $groups[1]->getActions());
        self::assertCount(2, $groups[2]->getActions());
    }

    public function test_restore_sources_are_scope_aware_and_keep_exact_uuid_values(): void
    {
        $database = $this->completedBackup(BackupProfile::Database, [ArtifactKind::ApplicationArchive]);
        $media = $this->completedBackup(BackupProfile::Media, [ArtifactKind::ResticSnapshot]);
        $partialFull = $this->completedBackup(BackupProfile::Recovery, [ArtifactKind::ApplicationArchive]);
        $full = $this->completedBackup(BackupProfile::Recovery, [ArtifactKind::ApplicationArchive, ArtifactKind::ResticSnapshot]);
        $sources = $this->app->make(RestoreSources::class);

        self::assertTrue($sources->contains($database->uuid, RestoreProfile::Database));
        self::assertTrue($sources->contains($partialFull->uuid, RestoreProfile::Database));
        self::assertTrue($sources->contains($full->uuid, RestoreProfile::Database));
        self::assertFalse($sources->contains($media->uuid, RestoreProfile::Database));
        self::assertTrue($sources->contains($media->uuid, RestoreProfile::Media));
        self::assertTrue($sources->contains($full->uuid, RestoreProfile::Media));
        self::assertFalse($sources->contains($database->uuid, RestoreProfile::Media));
        self::assertTrue($sources->contains($full->uuid, RestoreProfile::Full));
        self::assertFalse($sources->contains($partialFull->uuid, RestoreProfile::Full));
        self::assertFalse($sources->contains($database->uuid, RestoreProfile::Full));
        self::assertFalse($sources->contains($media->uuid, RestoreProfile::Full));

        $options = (new ReflectionMethod(new Restore, 'sourceOptions'))->invoke(new Restore, 'database', $database->uuid);
        self::assertArrayHasKey($database->uuid, $options);
        self::assertStringNotContainsString($database->uuid, $options[$database->uuid]);
    }

    public function test_inline_backup_check_uses_the_same_exact_source_eligibility(): void
    {
        $this->config()->set('quraba-backup.filament.pending_enabled', true);
        $this->config()->set('quraba-backup.filament.authorization.dry-restore', static fn (): bool => true);
        $database = $this->completedBackup(BackupProfile::Database, [ArtifactKind::ApplicationArchive]);
        $full = $this->completedBackup(BackupProfile::Recovery, [ArtifactKind::ApplicationArchive, ArtifactKind::ResticSnapshot]);
        $page = new Restore;
        $page->restoreScope = RestoreProfile::Full->value;
        $page->restoreSourceUuid = $database->uuid;
        $page->checkSelectedBackup();
        self::assertSame(0, PendingOperation::query()->where('type', PendingOperationType::DryRestore->value)->count());

        $page->restoreSourceUuid = $full->uuid;
        $page->checkSelectedBackup();
        $operation = PendingOperation::query()->where('type', PendingOperationType::DryRestore->value)->sole();
        self::assertSame($full->uuid, $operation->source_run_uuid);
        self::assertSame(RestoreProfile::Full, $operation->restore_profile);
        self::assertSame($operation->uuid, $page->checkUuid);
    }

    public function test_restore_now_requires_a_recent_successful_check_without_blockers(): void
    {
        $this->config()->set('quraba-backup.filament.pending_enabled', true);
        $source = $this->completedBackup(BackupProfile::Database, [ArtifactKind::ApplicationArchive]);
        $page = new Restore;
        $canRestore = new ReflectionMethod($page, 'canRestoreNow');
        $check = PendingOperation::request(PendingOperationType::DryRestore, GenericUser::class, '1', $source->uuid, RestoreProfile::Database, 'check-1');
        $page->checkUuid = $check->uuid;
        self::assertFalse($canRestore->invoke($page));
        $check->move(PendingOperationStatus::Pending, PendingOperationStatus::Claimed);
        $check->move(PendingOperationStatus::Claimed, PendingOperationStatus::Running);
        $check->finish(PendingOperationStatus::Completed, ['ok' => true, 'blockers' => ['unsafe']]);
        self::assertFalse($canRestore->invoke($page));
        $check->result = ['ok' => true, 'blockers' => []];
        $check->finished_at = now('UTC')->subDays(2);
        $check->save();
        self::assertFalse($canRestore->invoke($page));
        $check->finished_at = now('UTC');
        $check->save();
        self::assertTrue($canRestore->invoke($page));
        PendingOperation::request(PendingOperationType::DryRestore, GenericUser::class, '1', $source->uuid, RestoreProfile::Database, 'check-2');
        self::assertFalse($canRestore->invoke($page));
    }

    public function test_restore_journal_stages_and_findings_are_operator_language(): void
    {
        $this->app->setLocale('ar');
        self::assertSame('جارٍ استعادة قاعدة البيانات', OperatorStatus::restoreStage(['phase' => 'db_import_starting']));
        self::assertSame('جارٍ استعادة الملفات', OperatorStatus::restoreStage(['phase' => 'media_applying']));
        self::assertSame('توقفت الاستعادة بأمان قبل استبدال أي بيانات حالية.', OperatorStatus::restoreStage(['phase' => 'validated', 'terminal' => 'failed', 'destructive_started_at' => null]));
        self::assertSame(Ui::text('errors.app_key'), OperatorStatus::finding('The backup APP_KEY fingerprint differs from the current application.'));
    }

    public function test_guided_live_action_requires_both_operator_acknowledgements(): void
    {
        $this->config()->set('quraba-backup.filament.pending_enabled', true);
        $this->config()->set('quraba-backup.filament.live_restore_enabled', true);
        $this->config()->set('quraba-backup.filament.authorization.live-restore', static fn (): bool => true);
        $source = $this->completedBackup(BackupProfile::Database, [ArtifactKind::ApplicationArchive]);
        $check = PendingOperation::request(PendingOperationType::DryRestore, GenericUser::class, '1', $source->uuid, RestoreProfile::Database, 'guided-check');
        $check->move(PendingOperationStatus::Pending, PendingOperationStatus::Claimed);
        $check->move(PendingOperationStatus::Claimed, PendingOperationStatus::Running);
        $check->finish(PendingOperationStatus::Completed, ['ok' => true, 'blockers' => []]);
        $page = new Restore;
        $page->checkUuid = $check->uuid;
        (new ReflectionMethod($page, 'submitLive'))->invoke($page, ['acknowledge_replacement' => true, 'acknowledge_maintenance' => false, 'confirmation' => 'anything']);
        self::assertSame(0, PendingOperation::query()->where('type', PendingOperationType::LiveRestore->value)->count());
    }

    /** @param list<ArtifactKind> $kinds */
    private function completedBackup(BackupProfile $profile, array $kinds): BackupRun
    {
        $run = BackupRun::request($profile, BackupTrigger::Manual);
        BackupRun::query()->whereKey($run->id)->update(['status' => BackupStatus::Completed->value]);
        foreach ($kinds as $kind) {
            $artifact = BackupArtifact::createFor($run, $kind);
            BackupArtifact::query()->whereKey($artifact->id)->update(['status' => ArtifactStatus::Verified->value]);
        }

        return $run->refresh();
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
