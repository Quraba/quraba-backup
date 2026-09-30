<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Feature\Backup;

use Quraba\Backup\Backup\MediaSnapshotService;
use Quraba\Backup\Enums\ArtifactKind;
use Quraba\Backup\Enums\ArtifactStatus;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Enums\BackupTrigger;
use Quraba\Backup\Exceptions\MediaPathUnsafe;
use Quraba\Backup\Exceptions\RepositoryIdentityMismatch;
use Quraba\Backup\Exceptions\ResticSnapshotFailed;
use Quraba\Backup\Models\BackupArtifact;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Models\RepositoryIdentityRecord;
use Quraba\Backup\Restic\SnapshotKind;
use Quraba\Backup\Tests\Support\BuildsBackups;
use Quraba\Backup\Tests\Support\UsesFakeRestic;
use Quraba\Backup\Tests\TestCase;

final class MediaSnapshotTest extends TestCase
{
    use BuildsBackups;
    use UsesFakeRestic;

    private const string REPO = 'abababababababababababababababababababababababababababababababab';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useFakeRestic(['repository' => 'ready']);
        $this->prepareBackupPipeline();
    }

    private function service(): MediaSnapshotService
    {
        return $this->app->make(MediaSnapshotService::class);
    }

    /**
     * @return array{BackupRun, BackupArtifact}
     */
    private function runningMediaRun(): array
    {
        $run = BackupRun::request(BackupProfile::Media, BackupTrigger::Manual)->markPreflighting()->markRunning();

        return [$run, $run->addArtifact(ArtifactKind::ResticSnapshot)];
    }

    /**
     * @return list<list<string>>
     */
    private function backupInvocations(): array
    {
        return array_values(array_map(
            static fn (array $i): array => $i['argv'],
            array_filter($this->invocations(), static fn (array $i): bool => $i['argv'][0] === 'backup'),
        ));
    }

    public function test_snapshot_is_created_reread_by_exact_id_and_verified(): void
    {
        [$run, $artifact] = $this->runningMediaRun();

        $result = $this->service()->snapshot($run, $artifact, SnapshotKind::Media);
        $artifact->refresh();

        self::assertSame(ArtifactStatus::Verified, $artifact->status);
        self::assertSame($result->snapshotId, $artifact->snapshot_id);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $artifact->snapshot_id);
        self::assertSame(self::REPO, $artifact->metadata['repository_id'] ?? null);
        self::assertSame('media', $artifact->metadata['kind'] ?? null);
        self::assertSame(self::REPO, $run->refresh()->metadata['restic_repository_id'] ?? null);

        $backup = $this->backupInvocations();
        self::assertCount(1, $backup);
        foreach (['quraba-backup', 'app:'.self::APP_ID, 'env:testing', 'kind:media', 'run:'.$run->uuid] as $tag) {
            self::assertContains($tag, $backup[0]);
        }
        self::assertSame(str_replace('\\', '/', $this->mediaRoot), end($backup[0]));

        // Exact re-read: the full identity AND-filter plus the exact ID.
        $identityFilter = 'quraba-backup,app:'.self::APP_ID.',env:testing,kind:media,run:'.$run->uuid;
        self::assertContains('snapshots --json --no-lock --tag '.$identityFilter.' -- '.$result->snapshotId, $this->invokedCommands());

        // First proven repository becomes the expected identity.
        self::assertSame(self::REPO, RepositoryIdentityRecord::query()->value('repository_id'));
    }

    public function test_retry_after_lost_catalog_write_adopts_the_existing_snapshot(): void
    {
        [$run, $artifact] = $this->runningMediaRun();
        $first = $this->service()->snapshot($run, $artifact, SnapshotKind::Media);

        // Simulate: the catalog write was lost — the artifact is back in "creating".
        BackupArtifact::query()->whereKey($artifact->id)->toBase()->update(['status' => 'creating', 'snapshot_id' => null, 'verified_at' => null]);

        $again = $this->service()->snapshot($run->refresh(), $artifact->refresh(), SnapshotKind::Media);

        self::assertTrue($again->adopted);
        self::assertSame($first->snapshotId, $again->snapshotId);
        self::assertCount(1, $this->backupInvocations(), 'No second snapshot may be created for the same run.');
    }

    public function test_duplicate_run_snapshots_are_refused_as_ambiguous(): void
    {
        [$run, $artifact] = $this->runningMediaRun();
        $tags = ['quraba-backup', 'app:'.self::APP_ID, 'env:testing', 'kind:media', 'run:'.$run->uuid];

        $this->writeScenario(['repository' => 'ready', 'snapshots' => [
            ['id' => str_repeat('1', 64), 'time' => '2026-10-01T01:00:00Z', 'tags' => $tags, 'paths' => [$this->mediaRoot]],
            ['id' => str_repeat('2', 64), 'time' => '2026-10-01T02:00:00Z', 'tags' => $tags, 'paths' => [$this->mediaRoot]],
        ]]);

        try {
            $this->service()->snapshot($run, $artifact, SnapshotKind::Media);
            self::fail('Two snapshots for one run must be refused, not resolved by picking the newest.');
        } catch (ResticSnapshotFailed $exception) {
            self::assertSame('restic.snapshot_ambiguous', $exception->failureCode());
        }

        self::assertSame([], $this->backupInvocations());
        self::assertSame([], array_filter($this->invokedCommands(), static fn (string $c): bool => str_starts_with($c, 'forget')), 'Nothing is ever deleted.');
    }

    public function test_a_snapshot_claiming_this_run_with_another_kind_is_refused(): void
    {
        [$run, $artifact] = $this->runningMediaRun();

        $this->writeScenario(['repository' => 'ready', 'snapshots' => [
            ['id' => str_repeat('3', 64), 'time' => '2026-10-01T01:00:00Z', 'tags' => ['quraba-backup', 'app:'.self::APP_ID, 'env:testing', 'kind:recovery_media', 'run:'.$run->uuid], 'paths' => [$this->mediaRoot]],
        ]]);

        $this->expectException(ResticSnapshotFailed::class);
        $this->expectExceptionMessage('different app, environment or kind');
        $this->service()->snapshot($run, $artifact, SnapshotKind::Media);
    }

    public function test_a_replaced_repository_is_refused(): void
    {
        RepositoryIdentityRecord::establish(self::APP_ID, 'testing', str_repeat('cd', 32), 's3:https://original', RepositoryIdentityRecord::SOURCE_INITIALIZATION);
        [$run, $artifact] = $this->runningMediaRun();

        try {
            $this->service()->snapshot($run, $artifact, SnapshotKind::Media);
            self::fail('A different repository at the configured location must be refused.');
        } catch (RepositoryIdentityMismatch $exception) {
            self::assertSame('restic.repository_identity_mismatch', $exception->failureCode());
        }

        self::assertSame([], $this->backupInvocations());
        self::assertSame(str_repeat('cd', 32), RepositoryIdentityRecord::query()->value('repository_id'), 'The expected identity is never replaced.');
    }

    public function test_expected_identity_is_learned_from_remote_manifests_when_the_catalog_has_none(): void
    {
        $path = $this->sandbox.'/b2/quraba-backup/'.self::APP_ID.'/manifests/2026/09/30/5ff081a8-503e-44ba-91ae-30cfef9b972f.json';
        mkdir(dirname($path), 0700, true);
        file_put_contents($path, (string) json_encode(['app_id' => self::APP_ID, 'environment' => 'testing', 'restic' => ['repository_id' => str_repeat('ef', 32)]]));

        [$run, $artifact] = $this->runningMediaRun();

        $this->expectException(RepositoryIdentityMismatch::class);

        try {
            $this->service()->snapshot($run, $artifact, SnapshotKind::Media);
        } finally {
            self::assertSame(RepositoryIdentityRecord::SOURCE_REMOTE_MANIFEST, RepositoryIdentityRecord::query()->value('source'));
        }
    }

    public function test_unsafe_media_roots_are_refused(): void
    {
        foreach ([
            ['everything' => ['path' => $this->app->basePath()]],
            ['private' => ['path' => $this->sandbox.'/private']],
            ['nested' => ['path' => $this->mediaRoot], 'outer' => ['path' => $this->sandbox]],
            ['BadName' => ['path' => $this->mediaRoot]],
            ['missing' => ['path' => $this->sandbox.'/nope']],
            ['link' => ['path' => $this->app->publicPath().'/storage']],
        ] as $roots) {
            @mkdir($this->sandbox.'/private', 0700, true);
            $this->config()->set('restic.media.roots', $roots);
            $this->refreshBackupServices();

            try {
                $this->service()->roots();
                self::fail('Unsafe media roots must be refused: '.json_encode($roots));
            } catch (MediaPathUnsafe $exception) {
                self::assertSame('media.path_unsafe', $exception->failureCode());
            }
        }

        $this->config()->set('restic.media.roots', ['maybe' => ['path' => $this->sandbox.'/nope', 'optional' => true], 'uploads' => ['path' => $this->mediaRoot]]);
        $this->refreshBackupServices();
        self::assertCount(1, $this->service()->roots(), 'Optional missing roots are skipped.');
    }

    public function test_symlinked_media_roots_are_refused(): void
    {
        if (! @symlink($this->mediaRoot, $this->sandbox.'/media-link')) {
            self::markTestSkipped('This platform/user cannot create symbolic links.');
        }

        $this->config()->set('restic.media.roots', ['uploads' => ['path' => $this->sandbox.'/media-link']]);
        $this->refreshBackupServices();

        $this->expectException(MediaPathUnsafe::class);
        $this->expectExceptionMessage('symbolic link');
        $this->service()->roots();
    }
}
