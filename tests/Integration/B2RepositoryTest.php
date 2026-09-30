<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Quraba\Backup\Restic\RepositoryState;
use Quraba\Backup\Restic\ResticRepository;
use Quraba\Backup\Support\Process\ProcessFactory;
use Quraba\Backup\Support\Process\SymfonyProcessFactory;
use Quraba\Backup\Tests\Support\UsesFakeRestic;
use Quraba\Backup\Tests\TestCase;

/**
 * Optional, opt-in test against a real Backblaze B2 bucket. Never part of
 * normal CI. Requires:
 *
 *   QURABA_BACKUP_TEST_RESTIC_BINARY, QURABA_BACKUP_TEST_B2_ENDPOINT,
 *   QURABA_BACKUP_TEST_B2_BUCKET, QURABA_BACKUP_TEST_B2_KEY_ID,
 *   QURABA_BACKUP_TEST_B2_APPLICATION_KEY
 *
 * It only inspects a unique, throwaway prefix; it initializes only when
 * QURABA_BACKUP_TEST_B2_ALLOW_INIT=1 is also set.
 */
#[Group('b2-integration')]
final class B2RepositoryTest extends TestCase
{
    use UsesFakeRestic;

    protected function setUp(): void
    {
        parent::setUp();

        $required = ['QURABA_BACKUP_TEST_RESTIC_BINARY', 'QURABA_BACKUP_TEST_B2_ENDPOINT', 'QURABA_BACKUP_TEST_B2_BUCKET', 'QURABA_BACKUP_TEST_B2_KEY_ID', 'QURABA_BACKUP_TEST_B2_APPLICATION_KEY'];

        foreach ($required as $name) {
            if (! is_string(getenv($name)) || getenv($name) === '') {
                self::markTestSkipped('Optional B2 integration test: set '.implode(', ', $required).'.');
            }
        }

        $this->config()->set('restic.binary', (string) getenv('QURABA_BACKUP_TEST_RESTIC_BINARY'));
        $this->config()->set('quraba-backup.storage.b2.endpoint', (string) getenv('QURABA_BACKUP_TEST_B2_ENDPOINT'));
        $this->config()->set('quraba-backup.storage.b2.bucket', (string) getenv('QURABA_BACKUP_TEST_B2_BUCKET'));
        $this->config()->set('quraba-backup.storage.b2.key_id', (string) getenv('QURABA_BACKUP_TEST_B2_KEY_ID'));
        $this->config()->set('quraba-backup.storage.b2.application_key', (string) getenv('QURABA_BACKUP_TEST_B2_APPLICATION_KEY'));
        $this->config()->set('quraba-backup.storage.b2.prefix', 'quraba-backup-ci/'.bin2hex(random_bytes(6)));
        $this->refreshPackageServices();
        $this->app->instance(ProcessFactory::class, new SymfonyProcessFactory);
    }

    public function test_fresh_prefix_is_reported_uninitialized_not_broken(): void
    {
        $repository = $this->app->make(ResticRepository::class);

        self::assertSame(RepositoryState::Uninitialized, $repository->inspect()->state);

        if (getenv('QURABA_BACKUP_TEST_B2_ALLOW_INIT') === '1') {
            self::assertSame(RepositoryState::Ready, $repository->initialize()->state);
        }
    }

    public function test_wrong_credentials_are_distinguished(): void
    {
        $this->config()->set('quraba-backup.storage.b2.application_key', 'definitely-not-the-right-key');
        $this->refreshPackageServices();
        $this->app->instance(ProcessFactory::class, new SymfonyProcessFactory);

        self::assertSame(RepositoryState::CredentialsRejected, $this->app->make(ResticRepository::class)->inspect()->state);
    }
}
