<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Feature\Catalog;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Quraba\Backup\Domain\FailureDetails;
use Quraba\Backup\Domain\Identifiers;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Enums\BackupStatus;
use Quraba\Backup\Enums\BackupTrigger;
use Quraba\Backup\Enums\ConsistencyLevel;
use Quraba\Backup\Exceptions\IllegalStateTransition;
use Quraba\Backup\Exceptions\ResticAuthenticationFailed;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Security\SecretRedactor;
use Quraba\Backup\Tests\Support\Sentinels;
use Quraba\Backup\Tests\TestCase;

final class BackupRunTest extends TestCase
{
    use RefreshDatabase;

    private function failure(string $message = 'archive upload failed', string $code = 'archive.upload_failed'): FailureDetails
    {
        return FailureDetails::make($code, 'archive.upload', $message, $this->app->make(SecretRedactor::class));
    }

    public function test_request_creates_a_pending_run_with_uuid_and_enum_casts(): void
    {
        $run = BackupRun::request(BackupProfile::Recovery, BackupTrigger::Scheduled, ConsistencyLevel::BestEffort, ['note' => 'x']);
        $fresh = BackupRun::query()->findOrFail($run->id);

        self::assertTrue(Identifiers::isUuid($fresh->uuid));
        self::assertSame(BackupStatus::Pending, $fresh->status);
        self::assertSame(BackupProfile::Recovery, $fresh->profile);
        self::assertSame(BackupTrigger::Scheduled, $fresh->trigger);
        self::assertSame(ConsistencyLevel::BestEffort, $fresh->consistency);
        self::assertSame(['note' => 'x'], $fresh->metadata);
        self::assertNotNull($fresh->requested_at);
        self::assertNotSame($run->uuid, BackupRun::request(BackupProfile::Recovery, BackupTrigger::Scheduled)->uuid);
    }

    public function test_happy_path_transitions(): void
    {
        $run = BackupRun::request(BackupProfile::Database, BackupTrigger::Manual)
            ->markPreflighting()
            ->markRunning()
            ->markVerifying()
            ->markCompleted();

        $fresh = BackupRun::query()->findOrFail($run->id);
        self::assertSame(BackupStatus::Completed, $fresh->status);
        self::assertNotNull($fresh->started_at);
        self::assertNotNull($fresh->completed_at);
        self::assertNull($fresh->failure_code);
    }

    public function test_illegal_transitions_are_rejected_and_not_persisted(): void
    {
        $run = BackupRun::request(BackupProfile::Database, BackupTrigger::Manual);

        try {
            $run->markCompleted();
            self::fail('pending -> completed must be refused');
        } catch (IllegalStateTransition) {
            self::assertSame('pending', DB::table('quraba_backup_runs')->where('id', $run->id)->value('status'));
        }

        $run->markPreflighting()->markFailed($this->failure());

        $this->expectException(IllegalStateTransition::class);
        $run->markCompleted();
    }

    public function test_running_run_cannot_be_canceled(): void
    {
        $run = BackupRun::request(BackupProfile::Media, BackupTrigger::Manual)->markPreflighting()->markRunning();

        $this->expectException(IllegalStateTransition::class);
        $run->markCanceled($this->failure('operator canceled', 'operation.canceled'));
    }

    public function test_status_cannot_be_assigned_directly(): void
    {
        $run = BackupRun::request(BackupProfile::Database, BackupTrigger::Manual);

        foreach ([
            static fn () => $run->status = BackupStatus::Completed,
            static fn () => $run->forceFill(['status' => 'completed']),
            static fn () => $run->setAttribute('status', 'completed'),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('Direct status assignment must be refused.');
            } catch (IllegalStateTransition) {
                self::addToAssertionCount(1);
            }
        }

        // Mass assignment is additionally blocked (status is never fillable).
        try {
            $run->update(['status' => 'completed']);
        } catch (IllegalStateTransition|MassAssignmentException) {
            self::addToAssertionCount(1);
        }

        self::assertSame('pending', DB::table('quraba_backup_runs')->where('id', $run->id)->value('status'));
    }

    public function test_uuid_is_immutable(): void
    {
        $run = BackupRun::request(BackupProfile::Database, BackupTrigger::Manual);

        $this->expectException(IllegalStateTransition::class);
        $run->setAttribute('uuid', '11111111-1111-4111-8111-111111111111');
    }

    public function test_concurrent_transitions_cannot_both_win(): void
    {
        $run = BackupRun::request(BackupProfile::Database, BackupTrigger::Manual);
        $first = BackupRun::query()->findOrFail($run->id);
        $second = BackupRun::query()->findOrFail($run->id);

        $first->markPreflighting();

        try {
            $second->markCanceled($this->failure('late cancel', 'operation.canceled'));
            self::fail('A stale model must not overwrite a newer status.');
        } catch (IllegalStateTransition $exception) {
            self::assertStringContainsString('changed concurrently', $exception->getMessage());
        }

        self::assertSame('preflighting', DB::table('quraba_backup_runs')->where('id', $run->id)->value('status'));
    }

