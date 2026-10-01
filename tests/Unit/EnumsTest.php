<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quraba\Backup\Domain\StatusMachine;
use Quraba\Backup\Enums\ArtifactKind;
use Quraba\Backup\Enums\ArtifactStatus;
use Quraba\Backup\Enums\ArtifactStorage;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Enums\BackupStatus;
use Quraba\Backup\Enums\BackupTrigger;
use Quraba\Backup\Enums\ConsistencyLevel;
use Quraba\Backup\Enums\HealthState;
use Quraba\Backup\Enums\MaintenanceOperation;
use Quraba\Backup\Enums\MaintenanceStatus;
use Quraba\Backup\Enums\RestoreMode;
use Quraba\Backup\Enums\RestoreProfile;
use Quraba\Backup\Enums\RestoreStatus;

final class EnumsTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<\BackedEnum>, list<string>}>
     */
    public static function persistedValues(): iterable
    {
        yield 'BackupProfile' => [BackupProfile::class, ['database', 'media', 'recovery']];
        yield 'BackupTrigger' => [BackupTrigger::class, ['scheduled', 'manual', 'pre_restore', 'api']];
        yield 'BackupStatus' => [BackupStatus::class, ['pending', 'preflighting', 'running', 'verifying', 'partial', 'completed', 'failed', 'canceled', 'indeterminate']];
        yield 'ArtifactKind' => [ArtifactKind::class, ['application_archive', 'restic_snapshot', 'remote_manifest']];
        yield 'ArtifactStatus' => [ArtifactStatus::class, ['pending', 'creating', 'uploading', 'verifying', 'verified', 'failed', 'expired']];
        yield 'ArtifactStorage' => [ArtifactStorage::class, ['b2_archive', 'restic', 'b2_manifest']];
        yield 'ConsistencyLevel' => [ConsistencyLevel::class, ['none', 'best_effort', 'quiesced']];
        yield 'RestoreMode' => [RestoreMode::class, ['dry_run', 'restore']];
        yield 'RestoreProfile' => [RestoreProfile::class, ['database', 'media', 'full']];
        yield 'RestoreStatus' => [RestoreStatus::class, ['pending', 'resolving', 'reconstructing', 'validating', 'safety_backup', 'quiescing', 'applying', 'verifying', 'completed', 'failed', 'indeterminate', 'abandoned']];
        yield 'HealthState' => [HealthState::class, ['healthy', 'degraded', 'failed', 'unknown']];
        yield 'MaintenanceOperation' => [MaintenanceOperation::class, ['reconciliation', 'retention', 'restic_check', 'restic_prune', 'catalog_rebuild']];
    }

    /**
     * @param  class-string<\BackedEnum>  $enum
     * @param  list<string>  $values
     */
    #[DataProvider('persistedValues')]
    public function test_persisted_values_are_stable(string $enum, array $values): void
    {
        self::assertSame($values, array_map(static fn (\BackedEnum $case): string|int => $case->value, $enum::cases()));
    }

    /**
     * @return iterable<string, array{StatusMachine, StatusMachine, bool}>
     */
    public static function backupTransitions(): iterable
    {
        yield 'pending -> preflighting' => [BackupStatus::Pending, BackupStatus::Preflighting, true];
        yield 'pending -> canceled' => [BackupStatus::Pending, BackupStatus::Canceled, true];
        yield 'preflighting -> running' => [BackupStatus::Preflighting, BackupStatus::Running, true];
        yield 'running -> verifying' => [BackupStatus::Running, BackupStatus::Verifying, true];
        yield 'running -> indeterminate' => [BackupStatus::Running, BackupStatus::Indeterminate, true];
        yield 'verifying -> completed' => [BackupStatus::Verifying, BackupStatus::Completed, true];
        yield 'verifying -> partial' => [BackupStatus::Verifying, BackupStatus::Partial, true];
        yield 'pending -> completed (skips verification)' => [BackupStatus::Pending, BackupStatus::Completed, false];
        yield 'running -> completed (skips verification)' => [BackupStatus::Running, BackupStatus::Completed, false];
        yield 'running -> canceled (artifacts may exist)' => [BackupStatus::Running, BackupStatus::Canceled, false];
        yield 'failed -> completed' => [BackupStatus::Failed, BackupStatus::Completed, false];
        yield 'completed -> failed' => [BackupStatus::Completed, BackupStatus::Failed, false];
        yield 'indeterminate -> completed (needs reconciliation)' => [BackupStatus::Indeterminate, BackupStatus::Completed, false];
        yield 'partial -> completed' => [BackupStatus::Partial, BackupStatus::Completed, false];
        yield 'artifact pending -> verifying (adoption)' => [ArtifactStatus::Pending, ArtifactStatus::Verifying, true];
        yield 'artifact pending -> verified' => [ArtifactStatus::Pending, ArtifactStatus::Verified, false];
        yield 'artifact failed -> verified' => [ArtifactStatus::Failed, ArtifactStatus::Verified, false];
        yield 'artifact verified -> expired' => [ArtifactStatus::Verified, ArtifactStatus::Expired, true];
        yield 'artifact failed -> expired' => [ArtifactStatus::Failed, ArtifactStatus::Expired, false];
        yield 'restore validating -> completed (dry run end)' => [RestoreStatus::Validating, RestoreStatus::Completed, true];
        yield 'restore applying -> indeterminate' => [RestoreStatus::Applying, RestoreStatus::Indeterminate, true];
        yield 'restore pending -> applying' => [RestoreStatus::Pending, RestoreStatus::Applying, false];
        yield 'restore validating -> applying (skips safety)' => [RestoreStatus::Validating, RestoreStatus::Applying, false];
        yield 'maintenance running -> completed' => [MaintenanceStatus::Running, MaintenanceStatus::Completed, true];
        yield 'maintenance completed -> running' => [MaintenanceStatus::Completed, MaintenanceStatus::Running, false];
        yield 'cross-enum transition' => [BackupStatus::Running, ArtifactStatus::Verifying, false];
    }

    #[DataProvider('backupTransitions')]
    public function test_transition_table(StatusMachine $from, StatusMachine $to, bool $allowed): void
    {
        self::assertSame($allowed, $from->canTransitionTo($to));
    }

    public function test_terminal_states_have_no_transitions(): void
    {
        foreach ([BackupStatus::Completed, BackupStatus::Failed, BackupStatus::Partial, BackupStatus::Canceled, BackupStatus::Indeterminate] as $status) {
            self::assertTrue($status->isTerminal(), $status->value);

            foreach (BackupStatus::cases() as $next) {
                self::assertFalse($status->canTransitionTo($next));
            }
        }

        self::assertFalse(BackupStatus::Pending->isTerminal());
        self::assertTrue(ArtifactStatus::Expired->isTerminal());
        self::assertTrue(RestoreStatus::Indeterminate->isTerminal());
    }

    public function test_initial_states(): void
    {
        self::assertSame(BackupStatus::Pending, BackupStatus::initial());
        self::assertSame(ArtifactStatus::Pending, ArtifactStatus::initial());
        self::assertSame(RestoreStatus::Pending, RestoreStatus::initial());
        self::assertSame(MaintenanceStatus::Pending, MaintenanceStatus::initial());
    }

    public function test_artifact_kinds_map_to_exactly_one_storage(): void
    {
        self::assertSame(ArtifactStorage::B2Archive, ArtifactKind::ApplicationArchive->storage());
        self::assertSame(ArtifactStorage::Restic, ArtifactKind::ResticSnapshot->storage());
        self::assertSame(ArtifactStorage::B2Manifest, ArtifactKind::RemoteManifest->storage());
    }

    public function test_unknown_health_never_counts_as_healthy(): void
    {
        self::assertSame(HealthState::Unknown, HealthState::Healthy->worst(HealthState::Unknown));
        self::assertSame(HealthState::Unknown, HealthState::Degraded->worst(HealthState::Unknown));
        self::assertSame(HealthState::Failed, HealthState::Unknown->worst(HealthState::Failed));
        self::assertFalse(HealthState::Unknown->isEstablished());
        self::assertTrue(HealthState::Degraded->isEstablished());
    }

    public function test_live_only_restore_states(): void
    {
        self::assertTrue(RestoreStatus::Applying->isLiveOnly());
        self::assertTrue(RestoreStatus::SafetyBackup->isLiveOnly());
        self::assertTrue(RestoreStatus::Quiescing->isLiveOnly());
        self::assertFalse(RestoreStatus::Validating->isLiveOnly());
    }
}
