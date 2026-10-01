<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Feature\Commands;

use Illuminate\Support\Facades\Artisan;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Models\RepositoryIdentityRecord;
use Quraba\Backup\Scheduling\BackupScheduler;
use Quraba\Backup\Tests\Support\Sentinels;
use Quraba\Backup\Tests\Support\UsesFakeRestic;
use Quraba\Backup\Tests\TestCase;

final class ResticCommandsTest extends TestCase
{
    use UsesFakeRestic;

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function callJson(string $command): array
    {
        $exit = Artisan::call($command, ['--json' => true]);
        $output = Artisan::output();

        Sentinels::assertAbsent($output, $command.' output');

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        return [$exit, $decoded];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, string>
     */
    private function statuses(array $report): array
    {
        $statuses = [];

        foreach ((array) $report['checks'] as $check) {
            $statuses[$check['id']] = $check['status'];
        }

        return $statuses;
    }

    public function test_init_initializes_a_confirmed_absent_repository(): void
    {
        $this->useFakeRestic(['repository' => 'missing']);

        $this->artisan('quraba:backup:restic:init', ['--force' => true])
            ->expectsOutputToContain('s3:https://s3.us-west-004.backblazeb2.com/quraba-test-bucket/quraba-backup/'.self::APP_ID.'/restic')
            ->expectsOutputToContain('read back successfully')
            ->assertSuccessful();

        self::assertContains('init --repository-version 2', $this->invokedCommands());
    }

    public function test_init_asks_for_confirmation_and_can_be_declined(): void
    {
        $this->useFakeRestic(['repository' => 'missing']);

        $this->artisan('quraba:backup:restic:init')
            ->expectsConfirmation('Initialize a NEW, EMPTY Restic repository at the location above?', 'no')
            ->assertFailed();

        self::assertNotContains('init --repository-version 2', $this->invokedCommands());
    }

    public function test_init_refuses_existing_and_ambiguous_repositories(): void
    {
        foreach (['ready', 'wrong_password', 'unreachable', 'credentials'] as $state) {
            $this->useFakeRestic(['repository' => $state]);

            $this->artisan('quraba:backup:restic:init', ['--force' => true])->assertFailed();

            self::assertNotContains('init --repository-version 2', $this->invokedCommands(), $state);
        }
    }

    public function test_health_is_healthy_for_a_ready_repository(): void
    {
        $this->useFakeRestic(['repository' => 'ready']);

        [$exit, $report] = $this->callJson('quraba:backup:restic:health');
        self::assertSame('degraded', $report['state'], 'An unbound repository identity is a warning.');
        self::assertSame('warn', $this->statuses($report)['restic.repository_identity']);
        self::assertSame(0, RepositoryIdentityRecord::query()->count(), 'Health never binds an identity.');

        RepositoryIdentityRecord::establish(self::APP_ID, 'testing', str_repeat('ab', 32), 'test', RepositoryIdentityRecord::SOURCE_INITIALIZATION);

        [$exit, $report] = $this->callJson('quraba:backup:restic:health');

        self::assertSame(0, $exit);
        self::assertSame('healthy', $report['state']);
        $statuses = $this->statuses($report);
        self::assertSame('pass', $statuses['restic.version']);
        self::assertSame('pass', $statuses['restic.repository_initialized']);
        self::assertSame('pass', $statuses['restic.repository_identity']);
        self::assertSame('pass', $statuses['restic.repository_locks']);
    }

    public function test_health_fails_when_the_repository_was_replaced(): void
    {
        $this->useFakeRestic(['repository' => 'ready']);
        RepositoryIdentityRecord::establish(self::APP_ID, 'testing', str_repeat('cd', 32), 'test', RepositoryIdentityRecord::SOURCE_INITIALIZATION);

        [$exit, $report] = $this->callJson('quraba:backup:restic:health');

        self::assertSame(1, $exit);
        self::assertSame('fail', $this->statuses($report)['restic.repository_identity']);
    }

    public function test_init_binds_the_identity_and_refuses_to_replace_a_bound_repository(): void
    {
        $this->useFakeRestic(['repository' => 'missing']);

        $this->artisan('quraba:backup:restic:init', ['--force' => true])->assertSuccessful();
        self::assertSame(RepositoryIdentityRecord::SOURCE_INITIALIZATION, RepositoryIdentityRecord::query()->value('source'));

        // The repository vanishes (e.g. a changed prefix); a new one must not silently replace it.
        $this->writeScenario(['repository' => 'missing']);

        $this->artisan('quraba:backup:restic:init', ['--force' => true])->assertFailed();
        self::assertSame(1, count(array_filter($this->invokedCommands(), static fn (string $c): bool => str_starts_with($c, 'init'))));
    }

    public function test_health_distinguishes_uninitialized_from_unreachable(): void
    {
        $this->useFakeRestic(['repository' => 'missing']);
        [$exit, $report] = $this->callJson('quraba:backup:restic:health');

        self::assertSame(1, $exit);
        self::assertSame('failed', $report['state']);
        self::assertSame('fail', $this->statuses($report)['restic.repository_initialized']);

        $this->useFakeRestic(['repository' => 'unreachable']);
        [$exit, $report] = $this->callJson('quraba:backup:restic:health');

        self::assertSame(1, $exit);
        self::assertSame('unknown', $report['state'], 'An unreachable repository is UNKNOWN, never healthy.');
    }

    public function test_health_warns_about_restic_locks_without_removing_them(): void
    {
        $this->useFakeRestic(['repository' => 'ready', 'locks' => [str_repeat('7', 64)]]);

        [$exit, $report] = $this->callJson('quraba:backup:restic:health');

        self::assertSame(0, $exit);
        self::assertSame('degraded', $report['state']);
        self::assertSame('warn', $this->statuses($report)['restic.repository_locks']);
        self::assertSame([], array_filter($this->invokedCommands(), static fn (string $c): bool => str_starts_with($c, 'unlock')));
    }

    public function test_health_fails_without_a_binary_and_never_installs_one(): void
    {
        $this->refreshPackageServices();

        [$exit, $report] = $this->callJson('quraba:backup:restic:health');

        self::assertSame(1, $exit);
        self::assertSame('fail', $this->statuses($report)['restic.binary']);
        self::assertFileDoesNotExist($this->sandbox.'/private/bin/restic');
    }

    public function test_doctor_reports_every_area_as_json_without_secrets(): void
    {
        $this->useFakeRestic(['repository' => 'ready']);

        [$exit, $report] = $this->callJson('quraba:backup:doctor');
        $statuses = $this->statuses($report);

        foreach ([
            'runtime.php', 'runtime.os', 'runtime.architecture', 'runtime.proc_open', 'runtime.extensions',
            'identity.app_id', 'identity.environment', 'storage.private_root', 'storage.workspace', 'storage.not_public',
            'locking.flock', 'locking.cross_process', 'database.driver', 'database.dumper', 'database.client',
            'b2.config', 'b2.endpoint', 'restic.binary', 'restic.version', 'restic.password_file',
            'restic.repository_initialized', 'safety.secret_independence', 'safety.recovery_secrets',
            'backup.spatie', 'backup.archive_password', 'backup.encryption', 'backup.env_file', 'backup.media_roots',
            'backup.archive_storage', 'backup.manifest_storage', 'backup.repository_identity', 'backup.workspace_disk',
            'backup.consistency', 'backup.schedule',
        ] as $id) {
            self::assertArrayHasKey($id, $statuses, $id);
            self::assertContains($statuses[$id], ['pass', 'warn', 'fail', 'skip']);
        }

        self::assertSame('pass', $statuses['locking.cross_process']);
        self::assertSame('pass', $statuses['restic.version']);
        // The test database is SQLite, which is not a supported production driver.
        self::assertSame('fail', $statuses['database.driver']);
        self::assertSame(1, $exit, 'Required failures produce a non-zero exit.');
        self::assertFalse($report['ok']);
    }

    public function test_doctor_detects_reused_secrets_and_missing_identity(): void
    {
        $this->useFakeRestic(['repository' => 'ready']);
        $this->config()->set('quraba-backup.archive.password', Sentinels::RESTIC_PASSWORD);
        $this->config()->set('quraba-backup.app_id', null);

        [, $report] = $this->callJson('quraba:backup:doctor');
        $statuses = $this->statuses($report);

        self::assertSame('fail', $statuses['safety.secret_independence']);
        self::assertSame('fail', $statuses['identity.app_id']);
    }

    public function test_doctor_checks_backup_readiness_without_creating_a_backup(): void
    {
        $this->useFakeRestic(['repository' => 'ready']);
        $this->config()->set('quraba-backup.archive.password', '');
        $this->config()->set('restic.media.roots', ['uploads' => ['path' => $this->sandbox]]);
        mkdir($this->sandbox.'/private', 0700, true);

        [, $report] = $this->callJson('quraba:backup:doctor');
        $statuses = $this->statuses($report);

        self::assertSame('fail', $statuses['backup.archive_password'], 'Encryption readiness is required.');
        self::assertSame('pass', $statuses['backup.encryption']);
        self::assertSame('pass', $statuses['backup.env_file']);
        self::assertSame('fail', $statuses['backup.media_roots'], 'A root containing private storage is unsafe.');
        self::assertSame('pass', $statuses['backup.archive_storage']);
        self::assertSame('pass', $statuses['backup.manifest_storage']);
        self::assertSame('warn', $statuses['backup.repository_identity']);
        self::assertSame(BackupScheduler::platformSupportsBackground() ? 'pass' : 'warn', $statuses['backup.schedule']);

        self::assertSame(0, BackupRun::query()->count(), 'The doctor never creates a backup.');
        self::assertSame(0, RepositoryIdentityRecord::query()->count(), 'The doctor never binds a repository identity.');
        self::assertSame([], array_filter($this->invokedCommands(), static fn (string $c): bool => str_starts_with($c, 'backup') || str_starts_with($c, 'init')));
    }

    public function test_doctor_does_not_create_package_directories(): void
    {
        $this->config()->set('restic.binary', null);
        $this->refreshPackageServices();

        Artisan::call('quraba:backup:doctor', ['--json' => true]);

        self::assertDirectoryDoesNotExist($this->sandbox.'/private/work');
        self::assertDirectoryDoesNotExist($this->sandbox.'/private/locks');
        self::assertFileDoesNotExist($this->sandbox.'/private/bin/restic', 'The doctor never installs Restic.');
    }

    public function test_doctor_flags_dangerous_media_roots(): void
    {
        $this->useFakeRestic(['repository' => 'ready']);
        $this->config()->set('restic.media.roots', ['everything' => ['path' => $this->app->basePath()]]);

        [, $report] = $this->callJson('quraba:backup:doctor');

        self::assertSame('fail', $this->statuses($report)['backup.media_roots']);
    }
}
