<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Feature;

use Filament\Facades\Filament;
use Filament\FilamentManager;
use Filament\Panel;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Schema;
use Quraba\Backup\Enums\ArtifactKind;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Enums\BackupTrigger;
use Quraba\Backup\Enums\PendingOperationStatus;
use Quraba\Backup\Enums\PendingOperationType;
use Quraba\Backup\Filament\Pages\BackupDashboard;
use Quraba\Backup\Filament\Pages\BackupRuns;
use Quraba\Backup\Filament\Pages\HealthMaintenance;
use Quraba\Backup\Filament\Pages\Restore;
use Quraba\Backup\Models\BackupArtifact;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Models\PendingOperation;
use Quraba\Backup\Operations\WorkerHeartbeat;
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
        self::assertSame('N/A', $method->invoke($page, $database, ArtifactKind::ResticSnapshot));
        self::assertSame('N/A', $method->invoke($page, $media, ArtifactKind::ApplicationArchive));
        self::assertSame('Missing / failed', $method->invoke($page, $database, ArtifactKind::ApplicationArchive));
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
}
