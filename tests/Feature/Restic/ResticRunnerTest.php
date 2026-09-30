<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Feature\Restic;

use InvalidArgumentException;
use Quraba\Backup\Exceptions\ConfigurationException;
use Quraba\Backup\Exceptions\ProcessExecutionFailed;
use Quraba\Backup\Exceptions\ResticRepositoryUninitialized;
use Quraba\Backup\Exceptions\ResticUnavailable;
use Quraba\Backup\Exceptions\ResticVersionMismatch;
use Quraba\Backup\Restic\BinarySource;
use Quraba\Backup\Restic\ResticBackupRequest;
use Quraba\Backup\Restic\ResticRunner;
use Quraba\Backup\Tests\Support\Sentinels;
use Quraba\Backup\Tests\Support\UsesFakeRestic;
use Quraba\Backup\Tests\TestCase;
use Quraba\Backup\Workspace\WorkspaceManager;

final class ResticRunnerTest extends TestCase
{
    use UsesFakeRestic;

    private function runner(): ResticRunner
    {
        return $this->app->make(ResticRunner::class);
    }

    public function test_configured_binary_is_verified_by_exact_version(): void
    {
        $this->useFakeRestic();

        $binary = $this->runner()->binary();

        self::assertSame(BinarySource::Configured, $binary->source);
        self::assertSame('0.19.1', $binary->version->version);
        self::assertSame([['version', '--json']], array_map(static fn (array $i): array => $i['argv'], $this->invocations()));
    }

    public function test_version_mismatch_is_refused(): void
    {
        $this->useFakeRestic(['version' => '0.18.0']);

        $this->expectException(ResticVersionMismatch::class);
        $this->runner()->binary();
    }

    public function test_a_binary_replaced_on_disk_is_verified_again(): void
    {
        $path = $this->useFakeRestic();
        $runner = $this->runner();

        self::assertSame('0.19.1', $runner->binary()->version->version);
        $runner->binary();
        self::assertCount(1, $this->invocations(), 'Verification is cached while the file is unchanged.');

        // Swap the binary for one reporting another version (a drift/tamper scenario).
        $this->writeScenario(['version' => '0.18.0']);
        file_put_contents($path, (string) file_get_contents($path)."\n// changed\n");
        touch($path, time() + 60);

        $this->expectException(ResticVersionMismatch::class);
        $runner->binary();
    }

    public function test_configured_binary_failure_never_falls_back(): void
    {
        $this->useFakeRestic();
        $this->config()->set('restic.binary', $this->sandbox.'/missing/restic');
        $this->config()->set('restic.allow_system_binary', true);
        $this->refreshPackageServices();

        $this->expectException(ResticUnavailable::class);
        $this->runner()->binary();
    }

    public function test_managed_binary_is_used_when_nothing_is_configured(): void
    {
        $this->useFakeRestic(at: $this->sandbox.'/private/bin/restic');

        self::assertSame(BinarySource::Managed, $this->runner()->binary()->source);
    }

    public function test_missing_binary_points_to_the_installer(): void
    {
        $this->refreshPackageServices();

        $this->expectException(ResticUnavailable::class);
        $this->expectExceptionMessage('backup:install-restic');
        $this->runner()->binary();
    }

    public function test_repository_commands_use_argument_arrays_and_controlled_environment(): void
    {
        $this->useFakeRestic(['repository' => 'ready']);
        putenv('DB_PASSWORD='.Sentinels::DB_PASSWORD);
        putenv('RESTIC_PASSWORD=inherited-should-not-pass');

        try {
            $this->runner()->snapshots(['app:'.self::APP_ID, 'env:testing'])->throwIfFailed();
        } finally {
            putenv('DB_PASSWORD');
            putenv('RESTIC_PASSWORD');
        }

        $invocation = $this->invocations()[1];

        self::assertSame(['snapshots', '--json', '--no-lock', '--tag', 'app:'.self::APP_ID.',env:testing'], $invocation['argv']);

        $env = $invocation['env'];
        self::assertSame($this->sandbox.'/secrets/restic-password', $env['RESTIC_PASSWORD_FILE']);
        self::assertSame(Sentinels::B2_KEY_ID, $env['AWS_ACCESS_KEY_ID']);
        self::assertSame(Sentinels::B2_SECRET, $env['AWS_SECRET_ACCESS_KEY']);
        self::assertSame('us-west-004', $env['AWS_DEFAULT_REGION']);
        self::assertSame('s3:https://s3.us-west-004.backblazeb2.com/quraba-test-bucket/quraba-backup/'.self::APP_ID.'/restic', $env['RESTIC_REPOSITORY']);
        self::assertArrayNotHasKey('RESTIC_PASSWORD', $env, 'Inherited password variables must be stripped.');
        self::assertArrayNotHasKey('DB_PASSWORD', $env, 'Inherited .env secrets must be stripped.');

        // Secrets never appear in argv.
        Sentinels::assertAbsent(implode(' ', $invocation['argv']), 'argv');
        foreach ($this->processes->commands as $command) {
            Sentinels::assertAbsent(implode(' ', $command), 'process command');
        }
    }

