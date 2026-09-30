<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Feature\Catalog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Quraba\Backup\Domain\FailureDetails;
use Quraba\Backup\Enums\ArtifactKind;
use Quraba\Backup\Enums\ArtifactStatus;
use Quraba\Backup\Enums\ArtifactStorage;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Enums\BackupTrigger;
use Quraba\Backup\Enums\MaintenanceOperation;
use Quraba\Backup\Enums\MaintenanceStatus;
use Quraba\Backup\Enums\RestoreMode;
use Quraba\Backup\Enums\RestoreProfile;
use Quraba\Backup\Enums\RestoreStatus;
use Quraba\Backup\Exceptions\IllegalStateTransition;
use Quraba\Backup\Models\BackupMaintenanceRun;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Models\BackupSetting;
use Quraba\Backup\Models\RestoreRun;
use Quraba\Backup\Security\SecretRedactor;
use Quraba\Backup\Tests\TestCase;

final class ArtifactRestoreMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private const string SNAPSHOT = 'a3f5c0d1e2b4a3f5c0d1e2b4a3f5c0d1e2b4a3f5c0d1e2b4a3f5c0d1e2b4a3f5';

    private function failure(string $code = 'restic.command_failed'): FailureDetails
    {
        return FailureDetails::make($code, 'test.stage', 'failure', $this->app->make(SecretRedactor::class));
    }

    public function test_snapshot_artifact_verifies_only_with_full_id(): void
    {
        $run = BackupRun::request(BackupProfile::Media, BackupTrigger::Manual)->markPreflighting()->markRunning();
        $artifact = $run->addArtifact(ArtifactKind::ResticSnapshot)->markCreating()->markVerifying();

        self::assertSame(ArtifactStorage::Restic, $artifact->storage);

        try {
            $artifact->markVerifiedSnapshot('a3f5c0d1');
            self::fail('Short snapshot IDs must be refused.');
        } catch (InvalidArgumentException) {
            self::assertSame(ArtifactStatus::Verifying, $artifact->fresh()?->status);
        }

        $artifact->markVerifiedSnapshot(self::SNAPSHOT);

        $fresh = $artifact->fresh();
        self::assertSame(ArtifactStatus::Verified, $fresh?->status);
        self::assertSame(self::SNAPSHOT, $fresh?->snapshot_id);
        self::assertNotNull($fresh?->verified_at);
        self::assertSame($run->id, $fresh?->run->id);
    }

    public function test_object_artifact_requires_locator_checksum_and_size(): void
    {
        $run = BackupRun::request(BackupProfile::Database, BackupTrigger::Manual)->markPreflighting()->markRunning();
        $artifact = $run->addArtifact(ArtifactKind::ApplicationArchive)->markCreating();
        $artifact->assignLocator('quraba-backup/app/archives/2026/09/30/'.$run->uuid.'/application.zip');
        $artifact->markUploading()->markVerifying();

        foreach ([
            fn () => $artifact->markVerifiedObject((string) $artifact->locator, 'not-a-sha', 10),
            fn () => $artifact->markVerifiedObject((string) $artifact->locator, str_repeat('f', 64), 0),
            fn () => $artifact->markVerifiedSnapshot(self::SNAPSHOT),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('Incomplete identity must be refused.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        try {
            $artifact->markVerifiedObject('quraba-backup/other/application.zip', str_repeat('f', 64), 10);
            self::fail('A locator different from the pre-assigned one must be refused.');
        } catch (IllegalStateTransition) {
            self::addToAssertionCount(1);
        }

        $artifact->markVerifiedObject((string) $artifact->locator, str_repeat('f', 64), 1024);
        self::assertSame(ArtifactStatus::Verified, $artifact->fresh()?->status);
        self::assertSame(1024, $artifact->fresh()?->byte_size);
    }

    public function test_only_verified_artifacts_expire_and_failed_is_terminal(): void
    {
        $run = BackupRun::request(BackupProfile::Media, BackupTrigger::Manual);
        $artifact = $run->addArtifact(ArtifactKind::ResticSnapshot);

        try {
            $artifact->markExpired();
            self::fail('Pending artifacts cannot expire.');
        } catch (IllegalStateTransition) {
            self::addToAssertionCount(1);
        }

        $artifact->markFailed($this->failure());
        self::assertSame('restic.command_failed', $artifact->fresh()?->failure_code);

        $this->expectException(IllegalStateTransition::class);
        $artifact->markVerifying();
    }

    public function test_terminal_runs_accept_no_new_artifacts(): void
    {
        $run = BackupRun::request(BackupProfile::Media, BackupTrigger::Manual)->markFailed($this->failure());

        $this->expectException(IllegalStateTransition::class);
        $run->addArtifact(ArtifactKind::ResticSnapshot);
    }

    public function test_dry_run_restore_can_never_enter_live_states(): void
    {
        $restore = RestoreRun::request(RestoreMode::DryRun, RestoreProfile::Full, '5ff081a8-503e-44ba-91ae-30cfef9b972f')
            ->markResolving()->markReconstructing()->markValidating();

        foreach ([
            fn () => $restore->markSafetyBackup('11111111-1111-4111-8111-111111111111'),
            fn () => $restore->markQuiescing(),
            fn () => $restore->markApplying(),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('Dry runs must not enter live-only states.');
            } catch (IllegalStateTransition) {
                self::addToAssertionCount(1);
            }
        }

        $restore->markCompleted();
        self::assertSame(RestoreStatus::Completed, $restore->fresh()?->status);
    }

    public function test_live_restore_requires_safety_backup_and_becomes_indeterminate_after_destruction(): void
    {
        $restore = RestoreRun::request(RestoreMode::Restore, RestoreProfile::Database, '5ff081a8-503e-44ba-91ae-30cfef9b972f', 'user', 7)
            ->markResolving();
        $restore->freezeSource('quraba-backup/app/archives/2026/09/30/x/application.zip', str_repeat('1', 64), null);

        try {
            $restore->freezeSource('other', str_repeat('2', 64), null);
            self::fail('Frozen sources cannot change.');
        } catch (IllegalStateTransition) {
            self::addToAssertionCount(1);
        }

        $restore->markReconstructing()->markValidating()->markQuiescing()
            ->markSafetyBackup('22222222-2222-4222-8222-222222222222')
            ->markApplying()
            ->markDestructiveStarted();

        try {
            $restore->markFailed($this->failure('database.import_failed'));
            self::fail('After the destructive boundary FAILED is not provable.');
        } catch (IllegalStateTransition) {
            self::addToAssertionCount(1);
        }

        $restore->markIndeterminate($this->failure('process.interrupted'));
        self::assertSame(RestoreStatus::Indeterminate, $restore->fresh()?->status);
        self::assertSame('7', $restore->fresh()?->requested_by_id);

        $restore->resolveIndeterminate(RestoreStatus::Failed, ['journal' => 'db import incomplete'], $this->failure('database.import_failed'));
        self::assertSame(RestoreStatus::Failed, $restore->fresh()?->status);
    }

    public function test_applying_without_safety_backup_is_refused(): void
    {
        $restore = RestoreRun::request(RestoreMode::Restore, RestoreProfile::Media, '5ff081a8-503e-44ba-91ae-30cfef9b972f')
            ->markResolving()->markReconstructing()->markValidating()->markQuiescing();

        $this->expectException(IllegalStateTransition::class);
        $restore->markApplying();
    }

    public function test_restore_sources_require_exact_identities(): void
    {
        $restore = RestoreRun::request(RestoreMode::DryRun, RestoreProfile::Media, '5ff081a8-503e-44ba-91ae-30cfef9b972f')->markResolving();

        $this->expectException(InvalidArgumentException::class);
        $restore->freezeSource(null, null, 'latest');
    }

    public function test_dry_run_maintenance_cannot_report_affected_items(): void
    {
        $run = BackupMaintenanceRun::plan(MaintenanceOperation::Retention, true, [['snapshot' => self::SNAPSHOT]])->markRunning();

        try {
            $run->markCompleted([['snapshot' => self::SNAPSHOT]]);
            self::fail('Dry runs cannot affect anything.');
        } catch (IllegalStateTransition) {
            self::addToAssertionCount(1);
        }

        $run->markCompleted();

        $fresh = $run->fresh();
        self::assertSame(MaintenanceStatus::Completed, $fresh?->status);
        self::assertTrue($fresh?->dry_run);
        self::assertSame([], $fresh?->affected_items);
        self::assertSame([['snapshot' => self::SNAPSHOT]], $fresh?->planned_items);
    }

    public function test_maintenance_indeterminate_resolution(): void
    {
        $run = BackupMaintenanceRun::plan(MaintenanceOperation::ResticPrune, false)
            ->markRunning()
            ->markIndeterminate($this->failure('process.interrupted'));

        $run->resolveIndeterminate(MaintenanceStatus::Completed, ['observed' => 'snapshots absent'], [['snapshot' => self::SNAPSHOT]]);

        self::assertSame(MaintenanceStatus::Completed, $run->fresh()?->status);
    }

    public function test_settings_refuse_secrets_and_paths(): void
    {
        BackupSetting::put('retention.database.keep_daily', 14);
        BackupSetting::put('schedule.recovery', ['weekly', 'sunday']);

        self::assertSame(14, BackupSetting::read('retention.database.keep_daily'));
        self::assertSame(['weekly', 'sunday'], BackupSetting::read('schedule.recovery'));
        self::assertSame('default', BackupSetting::read('health.missing', 'default'));

        foreach (['restic.password_file', 'b2.application_key', 'b2.key_id', 'archive.password', 'media.path', 'restic.binary', 'api_token', 'Bad Key'] as $key) {
            try {
                BackupSetting::put($key, 'x');
                self::fail(sprintf('Setting [%s] must be refused.', $key));
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        BackupSetting::put('health.threshold', new \stdClass);
    }
}
