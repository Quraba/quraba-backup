<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Quraba\Backup\Backup\MediaSnapshotService;
use Quraba\Backup\Enums\ArtifactKind;
use Quraba\Backup\Enums\ArtifactStatus;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Enums\BackupStatus;
use Quraba\Backup\Enums\BackupTrigger;
use Quraba\Backup\Exceptions\RepositoryIdentityMismatch;
use Quraba\Backup\Exceptions\ResticSnapshotFailed;
use Quraba\Backup\Identity\IdentityResolver;
use Quraba\Backup\Models\BackupArtifact;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Models\RepositoryIdentityRecord;
use Quraba\Backup\Restic\RepositoryIdentityGuard;
use Quraba\Backup\Restic\ResticBackupRequest;
use Quraba\Backup\Restic\ResticRepository;
use Quraba\Backup\Restic\ResticRunner;
use Quraba\Backup\Restic\SnapshotIdentity;
use Quraba\Backup\Restic\SnapshotKind;
use Quraba\Backup\Support\Process\ProcessFactory;
use Quraba\Backup\Support\Process\SymfonyProcessFactory;
use Quraba\Backup\Tests\Support\BuildsBackups;
use Quraba\Backup\Tests\Support\UsesFakeRestic;
use Quraba\Backup\Tests\TestCase;
use Quraba\Backup\Workspace\WorkspaceArea;
use Quraba\Backup\Workspace\WorkspaceManager;

/**
 * Media snapshots against a REAL pinned Restic binary and a local repository.
 * Enabled by QURABA_BACKUP_TEST_RESTIC_BINARY (CI installs the verified binary).
 */
#[Group('restic-integration')]
final class RealResticMediaTest extends TestCase
{
    use BuildsBackups;
    use UsesFakeRestic;

    protected function setUp(): void
    {
        parent::setUp();

        $binary = getenv('QURABA_BACKUP_TEST_RESTIC_BINARY');

        if (! is_string($binary) || $binary === '' || ! is_file($binary)) {
            self::markTestSkipped('Set QURABA_BACKUP_TEST_RESTIC_BINARY to run real Restic integration tests.');
        }

        $this->config()->set('restic.binary', $binary);
        $this->config()->set('restic.repository.url', $this->sandbox.'/local-repository');
        $this->refreshPackageServices();
        $this->app->instance(ProcessFactory::class, new SymfonyProcessFactory);
        $this->prepareBackupPipeline();

        $this->app->make(RepositoryIdentityGuard::class)->initialize();
    }

    /**
     * @return array{BackupRun, BackupArtifact}
     */
    private function mediaRun(): array
    {
        $run = BackupRun::request(BackupProfile::Media, BackupTrigger::Manual)->markPreflighting()->markRunning();

        return [$run, $run->addArtifact(ArtifactKind::ResticSnapshot)];
    }

    private function snapshotCount(): int
    {
        return count($this->app->make(ResticRepository::class)->snapshots());
    }

    public function test_real_snapshot_is_verified_with_full_id_tags_and_repository_identity(): void
    {
        [$run, $artifact] = $this->mediaRun();

        $result = $this->app->make(MediaSnapshotService::class)->snapshot($run, $artifact, SnapshotKind::Media);
        $artifact->refresh();

        self::assertSame(ArtifactStatus::Verified, $artifact->status);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $artifact->snapshot_id);

        $expected = RepositoryIdentityRecord::query()->firstOrFail();
        self::assertSame(RepositoryIdentityRecord::SOURCE_INITIALIZATION, $expected->source);
        self::assertSame($expected->repository_id, $result->repositoryId);

