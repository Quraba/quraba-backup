<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Quraba\Backup\Backup\PendingBackupRequest;
use Quraba\Backup\Consistency\QuiescenceSession;
use Quraba\Backup\Contracts\PendingOperationActorResolver;
use Quraba\Backup\Contracts\QuiescenceProvider;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Enums\PendingOperationStatus;
use Quraba\Backup\Enums\PendingOperationType;
use Quraba\Backup\Enums\RestoreProfile;
use Quraba\Backup\Exceptions\ConfigurationException;
use Quraba\Backup\Exceptions\RestoreFailed;
use Quraba\Backup\Models\BackupMaintenanceRun;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Models\BackupSetting;
use Quraba\Backup\Models\PendingOperation;
use Quraba\Backup\Models\RestoreRun;
use Quraba\Backup\Operations\ConsumedLiveApproval;
use Quraba\Backup\Operations\LiveApprovalStore;
use Quraba\Backup\Operations\OperationLease;
use Quraba\Backup\Operations\PendingOperationProcessor;
use Quraba\Backup\Operations\PendingOperationRequest;
use Quraba\Backup\Operations\WorkerHeartbeat;
use Quraba\Backup\Restore\Journal\RestoreJournalStore;
use Quraba\Backup\Restore\Live\LiveRestoreAuthorization;
use Quraba\Backup\Restore\Live\LiveRestoreService;
use Quraba\Backup\Scheduling\BackupScheduler;
use Quraba\Backup\Scheduling\ScheduleSettings;
use Quraba\Backup\Support\PackagePaths;
use Quraba\Backup\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class FilamentOperationsTest extends TestCase
{
    public function test_manual_backup_requests_are_pending_and_duplicates_are_refused(): void
    {
        $this->config()->set('quraba-backup.filament.pending_enabled', true);
        $requests = $this->app->make(PendingBackupRequest::class);

        foreach (BackupProfile::cases() as $profile) {
            self::assertSame($profile, $requests->request($profile)->profile);
        }
        self::assertSame(3, BackupRun::query()->where('status', 'pending')->count());
        $this->expectException(\DomainException::class);
        $requests->request(BackupProfile::Database);
    }

    public function test_dry_restore_request_only_records_work_and_denies_without_permission(): void
    {
        $this->config()->set('quraba-backup.filament.pending_enabled', true);
        $actor = new GenericUser(['id' => 7]);
        $source = '6f614a0b-c447-4e36-9758-347858cbb46b';
        $request = $this->app->make(PendingOperationRequest::class);
        try {
            $request->submit(PendingOperationType::DryRestore, $actor, $source, RestoreProfile::Full);
            self::fail('Permission should deny by default.');
        } catch (AccessDeniedHttpException) {
            self::assertSame(0, PendingOperation::query()->count());
        }

        $this->config()->set('quraba-backup.filament.authorization.dry-restore', static fn (): bool => true);
        $operation = $request->submit(PendingOperationType::DryRestore, $actor, $source, RestoreProfile::Full);
        self::assertSame(PendingOperationStatus::Pending, $operation->status);
        foreach (['requested_at', 'created_at', 'updated_at'] as $timestamp) {
            self::assertNotNull($operation->{$timestamp}, $timestamp.' should remain a readable UTC timestamp');
        }
        self::assertSame(0, RestoreRun::query()->count());
    }

    public function test_database_only_live_operation_cannot_pass_private_approval_gate(): void
    {
        $this->config()->set('quraba-backup.filament.pending_enabled', true);
        $this->config()->set('quraba-backup.filament.live_restore_enabled', true);
        $this->config()->set('quraba-backup.filament.authorization.live-restore', static fn (): bool => true);
        $actor = new GenericUser(['id' => 7]);
        $this->app->instance(PendingOperationActorResolver::class, new class($actor) implements PendingOperationActorResolver
        {
            public function __construct(private Authenticatable $actor) {}

            public function resolve(string $type, string $id): ?Authenticatable
            {
                return $this->actor;
            }
        });
        $this->app->forgetInstance(PendingOperationProcessor::class);
        $operation = PendingOperation::request(PendingOperationType::LiveRestore, $actor::class, '7', self::APP_ID, RestoreProfile::Full, 'database-only', hash('sha256', 'nonce'));

        $this->app->make(PendingOperationProcessor::class)->runOne();

        self::assertSame(PendingOperationStatus::Failed, $operation->refresh()->status);
        self::assertSame(0, RestoreRun::query()->count());
    }

    public function test_private_live_approval_is_bound_and_consumed_once(): void
    {
        $nonce = bin2hex(random_bytes(32));
        $operation = PendingOperation::request(PendingOperationType::LiveRestore, GenericUser::class, '7', self::APP_ID, RestoreProfile::Full, 'private-approval', hash('sha256', $nonce));
        $store = $this->app->make(LiveApprovalStore::class);
        $store->issue($operation, $nonce);
        $proof = ConsumedLiveApproval::consume($operation, $this->app->make(PackagePaths::class));
        self::assertSame($operation->uuid, $proof->operationUuid);
        LiveRestoreAuthorization::fromConsumedApproval($proof);
        try {
            LiveRestoreAuthorization::fromConsumedApproval($proof);
            self::fail('One consumed proof must not issue authorization twice.');
        } catch (\RuntimeException) {
            self::assertTrue(true);
        }
        $this->expectException(\RuntimeException::class);
        ConsumedLiveApproval::consume($operation, $this->app->make(PackagePaths::class));
    }

    public function test_live_approval_rejects_changed_source_and_expiry(): void
    {
        $nonce = bin2hex(random_bytes(32));
        $operation = PendingOperation::request(PendingOperationType::LiveRestore, GenericUser::class, '7', self::APP_ID, RestoreProfile::Full, 'approval-binding', hash('sha256', $nonce));
        $paths = $this->app->make(PackagePaths::class);
        $this->app->make(LiveApprovalStore::class)->issue($operation, $nonce);
        $operation->source_run_uuid = '40af267c-9779-4245-b292-0807063af224';
        try {
            ConsumedLiveApproval::consume($operation, $paths);
            self::fail('A changed source must be rejected.');
        } catch (\RuntimeException) {
            self::assertFileExists($paths->root.'/approvals/'.$operation->uuid.'.json');
        }
        $operation->source_run_uuid = self::APP_ID;
        $path = $paths->root.'/approvals/'.$operation->uuid.'.json';
        $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $data['expires_at'] = now('UTC')->subMinute()->toIso8601String();
        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
        $this->expectException(\RuntimeException::class);
        ConsumedLiveApproval::consume($operation, $paths);
    }

    public function test_queued_authorization_refuses_different_source_and_scopes_at_service_boundary(): void
    {
        $nonce = bin2hex(random_bytes(32));
        $operation = PendingOperation::request(PendingOperationType::LiveRestore, GenericUser::class, '7', self::APP_ID, RestoreProfile::Full, 'service-binding', hash('sha256', $nonce));
        $this->app->make(LiveApprovalStore::class)->issue($operation, $nonce);
        $proof = ConsumedLiveApproval::consume($operation, $this->app->make(PackagePaths::class));
        $authorization = LiveRestoreAuthorization::fromConsumedApproval($proof);

        foreach ([['40af267c-9779-4245-b292-0807063af224', RestoreProfile::Full], [self::APP_ID, RestoreProfile::Database], [self::APP_ID, RestoreProfile::Media]] as [$source, $profile]) {
            try {
                $this->app->make(LiveRestoreService::class)->run($source, $profile, $authorization);
                self::fail('A mismatched queued restore must be refused.');
            } catch (RestoreFailed $exception) {
                self::assertSame('restore.confirmation_required', $exception->failureCode());
            }
        }
        self::assertSame([], $this->app->make(RestoreJournalStore::class)->all()['journals']);
        self::assertSame(0, RestoreRun::query()->count());
    }

    public function test_consumed_request_without_readable_terminal_journal_remains_indeterminate(): void
    {
        $nonce = bin2hex(random_bytes(32));
        $operation = PendingOperation::request(PendingOperationType::LiveRestore, GenericUser::class, '7', self::APP_ID, RestoreProfile::Full, 'uncertain-journal', hash('sha256', $nonce));
        $this->app->make(LiveApprovalStore::class)->issue($operation, $nonce);
        ConsumedLiveApproval::consume($operation, $this->app->make(PackagePaths::class));

        $outcome = (new \ReflectionMethod(PendingOperationProcessor::class, 'journalOutcome'))->invoke($this->app->make(PendingOperationProcessor::class), $operation);

        self::assertSame(PendingOperationStatus::Indeterminate, $outcome['state']);
        self::assertSame('indeterminate', $outcome['result']['status']);
    }

    public function test_live_request_requires_exact_phrase_and_never_persists_it(): void
    {
        $this->config()->set('quraba-backup.filament.pending_enabled', true);
        $this->config()->set('quraba-backup.filament.live_restore_enabled', true);
        $this->config()->set('quraba-backup.filament.authorization.live-restore', static fn (): bool => true);
        $this->app->instance(QuiescenceProvider::class, new class implements QuiescenceProvider
        {
            public function name(): string
            {
                return 'test';
            }

            public function claimsQuiescence(): bool
            {
                return true;
            }

            public function enter(): QuiescenceSession
            {
                throw new \LogicException('The HTTP request must not enter quiescence.');
            }
        });
        $dry = PendingOperation::request(PendingOperationType::DryRestore, GenericUser::class, '7', self::APP_ID, RestoreProfile::Full, 'dry-evidence');
        $dry->move(PendingOperationStatus::Pending, PendingOperationStatus::Claimed);
        $dry->move(PendingOperationStatus::Claimed, PendingOperationStatus::Running);
        $dry->finish(PendingOperationStatus::Completed, ['ok' => true, 'blockers' => []]);
        $actor = new GenericUser(['id' => 7]);
        $requests = $this->app->make(PendingOperationRequest::class);
        $phrase = LiveRestoreAuthorization::phrase($this->config());
        foreach ([' '.$phrase, strtolower($phrase), 'incorrect'] as $wrong) {
            try {
                $requests->submit(PendingOperationType::LiveRestore, $actor, self::APP_ID, RestoreProfile::Full, $wrong);
                self::fail('A nonexact confirmation must be rejected.');
            } catch (\DomainException) {
                self::assertSame(0, PendingOperation::query()->where('type', PendingOperationType::LiveRestore->value)->count());
            }
        }
        $operation = $requests->submit(PendingOperationType::LiveRestore, $actor, self::APP_ID, RestoreProfile::Full, $phrase);
        self::assertSame(PendingOperationStatus::Pending, $operation->status);
        self::assertStringNotContainsString($phrase, json_encode($operation->getAttributes(), JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($phrase, json_encode($operation->toArray(), JSON_THROW_ON_ERROR));
        $approval = file_get_contents($this->app->make(PackagePaths::class)->root.'/approvals/'.$operation->uuid.'.json');
        self::assertIsString($approval);
        self::assertStringNotContainsString($phrase, $approval);
    }

    public function test_worker_does_not_claim_pending_operation_with_held_lease(): void
    {
        $operation = PendingOperation::request(PendingOperationType::Doctor, GenericUser::class, '7', null, null, 'leased');
        $lease = $this->app->make(OperationLease::class)->acquire($operation->uuid);
        try {
            self::assertNull($this->app->make(PendingOperationProcessor::class)->runOne());
            self::assertSame(PendingOperationStatus::Pending, $operation->refresh()->status);
        } finally {
            flock($lease, LOCK_UN);
            fclose($lease);
        }
    }

    public function test_operation_lease_rejects_non_uuid_file_names(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->app->make(OperationLease::class)->acquire('../escape');
    }

    public function test_worker_rechecks_queued_actor_permission(): void
    {
        $this->config()->set('quraba-backup.filament.pending_enabled', true);
        $actor = new GenericUser(['id' => 7]);
        $this->app->instance(PendingOperationActorResolver::class, new class($actor) implements PendingOperationActorResolver
        {
            public function __construct(private Authenticatable $actor) {}

            public function resolve(string $type, string $id): ?Authenticatable
            {
                return $this->actor;
            }
        });
        $operation = PendingOperation::request(PendingOperationType::Doctor, GenericUser::class, '7', null, null, 'permission-recheck');
        $this->app->make(PendingOperationProcessor::class)->runOne();
        self::assertSame(PendingOperationStatus::Failed, $operation->refresh()->status);
        self::assertSame('operation.failed', $operation->failure_code);
        self::assertSame(0, BackupMaintenanceRun::query()->count());
    }

    public function test_state_update_cannot_touch_reused_row_id_after_database_replacement(): void
    {
        $operation = PendingOperation::request(PendingOperationType::LiveRestore, GenericUser::class, '7', self::APP_ID, RestoreProfile::Full, 'old-database');
        $operation->move(PendingOperationStatus::Pending, PendingOperationStatus::Claimed);
        $operation->move(PendingOperationStatus::Claimed, PendingOperationStatus::Running);
        $replacementUuid = '17e80943-a710-4cb6-af7f-2740d26f92c2';
        PendingOperation::query()->whereKey($operation->id)->update(['uuid' => $replacementUuid]);

        $operation->finish(PendingOperationStatus::Completed, ['ok' => true]);

        $replaced = PendingOperation::query()->whereKey($operation->id)->firstOrFail();
        self::assertSame($replacementUuid, $replaced->uuid);
        self::assertSame(PendingOperationStatus::Running, $replaced->status);
        self::assertNull($replaced->result);
    }

    public function test_schedule_overrides_validate_and_reset_to_config(): void
    {
        $settings = $this->app->make(ScheduleSettings::class);
        self::assertSame('config', $settings->effective('database')['source']);
        $settings->set('database', ['enabled' => true, 'frequency' => 'weekly', 'day' => 2, 'time' => '04:15']);
        self::assertSame('database', $settings->effective('database')['source']);
        $definitions = $this->app->make(BackupScheduler::class)->definitions();
        self::assertSame('weekly on day 2 at 04:15', $definitions[0]->describe());
        $settings->reset('database');
        self::assertSame('config', $settings->effective('database')['source']);
        BackupSetting::put('schedule.database', ['enabled' => true, 'frequency' => 'weekly', 'day' => 99, 'time' => '04:15']);
        $this->expectException(ConfigurationException::class);
        $settings->effective('database');
    }

    public function test_schedule_rejects_invalid_time_day_and_timezone(): void
    {
        $settings = $this->app->make(ScheduleSettings::class);
        foreach ([
            ['database', ['enabled' => true, 'frequency' => 'daily', 'day' => null, 'time' => '02:03']],
            ['media', ['enabled' => true, 'frequency' => 'weekly', 'day' => 7, 'time' => '02:05']],
            ['recovery', ['enabled' => true, 'frequency' => 'monthly', 'day' => 29, 'time' => '02:05']],
            ['timezone', 'Invalid/Timezone'],
        ] as [$key, $value]) {
            try {
                $settings->set($key, $value);
                self::fail('Invalid schedule input was accepted.');
            } catch (ConfigurationException) {
                self::assertSame('config', $settings->effective($key)['source']);
            }
        }
    }

    public function test_panel_live_restore_is_disabled_by_default(): void
    {
        self::assertFalse((bool) $this->config()->get('quraba-backup.filament.live_restore_enabled'));
        self::assertFalse((bool) $this->config()->get('quraba-backup.filament.pending_enabled'));
    }

    public function test_heartbeat_reports_actual_worker_observation(): void
    {
        $heartbeat = $this->app->make(WorkerHeartbeat::class);
        self::assertNull($heartbeat->observedAt());
        $heartbeat->beat();
        self::assertNotNull($heartbeat->observedAt());
        file_put_contents($this->app->make(PackagePaths::class)->root.'/runtime/worker-heartbeat.json', '{"observed_at":"not a date"}');
        self::assertFalse($heartbeat->recentlyObserved());
    }
}
