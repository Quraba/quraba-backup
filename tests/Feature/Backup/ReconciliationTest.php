<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Feature\Backup;

use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Quraba\Backup\Archive\ArchiveRequest;
use Quraba\Backup\Archive\ArchiveStore;
use Quraba\Backup\Archive\ArchiveVerifier;
use Quraba\Backup\Contracts\ArchiveEngine;
use Quraba\Backup\Enums\ArtifactKind;
use Quraba\Backup\Enums\ArtifactStatus;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Enums\BackupStatus;
use Quraba\Backup\Enums\BackupTrigger;
use Quraba\Backup\Enums\MaintenanceOperation;
use Quraba\Backup\Health\BackupHealthService;
use Quraba\Backup\Identity\IdentityResolver;
use Quraba\Backup\Models\BackupArtifact;
use Quraba\Backup\Models\BackupMaintenanceRun;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Restic\ResticBackupRequest;
use Quraba\Backup\Restic\ResticRunner;
use Quraba\Backup\Restic\SnapshotIdentity;
use Quraba\Backup\Restic\SnapshotKind;
use Quraba\Backup\Tests\Support\BuildsBackups;
use Quraba\Backup\Tests\Support\Sentinels;
use Quraba\Backup\Tests\Support\UsesFakeRestic;
use Quraba\Backup\Tests\TestCase;
use Quraba\Backup\Workspace\WorkspaceManager;