    public function test_arguments_reach_restic_literally_without_a_shell(): void
    {
        $this->useFakeRestic(['repository' => 'ready']);

        $hostile = $this->sandbox.'/media dir; touch pwned $(id) `id` && echo %PATH% $HOME';
        mkdir($hostile, 0700, true);

        $this->runner()->backup(ResticBackupRequest::make([$hostile], ['quraba-backup', 'run:'.self::APP_ID], 'app-host'))->throwIfFailed();

        $invocation = $this->invocations()[1];
        $argv = $invocation['argv'];

        self::assertSame('--', $argv[count($argv) - 2]);
        self::assertSame(str_replace('\\', '/', $hostile), $argv[count($argv) - 1], 'The path must arrive byte-for-byte.');
        self::assertFileDoesNotExist($invocation['cwd'].'/pwned', 'Nothing may be interpreted by a shell.');
        self::assertFileDoesNotExist($this->sandbox.'/pwned');
        self::assertContains('--host', $argv);
    }

    public function test_only_exact_snapshot_ids_are_accepted_for_forget_restore_and_stats(): void
    {
        $this->useFakeRestic(['repository' => 'ready']);
        $workspace = $this->app->make(WorkspaceManager::class)->create();

        foreach ([
            fn () => $this->runner()->forget(['latest']),
            fn () => $this->runner()->forget(['abcd1234']),
            fn () => $this->runner()->restore('latest', $workspace),
            fn () => $this->runner()->stats('abcd'),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('Selectors and short IDs must be refused.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        $id = str_repeat('9f', 32);
        $this->runner()->forget([$id])->throwIfFailed();
        $this->runner()->restore($id, $workspace)->throwIfFailed();

        $commands = $this->invokedCommands();
        self::assertContains('forget --json -- '.$id, $commands);

        $restore = array_values(array_filter($this->invocations(), static fn (array $i): bool => $i['argv'][0] === 'restore'))[0]['argv'];
        self::assertSame(['restore', $id, '--target'], array_slice($restore, 0, 3));
        self::assertStringStartsWith(str_replace('\\', '/', $workspace->root()), $restore[3], 'Restores only ever target a private workspace.');

        $workspace->cleanup();
    }

    public function test_tags_are_validated(): void
    {
        $this->useFakeRestic(['repository' => 'ready']);

        $this->expectException(InvalidArgumentException::class);
        $this->runner()->snapshots(['run:x,app:y']);
    }

    public function test_non_zero_exit_returns_a_result_that_classifies_on_demand(): void
    {
        $this->useFakeRestic(['repository' => 'missing']);

        $result = $this->runner()->catConfig();

        self::assertFalse($result->successful());
        self::assertSame(10, $result->exitCode);
        self::assertStringContainsString('repository does not exist', $result->stderr);

        $this->expectException(ResticRepositoryUninitialized::class);
        $result->throwIfFailed();
    }

    public function test_timeouts_are_enforced(): void
    {
        $this->useFakeRestic(['repository' => 'ready', 'sleep' => ['snapshots' => 5]]);
        $this->config()->set('restic.timeouts.query', 1);
        $this->refreshPackageServices();

        try {
            $this->runner()->snapshots();
            self::fail('The process must be stopped at its timeout.');
        } catch (ProcessExecutionFailed $exception) {
            self::assertSame('process.timeout', $exception->failureCode());
        }
    }

    public function test_zero_timeouts_are_refused(): void
    {
        $this->config()->set('restic.timeouts.query', 0);
        $this->refreshPackageServices();

        $this->expectException(ConfigurationException::class);
        $this->runner();
    }

    public function test_launch_failure_is_distinguished_from_exit_failure(): void
    {
        $this->useFakeRestic();

        try {
            $this->runner()->probeBinary($this->sandbox.'/does-not-exist/restic');
            self::fail('Launching a missing binary must fail.');
        } catch (ProcessExecutionFailed $exception) {
            self::assertSame('process.launch_failed', $exception->failureCode());
        }
    }

    public function test_repository_operations_require_a_usable_password_file(): void
    {
        $this->useFakeRestic(['repository' => 'ready']);
        $this->config()->set('restic.password_file', $this->sandbox.'/secrets/missing');
        $this->refreshPackageServices();

        $this->expectException(ConfigurationException::class);
        $this->runner()->snapshots();
    }

    public function test_world_readable_password_file_is_refused_on_posix(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX permissions are not available on Windows.');
        }

        $this->useFakeRestic(['repository' => 'ready']);
        chmod($this->sandbox.'/secrets/restic-password', 0644);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('chmod 600');
        $this->runner()->snapshots();
    }
}
