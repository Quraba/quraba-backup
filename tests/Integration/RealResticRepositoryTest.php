<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Quraba\Backup\Domain\Identifiers;
use Quraba\Backup\Restic\RepositoryState;
use Quraba\Backup\Restic\ResticBackupRequest;
use Quraba\Backup\Restic\ResticRelease;
use Quraba\Backup\Restic\ResticRepository;
use Quraba\Backup\Restic\ResticRunner;
use Quraba\Backup\Support\Process\ProcessFactory;
use Quraba\Backup\Support\Process\SymfonyProcessFactory;
use Quraba\Backup\Tests\Support\UsesFakeRestic;
use Quraba\Backup\Tests\TestCase;
use Quraba\Backup\Workspace\WorkspaceArea;
use Quraba\Backup\Workspace\WorkspaceManager;

/**
 * Runs against a REAL pinned Restic binary and a local temporary repository.
 *
 * Enabled by QURABA_BACKUP_TEST_RESTIC_BINARY=/path/to/restic (CI installs the
 * pinned release and verifies its SHA-256 first). Skipped otherwise; no
 * network or B2 credentials are needed.
 */
#[Group('restic-integration')]
final class RealResticRepositoryTest extends TestCase
{
    use UsesFakeRestic;

    private string $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $binary = getenv('QURABA_BACKUP_TEST_RESTIC_BINARY');

        if (! is_string($binary) || $binary === '' || ! is_file($binary)) {
            self::markTestSkipped('Set QURABA_BACKUP_TEST_RESTIC_BINARY to run real Restic integration tests.');
        }

        $this->repository = $this->sandbox.'/local-repository';

        $this->config()->set('restic.binary', $binary);
        $this->config()->set('restic.repository.url', $this->repository);
        $this->refreshPackageServices();
        $this->app->instance(ProcessFactory::class, new SymfonyProcessFactory);
    }

    private function repository(): ResticRepository
    {
        return $this->app->make(ResticRepository::class);
    }

    public function test_real_binary_reports_the_pinned_version(): void
    {
        self::assertSame(ResticRelease::VERSION, $this->app->make(ResticRunner::class)->version()->version);
    }

    public function test_uninitialized_init_already_initialized_and_snapshot_query(): void
    {
        self::assertSame(RepositoryState::Uninitialized, $this->repository()->inspect()->state);

        $initialized = $this->repository()->initialize();

        self::assertSame(RepositoryState::Ready, $initialized->state);
        self::assertSame(2, $initialized->formatVersion);
        self::assertSame([], $this->repository()->snapshots());
        self::assertSame([], $this->repository()->lockIds());

        $this->artisan('quraba:backup:restic:init', ['--force' => true])->assertFailed();
        $this->artisan('quraba:backup:restic:health', ['--json' => true])->assertSuccessful();
    }

    public function test_wrong_password_is_detected_and_never_reinitialized(): void
    {
        $this->repository()->initialize();

        file_put_contents($this->sandbox.'/secrets/restic-password', "a-completely-different-password\n");

        $inspection = $this->repository()->inspect();

        self::assertSame(RepositoryState::WrongPassword, $inspection->state);
        $this->artisan('quraba:backup:restic:init', ['--force' => true])->assertFailed();
    }

    public function test_every_typed_primitive_is_accepted_by_real_restic(): void
    {
        $this->repository()->initialize();
        $runner = $this->app->make(ResticRunner::class);

        $media = $this->sandbox.'/media root';
        mkdir($media);
        file_put_contents($media.'/a.jpg', 'image-a');

        $backup = $runner->backup(ResticBackupRequest::make([$media], ['quraba-backup', 'app:'.self::APP_ID, 'env:testing', 'kind:media'], 'quraba-test'))->throwIfFailed();
        $snapshotId = $backup->summary()['snapshot_id'] ?? null;

        self::assertIsString($snapshotId);
        self::assertTrue(Identifiers::isFullSnapshotId($snapshotId), 'The backup summary must carry the full snapshot ID.');

        $snapshots = $this->repository()->snapshots(['app:'.self::APP_ID, 'kind:media']);
        self::assertCount(1, $snapshots);
        self::assertSame($snapshotId, $snapshots[0]->id);
        self::assertSame([], $this->repository()->snapshots(['env:production']), 'Tag filters select by exact identity.');

        self::assertIsArray($runner->stats($snapshotId)->throwIfFailed()->json());

        // On Windows Restic cannot restore NTFS timestamps of ancestors such as
        // C:\Users inside the target; Linux (the production target, and CI)
        // exercises the full restore.
        if (PHP_OS_FAMILY !== 'Windows') {
            $workspace = $this->app->make(WorkspaceManager::class)->create();
            $runner->restore($snapshotId, $workspace)->throwIfFailed();

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($workspace->area(WorkspaceArea::Restore), \FilesystemIterator::SKIP_DOTS));
            $restored = array_values(array_filter(iterator_to_array($iterator, false), static fn (\SplFileInfo $file): bool => $file->getFilename() === 'a.jpg'));

            self::assertCount(1, $restored, 'The exact snapshot is reconstructed inside the private workspace.');
            self::assertSame('image-a', file_get_contents($restored[0]->getPathname()));
            self::assertTrue($workspace->cleanup()->succeeded());
        }

        $runner->forget([$snapshotId])->throwIfFailed();
        self::assertSame([], $this->repository()->snapshots());

        $runner->prune(dryRun: true)->throwIfFailed();
        $runner->check()->throwIfFailed();
    }

    public function test_unreadable_repository_is_not_treated_as_missing(): void
    {
        mkdir($this->repository, 0700, true);
        file_put_contents($this->repository.'/config', 'corrupted, not a restic config');

        $inspection = $this->repository()->inspect();

        self::assertNotSame(RepositoryState::Uninitialized, $inspection->state);
        self::assertNotSame(RepositoryState::Ready, $inspection->state);
        $this->artisan('quraba:backup:restic:init', ['--force' => true])->assertFailed();
    }
}
