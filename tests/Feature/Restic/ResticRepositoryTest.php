<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Feature\Restic;

use PHPUnit\Framework\Attributes\DataProvider;
use Quraba\Backup\Coordination\LockName;
use Quraba\Backup\Exceptions\ConfigurationException;
use Quraba\Backup\Exceptions\OperationBusy;
use Quraba\Backup\Exceptions\ResticRepositoryUnavailable;
use Quraba\Backup\Restic\RepositoryState;
use Quraba\Backup\Restic\ResticRepository;
use Quraba\Backup\Tests\Support\ChildProcess;
use Quraba\Backup\Tests\Support\UsesFakeRestic;
use Quraba\Backup\Tests\TestCase;

final class ResticRepositoryTest extends TestCase
{
    use UsesFakeRestic;

    private function repository(): ResticRepository
    {
        return $this->app->make(ResticRepository::class);
    }

    /**
     * @return iterable<string, array{string, RepositoryState}>
     */
    public static function states(): iterable
    {
        yield 'ready' => ['ready', RepositoryState::Ready];
        yield 'missing' => ['missing', RepositoryState::Uninitialized];
        yield 'wrong password' => ['wrong_password', RepositoryState::WrongPassword];
        yield 'network' => ['unreachable', RepositoryState::Unreachable];
        yield 'credentials' => ['credentials', RepositoryState::CredentialsRejected];
        yield 'locked' => ['locked', RepositoryState::Locked];
    }

    #[DataProvider('states')]
    public function test_inspection_distinguishes_failure_types(string $scenario, RepositoryState $expected): void
    {
        $this->useFakeRestic(['repository' => $scenario]);

        $inspection = $this->repository()->inspect();

        self::assertSame($expected, $inspection->state);
        self::assertStringStartsWith('s3:https://', (string) $inspection->location);

        if ($expected === RepositoryState::Ready) {
            self::assertSame(str_repeat('ab', 32), $inspection->repositoryId);
            self::assertSame(2, $inspection->formatVersion);
        }
    }

    public function test_not_configured_is_reported_without_running_restic(): void
    {
        $this->useFakeRestic();
        $this->config()->set('quraba-backup.storage.b2.bucket', null);
        $this->refreshPackageServices();

        self::assertSame(RepositoryState::NotConfigured, $this->repository()->inspect()->state);
        self::assertNotContains('cat config --no-lock', $this->invokedCommands());
    }

    public function test_explicit_initialization_of_a_confirmed_absent_repository(): void
    {
        $this->useFakeRestic(['repository' => 'missing']);

        $result = $this->repository()->initialize();

        self::assertSame(RepositoryState::Ready, $result->state);
        self::assertContains('init --repository-version 2', $this->invokedCommands());
        // Proven readable afterwards.
        self::assertContains('snapshots --json --no-lock', $this->invokedCommands());
        self::assertSame([], $this->repository()->snapshots());
    }

    public function test_initialized_repository_is_never_initialized_again(): void
    {
        $this->useFakeRestic(['repository' => 'ready']);

        try {
            $this->repository()->initialize();
            self::fail('An existing repository must not be re-initialized.');
        } catch (ConfigurationException $exception) {
            self::assertSame('restic.repository_already_initialized', $exception->failureCode());
        }

        self::assertNotContains('init --repository-version 2', $this->invokedCommands());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ambiguousStates(): iterable
    {
        yield 'wrong password' => ['wrong_password'];
        yield 'network failure' => ['unreachable'];
        yield 'bad credentials' => ['credentials'];
        yield 'locked' => ['locked'];
    }

    #[DataProvider('ambiguousStates')]
    public function test_ambiguous_failures_never_trigger_initialization(string $scenario): void
    {
        $this->useFakeRestic(['repository' => $scenario]);

        try {
            $this->repository()->initialize();
            self::fail('Initialization must be refused.');
        } catch (ResticRepositoryUnavailable $exception) {
            self::assertSame('restic.init_refused', $exception->failureCode());
        }

        self::assertNotContains('init --repository-version 2', $this->invokedCommands());
    }

    public function test_initialization_requires_the_global_operation_lock(): void
    {
        $this->useFakeRestic(['repository' => 'missing']);
        [$child] = ChildProcess::start($this->sandbox.'/private', LockName::GlobalOperation->value);

        try {
            $this->repository()->initialize();
            self::fail('Initialization must not run concurrently with another write operation.');
        } catch (OperationBusy) {
            self::assertNotContains('init --repository-version 2', $this->invokedCommands());
        } finally {
            ChildProcess::kill($child);
        }
    }

    public function test_snapshot_listing_and_lock_detection(): void
    {
        $this->useFakeRestic([
            'repository' => 'ready',
            'snapshots' => [
                ['id' => str_repeat('1', 64), 'short_id' => '11111111', 'time' => '2026-09-29T02:00:00Z', 'tags' => ['quraba-backup'], 'paths' => ['/srv/media'], 'hostname' => 'h'],
            ],
            'locks' => [str_repeat('7', 64)],
        ]);

        $snapshots = $this->repository()->snapshots();

        self::assertCount(1, $snapshots);
        self::assertSame(str_repeat('1', 64), $snapshots[0]->id);
        self::assertSame([str_repeat('7', 64)], $this->repository()->lockIds());
    }
}