final class ReconciliationTest extends TestCase
{
    use BuildsBackups;
    use UsesFakeRestic;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useFakeRestic(['repository' => 'ready']);
        $this->prepareBackupPipeline();
    }

    private function interruptedRun(BackupProfile $profile): BackupRun
    {
        return BackupRun::request($profile, BackupTrigger::Scheduled, metadata: ['package_version' => 'test'])->markPreflighting()->markRunning();
    }

    private function backupCount(): int
    {
        return count(array_filter($this->invokedCommands(), static fn (string $c): bool => str_starts_with($c, 'backup')));
    }

    /**
     * Crash after the archive was verified locally and uploaded, before the
     * catalog learned about it.
     */
    private function uploadArchiveThenCrash(BackupRun $run): BackupArtifact
    {
        $artifact = $run->addArtifact(ArtifactKind::ApplicationArchive)->markCreating();
        $identity = $this->app->make(IdentityResolver::class)->current();
        $workspace = $this->app->make(WorkspaceManager::class)->create();

        $created = $this->app->make(ArchiveEngine::class)->create(new ArchiveRequest($run->uuid, $run->profile, $identity, $workspace, 'testing', $this->sandbox.'/.env', 'test', Sentinels::ARCHIVE_PASSWORD));
        $verified = $this->app->make(ArchiveVerifier::class)->verify($created->path, Sentinels::ARCHIVE_PASSWORD, $run->uuid, $identity);
        $store = $this->app->make(ArchiveStore::class);
        $artifact->assignLocator($store->locatorFor($run));
        $artifact->markUploading();
        $store->store($run, $verified);

        // The process dies here: the workspace is gone, the catalog says "uploading".
        $workspace->cleanup();

        return $artifact;
    }

    /**
     * Crash after Restic wrote the snapshot, before its ID reached the catalog.
     */
    private function snapshotThenCrash(BackupRun $run, SnapshotKind $kind): BackupArtifact
    {
        $artifact = $run->addArtifact(ArtifactKind::ResticSnapshot)->markCreating();
        $identity = SnapshotIdentity::for($this->app->make(IdentityResolver::class)->current(), $kind, $run->uuid);

        $this->app->make(ResticRunner::class)->backup(ResticBackupRequest::make([str_replace('\\', '/', $this->mediaRoot)], $identity->tags(), $identity->host()))->throwIfFailed();

        return $artifact;
    }

    public function test_crash_after_b2_upload_is_adopted_after_download_and_verification(): void
    {
        $run = $this->interruptedRun(BackupProfile::Database);
        $artifact = $this->uploadArchiveThenCrash($run);

        $report = $this->reconciler()->reconcile();

        self::assertSame(0, $report->unresolved());
        self::assertSame(BackupStatus::Completed, $run->refresh()->status);
        self::assertSame(ArtifactStatus::Verified, $artifact->refresh()->status);
        self::assertSame(hash_file('sha256', $this->bucketPath((string) $artifact->locator)), $artifact->sha256);
        self::assertTrue($artifact->metadata['adopted'] ?? false);
        self::assertNotNull($run->artifacts()->where('kind', ArtifactKind::RemoteManifest->value)->where('status', ArtifactStatus::Verified->value)->first());
        self::assertSame(1, $this->dumper->dumps, 'Reconciliation never re-creates the archive.');
    }

    public function test_crash_after_local_verification_but_before_upload_fails_the_component(): void
    {
        $run = $this->interruptedRun(BackupProfile::Database);
        $artifact = $run->addArtifact(ArtifactKind::ApplicationArchive)->markCreating();
        $artifact->assignLocator($this->app->make(ArchiveStore::class)->locatorFor($run));
        $artifact->markUploading();

        $this->reconciler()->reconcile();

        self::assertSame(BackupStatus::Failed, $run->refresh()->status);
        self::assertSame('archive.missing_after_interruption', $artifact->refresh()->failure_code);
    }

    public function test_a_different_archive_at_the_run_path_is_never_adopted(): void
    {
        $run = $this->interruptedRun(BackupProfile::Database);
        $locator = $this->app->make(ArchiveStore::class)->locatorFor($run);
        mkdir(dirname($this->bucketPath($locator)), 0700, true);
        file_put_contents($this->bucketPath($locator), 'not an archive of this run');

        $this->reconciler()->reconcile();

        $artifact = $run->refresh()->artifacts()->where('kind', ArtifactKind::ApplicationArchive->value)->firstOrFail();
        self::assertSame(ArtifactStatus::Failed, $artifact->status);
        self::assertSame(BackupStatus::Failed, $run->status);
        self::assertSame('not an archive of this run', file_get_contents($this->bucketPath($locator)), 'Evidence is never overwritten.');
    }

    public function test_crash_after_restic_snapshot_creation_is_adopted_without_a_second_snapshot(): void
    {
        $run = $this->interruptedRun(BackupProfile::Media);
        $artifact = $this->snapshotThenCrash($run, SnapshotKind::Media);
        self::assertSame(1, $this->backupCount());

        $this->reconciler()->reconcile();

        self::assertSame(BackupStatus::Completed, $run->refresh()->status);
        self::assertSame(ArtifactStatus::Verified, $artifact->refresh()->status);
        self::assertSame(1, $this->backupCount(), 'No duplicate snapshot.');
    }

    public function test_crash_after_snapshot_verification_before_catalog_persistence(): void
    {
        $run = $this->interruptedRun(BackupProfile::Recovery);
        $this->uploadArchiveThenCrash($run);
        $artifact = $this->snapshotThenCrash($run, SnapshotKind::RecoveryMedia);
        $artifact->markVerifying();

        $report = $this->reconciler()->reconcile();

        self::assertSame(0, $report->unresolved());
        self::assertSame(BackupStatus::Completed, $run->refresh()->status);
        self::assertSame(1, $this->backupCount());

        $manifestLocator = $run->artifacts()->where('kind', ArtifactKind::RemoteManifest->value)->value('locator');
        $manifest = json_decode((string) file_get_contents($this->bucketPath((string) $manifestLocator)), true);
        self::assertTrue($manifest['recovery_point']);
    }

    public function test_disaster_recovery_adopts_historical_roots_from_the_immutable_manifest(): void
    {
        [$run, $artifact, $manifestPath, $originalManifest, $pathA, $pathB] = $this->publishedRecoveryPointWithRestoredCatalog();
        $snapshotId = $originalManifest['restic']['snapshot']['id'];

        self::assertNotSame($pathA, $pathB);
        self::assertSame(1, $this->backupCount());

        $report = $this->reconciler()->reconcile();

        self::assertSame(0, $report->unresolved());
        self::assertSame(BackupStatus::Completed, $run->refresh()->status);
        self::assertSame(ArtifactStatus::Verified, $run->artifacts()->where('kind', ArtifactKind::ApplicationArchive->value)->firstOrFail()->status);
        self::assertSame(ArtifactStatus::Verified, $artifact->refresh()->status);
        self::assertSame($snapshotId, $artifact->snapshot_id);
        self::assertSame($originalManifest['restic']['snapshot']['roots'], $artifact->metadata['roots']);
        self::assertSame($pathA, $artifact->metadata['roots'][0]['path']);
        self::assertSame(1, $this->backupCount(), 'Reconciliation must never create another snapshot.');
        self::assertSame($originalManifest, json_decode((string) file_get_contents($manifestPath), true), 'The immutable manifest must not change.');

        $manifestArtifact = $run->artifacts()->where('kind', ArtifactKind::RemoteManifest->value)->firstOrFail();
        self::assertSame(ArtifactStatus::Verified, $manifestArtifact->status, 'Finalization must adopt the identical manifest without collision.');
        self::assertTrue($manifestArtifact->metadata['adopted']);
        self::assertTrue($originalManifest['recovery_point']);

        $health = $this->app->make(BackupHealthService::class)->check(sample: false)->toArray();
        $recoveryPoint = array_values(array_filter($health['checks'], static fn (array $check): bool => $check['id'] === 'health.recovery_point'))[0];
        $unresolved = array_values(array_filter($health['checks'], static fn (array $check): bool => $check['id'] === 'health.unresolved_runs'))[0];
        self::assertSame('pass', $recoveryPoint['status']);
        self::assertSame('pass', $unresolved['status']);
    }

    /** @return iterable<string, array{string}> */
    public static function manifestConflicts(): iterable
    {
        foreach (['id', 'repository_id', 'kind', 'roots'] as $conflict) {
            yield $conflict => [$conflict];
        }
    }

    #[DataProvider('manifestConflicts')]
    public function test_conflicting_immutable_manifest_snapshot_evidence_remains_indeterminate(string $conflict): void
    {
        [$run, , $manifestPath, $manifest] = $this->publishedRecoveryPointWithRestoredCatalog();

        switch ($conflict) {
            case 'id':
                $manifest['restic']['snapshot']['id'] = str_repeat('f', 64);
                break;
            case 'repository_id':
                $manifest['restic']['repository_id'] = str_repeat('e', 64);
                break;
            case 'kind':
                $manifest['restic']['snapshot']['kind'] = 'media';
                break;
            case 'roots':
                $manifest['restic']['snapshot']['roots'][0]['path'] = str_replace('\\', '/', $this->sandbox.'/wrong-root');
                break;
        }
        file_put_contents($manifestPath, json_encode($manifest));

        $report = $this->reconciler()->reconcile();

        self::assertSame(1, $report->unresolved(), $conflict);
        self::assertSame(BackupStatus::Indeterminate, $run->refresh()->status, $conflict);
        self::assertSame(1, $this->backupCount(), $conflict);
    }

    public function test_immutable_manifest_cannot_select_among_ambiguous_physical_snapshots(): void
    {
        [$run] = $this->publishedRecoveryPointWithRestoredCatalog();
        $snapshotsPath = dirname($this->fakeRestic).'/snapshots.json';
        $snapshots = json_decode((string) file_get_contents($snapshotsPath), true);
        $duplicate = $snapshots[0];
        $duplicate['id'] = str_repeat('1', 64);
        $snapshots[] = $duplicate;
        file_put_contents($snapshotsPath, json_encode($snapshots));

        $report = $this->reconciler()->reconcile();

        self::assertSame(1, $report->unresolved());
        self::assertSame(BackupStatus::Indeterminate, $run->refresh()->status);
        self::assertSame('restic.snapshot_ambiguous', $run->failure_code);
        self::assertSame(1, $this->backupCount());
    }

    public function test_immutable_manifest_cannot_adopt_a_recorded_incomplete_snapshot(): void
    {
        [$run, $artifact, , $manifest] = $this->publishedRecoveryPointWithRestoredCatalog();
        $artifact->mergeMetadata(['incomplete_snapshot_id' => $manifest['restic']['snapshot']['id']]);

        $report = $this->reconciler()->reconcile();

        self::assertSame(1, $report->unresolved());
        self::assertSame(BackupStatus::Indeterminate, $run->refresh()->status);
        self::assertSame('restic.snapshot_incomplete', $run->failure_code);
        self::assertSame(1, $this->backupCount());
    }

    public function test_missing_physical_snapshot_with_a_verified_manifest_remains_indeterminate(): void
    {
        [$run] = $this->publishedRecoveryPointWithRestoredCatalog();
        file_put_contents(dirname($this->fakeRestic).'/snapshots.json', '[]');

        $report = $this->reconciler()->reconcile();

        self::assertSame(1, $report->unresolved());
        self::assertSame(BackupStatus::Indeterminate, $run->refresh()->status);
        self::assertSame('restic.snapshot_identity_mismatch', $run->failure_code);
        self::assertSame(1, $this->backupCount());
    }

    /**
     * @return array{BackupRun, BackupArtifact, string, array<string, mixed>, string, string}
     */
    private function publishedRecoveryPointWithRestoredCatalog(): array
    {
        $pathA = str_replace('\\', '/', $this->mediaRoot);
        $run = $this->manager()->run(BackupProfile::Recovery)->run;
        self::assertSame(BackupStatus::Completed, $run->status);

        $archive = $run->artifacts()->where('kind', ArtifactKind::ApplicationArchive->value)->firstOrFail();
        $snapshot = $run->artifacts()->where('kind', ArtifactKind::ResticSnapshot->value)->firstOrFail();
        $manifestArtifact = $run->artifacts()->where('kind', ArtifactKind::RemoteManifest->value)->firstOrFail();
        $manifestPath = $this->bucketPath((string) $manifestArtifact->locator);
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        self::assertSame($pathA, $manifest['restic']['snapshot']['roots'][0]['path']);

        // The application archive captured an earlier catalog state. Its
        // restored row has not learned the final remote artifacts yet.
        $runMetadata = $run->metadata;
        unset($runMetadata['restic_repository_id']);
        BackupRun::query()->whereKey($run->id)->toBase()->update([
            'status' => BackupStatus::Running->value,
            'completed_at' => null,
            'metadata' => json_encode($runMetadata),
        ]);
        BackupArtifact::query()->whereKey($archive->id)->toBase()->update([
            'status' => ArtifactStatus::Creating->value,
            'locator' => null,
            'sha256' => null,
            'byte_size' => null,
            'verified_at' => null,
            'metadata' => '{}',
        ]);
        BackupArtifact::query()->whereKey($snapshot->id)->toBase()->update([
            'status' => ArtifactStatus::Creating->value,
            'snapshot_id' => null,
            'verified_at' => null,
            'metadata' => '{}',
        ]);
        BackupArtifact::query()->whereKey($manifestArtifact->id)->delete();

        $pathB = str_replace('\\', '/', $this->sandbox.'/restored-media');
        mkdir($pathB, 0700, true);
        $this->config()->set('restic.media.roots', ['uploads' => ['path' => $pathB]]);
        $this->refreshBackupServices();

        return [$run->refresh(), $snapshot->refresh(), $manifestPath, $manifest, $pathA, $pathB];
    }

    public function test_ambiguous_snapshots_keep_the_run_indeterminate(): void
    {
        $run = $this->interruptedRun(BackupProfile::Media);
        $this->snapshotThenCrash($run, SnapshotKind::Media);
        $identity = SnapshotIdentity::for($this->app->make(IdentityResolver::class)->current(), SnapshotKind::Media, $run->uuid);
        $this->app->make(ResticRunner::class)->backup(ResticBackupRequest::make([str_replace('\\', '/', $this->mediaRoot)], $identity->tags(), $identity->host()))->throwIfFailed();

        $report = $this->reconciler()->reconcile();

        self::assertSame(1, $report->unresolved());
        self::assertSame(BackupStatus::Indeterminate, $run->refresh()->status);
        self::assertSame('restic.snapshot_ambiguous', $run->failure_code);
        self::assertSame(1, Artisan::call('quraba:backup:reconcile'), 'Unresolved runs make the command fail.');
    }

    public function test_missing_snapshot_is_failed_but_restic_locks_defer_the_decision(): void
    {
        $this->writeScenario(['repository' => 'ready', 'locks' => [str_repeat('7', 64)]]);
        $run = $this->interruptedRun(BackupProfile::Media);
        $run->addArtifact(ArtifactKind::ResticSnapshot)->markCreating();

        $this->reconciler()->reconcile();
        self::assertSame(BackupStatus::Indeterminate, $run->refresh()->status, 'A backup may still be writing.');

        $this->writeScenario(['repository' => 'ready']);
        $this->reconciler()->reconcile();

        self::assertSame(BackupStatus::Failed, $run->refresh()->status);
        self::assertSame(0, $this->backupCount(), 'Reconciliation never creates snapshots.');
    }

    public function test_interrupted_preflight_is_failed_and_dry_run_changes_nothing(): void
    {
        $preflighting = BackupRun::request(BackupProfile::Database, BackupTrigger::Manual)->markPreflighting();
        $running = $this->interruptedRun(BackupProfile::Database);
        $this->uploadArchiveThenCrash($running);

        $dry = $this->reconciler()->reconcile(dryRun: true);
        self::assertTrue($dry->dryRun);
        self::assertSame(BackupStatus::Preflighting, $preflighting->refresh()->status);
        self::assertSame(BackupStatus::Running, $running->refresh()->status);
        self::assertTrue($dry->items[1]['components']['application_archive']['remote_object_exists']);

        $exit = Artisan::call('quraba:backup:reconcile', ['--json' => true]);
        $report = json_decode(Artisan::output(), true);

        self::assertSame(0, $exit);
        self::assertSame(BackupStatus::Failed, $preflighting->refresh()->status);
        self::assertSame(BackupStatus::Completed, $running->refresh()->status);
        self::assertCount(2, $report['runs']);

        $audits = BackupMaintenanceRun::query()->where('operation', MaintenanceOperation::Reconciliation->value)->get();
        self::assertCount(2, $audits, 'Every reconciliation pass is audited.');
    }
}
