<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Feature\Restic;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Quraba\Backup\Domain\FailureDetails;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Enums\BackupTrigger;
use Quraba\Backup\Exceptions\QurabaBackupException;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Restic\ResticRunner;
use Quraba\Backup\Security\SecretRedactor;
use Quraba\Backup\Tests\Support\Sentinels;
use Quraba\Backup\Tests\Support\UsesFakeRestic;
use Quraba\Backup\Tests\TestCase;

/**
 * The fake Restic deliberately prints every credential it receives (key ID,
 * secret, repository password, credentialed URL, signed query) to stdout
 * and stderr. None of it may survive anywhere the package exposes output.
 */
final class RedactionTest extends TestCase
{
    use RefreshDatabase;
    use UsesFakeRestic;

    public function test_secrets_printed_by_restic_never_escape(): void
    {
        $this->useFakeRestic(['repository' => 'ready', 'leak' => true]);

        $result = $this->app->make(ResticRunner::class)->snapshots();

        Sentinels::assertAbsent($result->stdout, 'result stdout');
        Sentinels::assertAbsent($result->stderr, 'result stderr');
        self::assertStringContainsString('Fatal: request to', $result->stderr, 'Diagnostics stay useful.');

        try {
            $result->throwIfFailed();
            self::fail('The leaking command failed and must throw.');
        } catch (QurabaBackupException $exception) {
            Sentinels::assertAbsent($exception->getMessage(), 'exception message');

            $failure = FailureDetails::fromThrowable($exception, 'media.snapshot', $this->app->make(SecretRedactor::class));
            $run = BackupRun::request(BackupProfile::Media, BackupTrigger::Manual)->markPreflighting()->markFailed($failure);

            Sentinels::assertAbsent((string) json_encode(DB::table('quraba_backup_runs')->where('id', $run->id)->first()), 'persisted failure');
        }

        self::assertNotSame([], $this->logs->records, 'The failure must be logged.');
        Sentinels::assertAbsent($this->logs->dump(), 'logs');
    }

    public function test_cli_error_output_is_redacted(): void
    {
        $this->useFakeRestic(['repository' => 'ready', 'leak' => true]);

        $this->artisan('backup:restic:init', ['--force' => true])->assertFailed();
        $this->artisan('backup:restic:health', ['--json' => true])->assertFailed();

        Sentinels::assertAbsent($this->logs->dump(), 'logs');
    }

    public function test_health_json_output_contains_no_secrets(): void
    {
        $this->useFakeRestic(['repository' => 'ready', 'leak' => true]);

        $exit = Artisan::call('backup:restic:health', ['--json' => true]);
        $output = Artisan::output();

        self::assertSame(1, $exit);
        self::assertJson($output);
        Sentinels::assertAbsent($output, 'health JSON output');
    }
}
