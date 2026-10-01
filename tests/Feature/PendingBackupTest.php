<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Feature;

use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Enums\BackupStatus;
use Quraba\Backup\Enums\BackupTrigger;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Tests\TestCase;

final class PendingBackupTest extends TestCase
{
    public function test_pending_command_claims_only_api_request_and_records_preflight_failure(): void
    {
        $manual = BackupRun::request(BackupProfile::Database, BackupTrigger::Manual);
        $pending = BackupRun::request(BackupProfile::Media, BackupTrigger::Api);
        $this->config()->set('quraba-backup.app_id', null);

        $this->artisan('quraba:backup:pending')->assertExitCode(1);

        self::assertSame(BackupStatus::Pending, $manual->refresh()->status);
        self::assertSame(BackupStatus::Failed, $pending->refresh()->status);
        self::assertSame(BackupTrigger::Api, $pending->trigger);
    }

    public function test_disabled_package_does_not_consume_pending_request(): void
    {
        $pending = BackupRun::request(BackupProfile::Database, BackupTrigger::Api);
        $this->config()->set('quraba-backup.enabled', false);

        $this->artisan('quraba:backup:pending')->assertExitCode(0);

        self::assertSame(BackupStatus::Pending, $pending->refresh()->status);
    }
}