    public function test_failures_persist_code_and_sanitized_message_separately(): void
    {
        $exception = ResticAuthenticationFailed::storageCredentialsRejected(sprintf(
            "InvalidAccessKeyId for %s / %s\x07 with password %s",
            Sentinels::B2_KEY_ID,
            Sentinels::B2_SECRET,
            Sentinels::RESTIC_PASSWORD,
        ));

        $failure = FailureDetails::fromThrowable($exception, 'media.snapshot', $this->app->make(SecretRedactor::class));
        $run = BackupRun::request(BackupProfile::Media, BackupTrigger::Manual)->markPreflighting()->markFailed($failure);

        $row = (array) DB::table('quraba_backup_runs')->where('id', $run->id)->first();

        self::assertSame('restic.storage_credentials_rejected', $row['failure_code']);
        self::assertSame('media.snapshot', $row['failure_stage']);
        self::assertStringContainsString('InvalidAccessKeyId', (string) $row['failure_message']);
        self::assertStringNotContainsString("\x07", (string) $row['failure_message']);
        Sentinels::assertAbsent((string) json_encode($row), 'persisted run row');
        self::assertNotNull($row['failed_at']);
    }

    public function test_failure_messages_are_bounded(): void
    {
        $failure = $this->failure(str_repeat('x', 10000));

        self::assertSame(FailureDetails::MAX_MESSAGE_LENGTH, mb_strlen($failure->message));
    }

    public function test_failure_codes_must_be_machine_readable(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->failure('x', 'Not A Code!');
    }

    public function test_timestamps_are_stored_in_utc_regardless_of_app_timezone(): void
    {
        $this->config()->set('app.timezone', 'Asia/Riyadh');
        date_default_timezone_set('Asia/Riyadh');

        try {
            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-30 12:00:00', 'UTC'));
            $run = BackupRun::request(BackupProfile::Database, BackupTrigger::Manual)->markPreflighting();

            $row = (array) DB::table('quraba_backup_runs')->where('id', $run->id)->first();
            self::assertSame('2026-09-30 12:00:00', $row['requested_at']);
            self::assertSame('2026-09-30 12:00:00', $row['started_at']);
            self::assertSame('2026-09-30 12:00:00', $row['created_at']);

            $fresh = BackupRun::query()->findOrFail($run->id);
            self::assertSame('2026-09-30T12:00:00Z', $fresh->requested_at?->toIso8601ZuluString());
        } finally {
            CarbonImmutable::setTestNow();
            date_default_timezone_set('UTC');
        }
    }

    public function test_partial_keeps_component_failure(): void
    {
        $run = BackupRun::request(BackupProfile::Recovery, BackupTrigger::Scheduled)
            ->markPreflighting()->markRunning()->markVerifying()
            ->markPartial($this->failure('media snapshot failed', 'restic.command_failed'));

        self::assertSame(BackupStatus::Partial, $run->fresh()?->status);
        self::assertSame('restic.command_failed', $run->fresh()?->failure_code);
    }

    public function test_indeterminate_is_resolved_only_through_reconciliation_with_evidence(): void
    {
        $run = BackupRun::request(BackupProfile::Media, BackupTrigger::Manual)
            ->markPreflighting()->markRunning()
            ->markIndeterminate($this->failure('process died after restic backup', 'process.interrupted'));

        try {
            $run->resolveIndeterminate(BackupStatus::Completed, []);
            self::fail('Evidence is required.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        try {
            $run->resolveIndeterminate(BackupStatus::Running, ['snapshot_id' => str_repeat('e', 64)]);
            self::fail('Running is not a reconciliation outcome.');
        } catch (IllegalStateTransition) {
            self::addToAssertionCount(1);
        }

        $run->resolveIndeterminate(BackupStatus::Completed, ['snapshot_id' => str_repeat('e', 64), 'note' => 'key '.Sentinels::B2_SECRET]);
        $fresh = $run->fresh();

        self::assertSame(BackupStatus::Completed, $fresh?->status);
        self::assertSame(str_repeat('e', 64), $fresh?->metadata['reconciliation']['evidence']['snapshot_id'] ?? null);
        Sentinels::assertAbsent((string) json_encode($fresh?->metadata), 'reconciliation evidence');

        $this->expectException(IllegalStateTransition::class);
        $run->resolveIndeterminate(BackupStatus::Failed, ['x' => 'y'], $this->failure());
    }

    public function test_pins(): void
    {
        $run = BackupRun::request(BackupProfile::Recovery, BackupTrigger::PreRestore);
        $run->pin(CarbonImmutable::now('UTC')->addDays(30), 'pre-restore safety backup');

        self::assertTrue($run->fresh()?->isPinned());

        $run->unpin();
        self::assertFalse($run->fresh()?->isPinned());

        $this->expectException(InvalidArgumentException::class);
        $run->pin(CarbonImmutable::now('UTC'), ' ');
    }

    public function test_metadata_is_redacted(): void
    {
        $run = BackupRun::request(BackupProfile::Database, BackupTrigger::Api, metadata: ['leak' => Sentinels::ARCHIVE_PASSWORD]);
        $run->mergeMetadata(['nested' => ['db' => Sentinels::DB_PASSWORD]]);

        Sentinels::assertAbsent((string) DB::table('quraba_backup_runs')->where('id', $run->id)->value('metadata'), 'metadata');
    }
}