        $snapshot = $this->app->make(ResticRepository::class)->snapshots([], [$result->snapshotId])[0];
        foreach (['quraba-backup', 'app:'.self::APP_ID, 'env:testing', 'kind:media', 'run:'.$run->uuid] as $tag) {
            self::assertTrue($snapshot->hasTag($tag), $tag);
        }
    }

    public function test_restic_tag_filters_are_and_not_or(): void
    {
        $identity = $this->app->make(IdentityResolver::class)->current();
        $runner = $this->app->make(ResticRunner::class);
        $media = SnapshotIdentity::for($identity, SnapshotKind::Media, '11111111-1111-4111-8111-111111111111');
        $recovery = SnapshotIdentity::for($identity, SnapshotKind::RecoveryMedia, '22222222-2222-4222-8222-222222222222');

        $runner->backup(ResticBackupRequest::make([$this->mediaRoot], $media->tags(), $media->host()))->throwIfFailed();
        $runner->backup(ResticBackupRequest::make([$this->mediaRoot], $recovery->tags(), $recovery->host()))->throwIfFailed();

        $repository = $this->app->make(ResticRepository::class);

        self::assertCount(2, $repository->snapshots(['quraba-backup']));
        self::assertCount(1, $repository->snapshots($media->tags()), 'All identity tags must match (AND).');
        self::assertCount(0, $repository->snapshots(['kind:media', 'run:22222222-2222-4222-8222-222222222222']), 'An OR would have matched both.');
    }

    public function test_retry_adopts_and_duplicates_are_ambiguous(): void
    {
        [$run, $artifact] = $this->mediaRun();
        $service = $this->app->make(MediaSnapshotService::class);
        $first = $service->snapshot($run, $artifact, SnapshotKind::Media);

        BackupArtifact::query()->whereKey($artifact->id)->toBase()->update(['status' => 'creating', 'snapshot_id' => null]);
        $again = $service->snapshot($run->refresh(), $artifact->refresh(), SnapshotKind::Media);

        self::assertTrue($again->adopted);
        self::assertSame($first->snapshotId, $again->snapshotId);
        self::assertSame(1, $this->snapshotCount());

        // Another process somehow wrote a second snapshot for the same run.
        $identity = SnapshotIdentity::for($this->app->make(IdentityResolver::class)->current(), SnapshotKind::Media, $run->uuid);
        $this->app->make(ResticRunner::class)->backup(ResticBackupRequest::make([$this->mediaRoot], $identity->tags(), $identity->host()))->throwIfFailed();
        BackupArtifact::query()->whereKey($artifact->id)->toBase()->update(['status' => 'creating', 'snapshot_id' => null]);

        try {
            $service->snapshot($run->refresh(), $artifact->refresh(), SnapshotKind::Media);
            self::fail('Two snapshots for one run are ambiguous.');
        } catch (ResticSnapshotFailed $exception) {
            self::assertSame('restic.snapshot_ambiguous', $exception->failureCode());
        }

        self::assertSame(2, $this->snapshotCount(), 'Nothing is deleted.');
    }

    public function test_a_replacement_repository_at_the_same_location_is_refused(): void
    {
        self::removeTree($this->sandbox.'/local-repository');

        // Initialization is refused: this application is bound to the vanished repository.
        $this->artisan('quraba:backup:restic:init', ['--force' => true])->assertFailed();

        // Someone creates a new empty repository there anyway.
        $this->app->make(ResticRepository::class)->initialize();

        $this->expectException(RepositoryIdentityMismatch::class);
        $this->app->make(RepositoryIdentityGuard::class)->verifyOpenRepository();
    }

    public function test_a_deleted_file_is_recoverable_from_an_older_snapshot(): void
    {
        [$run, $artifact] = $this->mediaRun();
        $old = $this->app->make(MediaSnapshotService::class)->snapshot($run, $artifact, SnapshotKind::Media);

        unlink($this->mediaRoot.'/uploads/a.jpg');
        [$run2, $artifact2] = $this->mediaRun();
        $new = $this->app->make(MediaSnapshotService::class)->snapshot($run2, $artifact2, SnapshotKind::Media);

        self::assertNotSame($old->snapshotId, $new->snapshotId);

        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestIncomplete('Restic restores on Windows fail on NTFS ancestor timestamps; the restore half runs on Linux CI.');
        }

        $workspace = $this->app->make(WorkspaceManager::class)->create();

        try {
            $this->app->make(ResticRunner::class)->restore($old->snapshotId, $workspace)->throwIfFailed();

            $found = [];
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($workspace->area(WorkspaceArea::Restore), \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->getFilename() === 'a.jpg') {
                    $found[] = (string) file_get_contents($file->getPathname());
                }
            }

            self::assertSame(['image-a'], $found);
        } finally {
            $workspace->cleanup();
        }
    }

    public function test_full_recovery_point_with_real_restic(): void
    {
        $result = $this->manager()->run(BackupProfile::Recovery);

        self::assertSame(BackupStatus::Completed, $result->run->status);
        self::assertSame(1, $this->snapshotCount());
        self::assertSame(3, $result->run->artifacts()->where('status', ArtifactStatus::Verified->value)->count());
    }
}
