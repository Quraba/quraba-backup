<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Quraba\Backup\Archive\ArchiveStore;
use Quraba\Backup\Backup\BackupManager;
use Quraba\Backup\Backup\PendingBackupRequest;
use Quraba\Backup\Enums\ArtifactStatus;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Enums\MaintenanceOperation;
use Quraba\Backup\Enums\MaintenanceStatus;
use Quraba\Backup\Enums\RestoreStatus;
use Quraba\Backup\Exceptions\RepositoryIdentityMismatch;
use Quraba\Backup\Exceptions\RetentionFailed;
use Quraba\Backup\Health\BackupHealthService;
use Quraba\Backup\Identity\IdentityResolver;
use Quraba\Backup\Maintenance\MaintenanceReconciler;
use Quraba\Backup\Manifest\ManifestStore;
use Quraba\Backup\Manifest\RemoteManifestCatalog;
use Quraba\Backup\Models\BackupMaintenanceRun;
use Quraba\Backup\Models\RestoreRun;
use Quraba\Backup\Restore\ArchiveReconstructor;
use Quraba\Backup\Restore\RestoreDryRunService;
use Quraba\Backup\Retention\RetentionExecutor;
use Quraba\Backup\Retention\RetentionTombstone;
use Quraba\Backup\Retention\RetentionTombstoneStore;
use Quraba\Backup\Security\SecretRedactor;
use Quraba\Backup\Storage\FlysystemObjectStorage;
use Quraba\Backup\Storage\RemoteLayout;
use Quraba\Backup\Storage\RemoteStorage;
use Quraba\Backup\Tests\Support\BuildsBackups;
use Quraba\Backup\Tests\Support\FlakyObjectStorage;
use Quraba\Backup\Tests\Support\Sentinels;
use Quraba\Backup\Tests\Support\UsesFakeRestic;
use Quraba\Backup\Tests\TestCase;

final class PhaseSixSevenTest extends TestCase
{
    use BuildsBackups;
    use UsesFakeRestic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeRestic(['repository' => 'ready']);
        $this->prepareBackupPipeline();
    }

    private function restore(string $uuid, string $profile): array
    {
        $this->app->forgetInstance(RestoreDryRunService::class);
        $exit = Artisan::call('quraba:backup:restore', ['--run' => $uuid, '--profile' => $profile, '--json' => true]);
        $result = json_decode(Artisan::output(), true);
        self::assertIsArray($result);
        self::assertSame($result['ok'] ? 0 : 1, $exit);
        self::assertSame('Nothing was changed in the live application.', $result['notice']);

        return $result;
    }

    private function injectFlakyStorage(): FlakyObjectStorage
    {
        $filesystem = new Filesystem(new LocalFilesystemAdapter($this->sandbox.'/b2'));
        $redactor = $this->app->make(SecretRedactor::class);
        $flaky = null;
        $this->app->instance(RemoteStorage::class, new RemoteStorage(
            $this->config(), $this->app->make(IdentityResolver::class),
            static function (RemoteLayout $layout) use ($filesystem, $redactor, &$flaky): FlakyObjectStorage {
                return $flaky = new FlakyObjectStorage(new FlysystemObjectStorage($filesystem, [$layout->resticRoot()], 'local', $redactor));
            },
        ));
        foreach ([ArchiveStore::class, ArchiveReconstructor::class, ManifestStore::class, RemoteManifestCatalog::class, RetentionExecutor::class, RetentionTombstoneStore::class, MaintenanceReconciler::class] as $service) {
            $this->app->forgetInstance($service);
        }
        $this->app->make(RemoteStorage::class)->objects();
        self::assertInstanceOf(FlakyObjectStorage::class, $flaky);

        return $flaky;
    }

    private function keepOneOfEachFamily(): void
    {
        foreach (['database', 'media', 'recovery'] as $family) {
            $this->config()->set('quraba-backup.retention.'.$family, ['keep_latest' => 1]);
        }
    }

    public function test_database_dry_run_reconstructs_and_audits_without_live_changes(): void
    {
        $backup = $this->manager()->run(BackupProfile::Database)->run;
        $before = (string) file_get_contents($this->sandbox.'/.env');
        $this->config()->set('quraba-backup.restore.db_validation_level', 'schema');

        $result = $this->restore($backup->uuid, 'database');

        self::assertTrue($result['ok'], (string) json_encode($result['blockers']));
        self::assertTrue($result['archive_verified']);
        self::assertSame('match', $result['app_key_compatibility']);
        self::assertSame('schema', $result['db_validation_level']);
        self::assertSame($before, (string) file_get_contents($this->sandbox.'/.env'));
        $audit = RestoreRun::query()->latest('id')->firstOrFail();
        self::assertSame(RestoreStatus::Completed, $audit->status);
        self::assertNull($audit->destructive_started_at);
        Sentinels::assertAbsent((string) json_encode($result), 'restore report');
    }

    public function test_remote_only_database_source_works_after_local_run_is_lost(): void
    {
        $backup = $this->manager()->run(BackupProfile::Database)->run;
        $backup->artifacts()->delete();
        $backup->delete();

        $result = $this->restore($backup->uuid, 'database');
        self::assertTrue($result['ok'], (string) json_encode($result['blockers']));
        self::assertSame('remote', $result['source']);
    }

    public function test_wrong_archive_sha_and_password_are_refused(): void
    {
        $backup = $this->manager()->run(BackupProfile::Database)->run;
        $archive = $backup->artifacts()->where('kind', 'application_archive')->firstOrFail();
        file_put_contents($this->bucketPath((string) $archive->locator), 'corrupted');
        $result = $this->restore($backup->uuid, 'database');
        self::assertFalse($result['ok']);

        $second = $this->manager()->run(BackupProfile::Database)->run;
        $this->config()->set('quraba-backup.archive.password', 'wrong-password');
        $result = $this->restore($second->uuid, 'database');
        self::assertFalse($result['ok']);
    }

    public function test_truncated_archive_download_cannot_pass_the_frozen_sha(): void
    {
        $backup = $this->manager()->run(BackupProfile::Database)->run;
        $flaky = $this->injectFlakyStorage();
        $flaky->truncateReadsUnder = ['/archives/'];

        $result = $this->restore($backup->uuid, 'database');
        self::assertFalse($result['ok']);
        self::assertSame(RestoreStatus::Failed, RestoreRun::query()->latest('id')->firstOrFail()->status);
        self::assertNull(RestoreRun::query()->latest('id')->firstOrFail()->destructive_started_at);
    }

    public function test_full_dry_run_uses_exact_snapshot_and_private_media_mapping(): void
    {
        $backup = $this->manager()->run(BackupProfile::Recovery)->run;
        $result = $this->restore($backup->uuid, 'full');

        self::assertTrue($result['ok'], (string) json_encode($result['blockers']));
        self::assertCount(1, $result['media_roots']);
        self::assertSame('uploads', $result['media_roots'][0]['name']);
        self::assertSame('restored-fixture.txt', basename($result['media_roots'][0]['workspace_subtree'].'/restored-fixture.txt'));
        self::assertNotEmpty(array_filter($this->invokedCommands(), static fn (string $command): bool => str_starts_with($command, 'restore '.$result['snapshot_id'])));
        self::assertFileExists($this->mediaRoot.'/uploads/a.jpg');
    }

    public function test_panel_recovery_point_is_restorable_and_legacy_missing_identity_requires_remote_manifest(): void
    {
        $this->config()->set('quraba-backup.filament.pending_enabled', true);
        $requested = $this->app->make(PendingBackupRequest::class)->request(BackupProfile::Recovery);
        $run = $this->app->make(BackupManager::class)->runPending($requested->uuid)->run->refresh();
        $identity = $this->app->make(IdentityResolver::class)->current();

        self::assertSame($identity->appId, $run->metadata['app_id']);
        self::assertSame($identity->environment, $run->metadata['environment']);
        self::assertTrue($this->restore($run->uuid, 'full')['ok']);

        $metadata = $run->metadata;
        unset($metadata['app_id'], $metadata['environment']);
        $run->metadata = $metadata;
        $run->save();
        self::assertTrue($this->restore($run->uuid, 'full')['ok']);

        $manifest = $run->artifacts()->where('kind', 'remote_manifest')->value('locator');
        self::assertIsString($manifest);
        unlink($this->bucketPath($manifest));
        self::assertFalse($this->restore($run->uuid, 'full')['ok']);
    }

    public function test_full_restore_refuses_partial_source_and_force(): void
    {
        $backup = $this->manager()->run(BackupProfile::Database)->run;
        $result = $this->restore($backup->uuid, 'full');
        self::assertFalse($result['ok']);

        $exit = Artisan::call('quraba:backup:restore', ['--run' => $backup->uuid, '--force' => true, '--json' => true]);
        self::assertSame(1, $exit);
        // --force alone is never a live restore: both gates are required.
        self::assertStringContainsString('restore.confirmation_required', Artisan::output());
    }

    public function test_local_remote_source_disagreement_and_app_key_mismatch_block_dry_run(): void
    {
        $backup = $this->manager()->run(BackupProfile::Database)->run;
        $locator = $backup->artifacts()->where('kind', 'remote_manifest')->value('locator');
        self::assertIsString($locator);
        $path = $this->bucketPath($locator);
        $original = (string) file_get_contents($path);
        $manifest = json_decode($original, true);
        $manifest['archive']['sha256'] = str_repeat('a', 64);
        file_put_contents($path, json_encode($manifest));
        $conflict = $this->restore($backup->uuid, 'database');
        self::assertFalse($conflict['ok']);
        self::assertSame('restore.source_conflict', $conflict['error']['code']);

        file_put_contents($path, $original);
        $this->config()->set('app.key', 'base64:another-app-key-fingerprint');
        $mismatch = $this->restore($backup->uuid, 'database');
        self::assertFalse($mismatch['ok']);
        self::assertSame('mismatch', $mismatch['app_key_compatibility']);
    }

    public function test_wrong_repository_or_snapshot_tags_block_media_reconstruction(): void
    {
        $backup = $this->manager()->run(BackupProfile::Media)->run;
        file_put_contents(dirname($this->fakeRestic).'/repo-id', str_repeat('ef', 32));
        $wrongRepository = $this->restore($backup->uuid, 'media');
        self::assertFalse($wrongRepository['ok']);
        @unlink(dirname($this->fakeRestic).'/repo-id');

        $snapshotsPath = dirname($this->fakeRestic).'/snapshots.json';
        $snapshots = json_decode((string) file_get_contents($snapshotsPath), true);
        $snapshots[0]['tags'] = ['quraba-backup', 'app:'.self::APP_ID, 'env:testing', 'kind:media', 'run:11111111-1111-4111-8111-111111111111'];
        file_put_contents($snapshotsPath, json_encode($snapshots));
        $wrongTags = $this->restore($backup->uuid, 'media');
        self::assertFalse($wrongTags['ok']);
    }

    public function test_ambiguous_manifest_media_roots_are_refused(): void
    {
        $backup = $this->manager()->run(BackupProfile::Media)->run;
        $locator = $backup->artifacts()->where('kind', 'remote_manifest')->value('locator');
        self::assertIsString($locator);
        $path = $this->bucketPath($locator);
        $manifest = json_decode((string) file_get_contents($path), true);
        $manifest['restic']['snapshot']['roots'][] = $manifest['restic']['snapshot']['roots'][0];
        file_put_contents($path, json_encode($manifest));

        self::assertFalse($this->restore($backup->uuid, 'media')['ok']);
    }

    public function test_insufficient_staging_space_blocks_before_archive_download(): void
    {
        $backup = $this->manager()->run(BackupProfile::Database)->run;
        $archive = $backup->artifacts()->where('kind', 'application_archive')->firstOrFail();
        $archive->byte_size = 1000000000000000;
        $archive->save();
        $locator = $backup->artifacts()->where('kind', 'remote_manifest')->value('locator');
        self::assertIsString($locator);
        $path = $this->bucketPath($locator);
        $manifest = json_decode((string) file_get_contents($path), true);
        $manifest['archive']['bytes'] = $archive->byte_size;
        file_put_contents($path, json_encode($manifest));

        $result = $this->restore($backup->uuid, 'database');
        self::assertFalse($result['ok']);
        self::assertContains('The private workspace has insufficient free disk space for reconstruction.', $result['blockers']);
        self::assertNull($result['archive_verified']);
    }

    public function test_explicit_exact_database_policy_blocks_an_incomplete_source(): void
    {
        $backup = $this->manager()->run(BackupProfile::Database)->run;
        $this->config()->set('quraba-backup.restore.require_complete_database', true);

        $result = $this->restore($backup->uuid, 'database');
        self::assertFalse($result['ok']);
        self::assertFalse($result['database_exact_object_completeness']);
        self::assertContains('The source archive does not prove complete database object protection required by restore.require_complete_database.', $result['blockers']);
    }

    public function test_atomic_rename_probe_failure_blocks_media_restore_on_posix(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX directory permissions exercise the atomic probe in Linux CI.');
        }

        $backup = $this->manager()->run(BackupProfile::Media)->run;
        $parent = dirname($this->mediaRoot);
        $originalMode = fileperms($parent) & 0777;
        chmod($parent, 0500);
        if (is_writable($parent)) {
            chmod($parent, $originalMode);
            self::markTestSkipped('This user can write despite the restrictive directory mode.');
        }

        try {
            $result = $this->restore($backup->uuid, 'media');
            self::assertFalse($result['ok']);
            self::assertFalse($result['atomic_rename']);
        } finally {
            chmod($parent, $originalMode);
        }
    }

    public function test_missing_exact_snapshot_blocks_media_dry_run(): void
    {
        $backup = $this->manager()->run(BackupProfile::Media)->run;
        file_put_contents(dirname($this->fakeRestic).'/snapshots.json', '[]');

        $result = $this->restore($backup->uuid, 'media');
        self::assertFalse($result['ok']);
        self::assertSame(RestoreStatus::Failed, RestoreRun::query()->latest('id')->firstOrFail()->status);
        self::assertNull(RestoreRun::query()->latest('id')->firstOrFail()->destructive_started_at);
    }

    public function test_tombstoned_archive_cannot_be_a_dry_run_source(): void
    {
        $old = $this->manager()->run(BackupProfile::Database)->run;
        $this->manager()->run(BackupProfile::Database);
        $this->keepOneOfEachFamily();
        self::assertSame(0, Artisan::call('quraba:backup:retention', ['--execute' => true, '--json' => true]), Artisan::output());

        $result = $this->restore($old->uuid, 'database');
        self::assertFalse($result['ok']);
        self::assertSame('restore.source_expired', $result['error']['code']);
    }

    public function test_remote_unavailability_is_unknown_in_health_sampling(): void
    {
        $this->manager()->run(BackupProfile::Media);
        $this->writeScenario(['repository' => 'unreachable']);
        $this->app->forgetInstance(BackupHealthService::class);
        Artisan::call('quraba:backup:health', ['--json' => true]);
        $health = json_decode(Artisan::output(), true);
        $repository = array_values(array_filter($health['checks'], static fn (array $check): bool => $check['id'] === 'health.repository'))[0];
        self::assertSame('unknown', $repository['health_impact']);
    }

    public function test_health_reports_stale_database_backup_by_capture_age(): void
    {
        $backup = $this->manager()->run(BackupProfile::Database)->run;
        $this->config()->set('quraba-backup.health.max_age_hours.database', 1);
        $backup->started_at = CarbonImmutable::now('UTC')->subHours(48);
        $backup->save();

        Artisan::call('quraba:backup:health', ['--json' => true]);
        $checks = json_decode(Artisan::output(), true)['checks'];
        $database = array_values(array_filter($checks, static fn (array $check): bool => $check['id'] === 'health.database_backup'))[0];
        self::assertSame('fail', $database['status']);
        self::assertGreaterThan(47, $database['details']['age_hours']);
    }

    public function test_health_samples_the_exact_archive_object(): void
    {
        $backup = $this->manager()->run(BackupProfile::Database)->run;
        $locator = $backup->artifacts()->where('kind', 'application_archive')->value('locator');
        self::assertIsString($locator);
        unlink($this->bucketPath($locator));

        Artisan::call('quraba:backup:health', ['--json' => true]);
        $checks = json_decode(Artisan::output(), true)['checks'];
        $sample = array_values(array_filter($checks, static fn (array $check): bool => $check['id'] === 'health.archive_sample'))[0];
        self::assertSame('fail', $sample['status']);
    }

    public function test_retention_plan_and_exact_archive_expiry_produce_remote_tombstone(): void
    {
        $old = $this->manager()->run(BackupProfile::Database)->run;
        $new = $this->manager()->run(BackupProfile::Database)->run;
        $this->keepOneOfEachFamily();

        $planExit = Artisan::call('quraba:backup:retention', ['--json' => true]);
        $plan = json_decode(Artisan::output(), true);
        self::assertSame(0, $planExit);
        self::assertSame('plan', $plan['mode']);
        self::assertSame(1, $plan['plan']['expire']);
        self::assertFileExists($this->bucketPath((string) $old->artifacts()->where('kind', 'application_archive')->value('locator')));

        $executeExit = Artisan::call('quraba:backup:retention', ['--execute' => true, '--json' => true]);
        $execute = json_decode(Artisan::output(), true);
        self::assertSame(0, $executeExit, (string) json_encode($execute));
        self::assertSame('completed', $execute['status']);
        $oldArchive = $old->artifacts()->where('kind', 'application_archive')->firstOrFail();
        self::assertSame(ArtifactStatus::Expired, $oldArchive->status);
        self::assertFileDoesNotExist($this->bucketPath((string) $oldArchive->locator));
        self::assertFileExists($this->bucketPath((string) $new->artifacts()->where('kind', 'application_archive')->value('locator')));
        $layout = $this->app->make(RemoteStorage::class)->layout();
        self::assertFileExists($this->bucketPath($layout->componentTombstone($old->uuid, 'application_archive')));
        self::assertFileDoesNotExist($this->bucketPath($layout->componentTombstone($old->uuid, 'media_snapshot')));
        self::assertFileDoesNotExist($this->bucketPath($layout->tombstone($old->uuid)), 'The legacy combined tombstone is no longer written.');

        $discoverExit = Artisan::call('quraba:backup:discover', ['--remote' => true, '--json' => true]);
        $discovered = json_decode(Artisan::output(), true);
        self::assertSame(0, $discoverExit);
        $oldRow = array_values(array_filter($discovered['runs'], static fn (array $row): bool => $row['run_uuid'] === $old->uuid))[0];
        self::assertFalse($oldRow['archive_available']);
        self::assertSame(['application_archive'], $oldRow['expired_components']);
    }

    public function test_retention_plan_keeps_pins_and_the_newest_viable_history(): void
    {
        $old = $this->manager()->run(BackupProfile::Database)->run;
        $new = $this->manager()->run(BackupProfile::Database)->run;
        $old->pin(CarbonImmutable::now('UTC')->addDay(), 'operator protection');
        $this->keepOneOfEachFamily();

        self::assertSame(0, Artisan::call('quraba:backup:retention', ['--json' => true]));
        $decisions = json_decode(Artisan::output(), true)['plan']['decisions'];
        $byUuid = [];
        foreach ($decisions as $decision) {
            $byUuid[$decision['run_uuid']] = $decision;
        }
        self::assertSame('keep', $byUuid[$old->uuid]['decision']);
        self::assertContains('protected:pinned', $byUuid[$old->uuid]['reasons']);
        self::assertSame('keep', $byUuid[$new->uuid]['decision']);
    }

    public function test_check_and_prune_are_audited_and_health_has_a_json_state(): void
    {
        $this->manager()->run(BackupProfile::Media);
        self::assertSame(0, Artisan::call('quraba:backup:restic:check', ['--json' => true]));
        self::assertSame('completed', json_decode(Artisan::output(), true)['status']);
        self::assertSame(0, Artisan::call('quraba:backup:restic:prune', ['--json' => true]));
        self::assertTrue(json_decode(Artisan::output(), true)['dry_run']);
        self::assertSame(2, BackupMaintenanceRun::query()->count());

        Artisan::call('quraba:backup:health', ['--json' => true]);
        $health = json_decode(Artisan::output(), true);
        self::assertContains($health['state'], ['healthy', 'degraded', 'failed', 'unknown']);
        self::assertNotEmpty($health['checks']);
    }

    public function test_failed_check_and_issued_prune_have_truthful_audit_states(): void
    {
        $this->manager()->run(BackupProfile::Media);
        $this->writeScenario(['repository' => 'ready', 'commands' => [
            'check' => ['exit' => 1, 'stderr' => 'injected check failure'],
            'prune' => ['exit' => 1, 'stderr' => 'injected prune failure'],
        ]]);

        self::assertSame(1, Artisan::call('quraba:backup:restic:check', ['--json' => true]));
        self::assertSame(MaintenanceStatus::Failed, BackupMaintenanceRun::query()->latest('id')->firstOrFail()->status);
        self::assertSame(1, Artisan::call('quraba:backup:restic:prune', ['--execute' => true, '--json' => true]));
        self::assertSame(MaintenanceStatus::Indeterminate, BackupMaintenanceRun::query()->latest('id')->firstOrFail()->status);
    }

    public function test_existing_expiry_record_conflict_is_refused_without_overwrite(): void
    {
        $old = $this->manager()->run(BackupProfile::Database)->run;
        $this->manager()->run(BackupProfile::Database);
        $this->keepOneOfEachFamily();
        self::assertSame(0, Artisan::call('quraba:backup:retention', ['--execute' => true, '--json' => true]), Artisan::output());
        $identity = $this->app->make(IdentityResolver::class)->current();
        $store = $this->app->make(RetentionTombstoneStore::class);
        $path = $this->bucketPath($this->app->make(RemoteStorage::class)->layout()->componentTombstone($old->uuid, 'application_archive'));
        $original = (string) file_get_contents($path);

        // An identical expiry is adopted (idempotent retry), never rewritten.
        $store->put(RetentionTombstone::make($old->uuid, $identity, ['application_archive'], CarbonImmutable::now('UTC')->addHour(), $old->uuid));
        self::assertSame($original, file_get_contents($path));

        // A document claiming something else at that path is a collision.
        $foreign = RetentionTombstone::make($old->uuid, $identity, ['media_snapshot'], CarbonImmutable::now('UTC'), $old->uuid);
        file_put_contents($path, $foreign->encode());

        try {
            $store->put(RetentionTombstone::make($old->uuid, $identity, ['application_archive'], CarbonImmutable::now('UTC'), $old->uuid));
            self::fail('A conflicting expiry record was adopted.');
        } catch (RetentionFailed $exception) {
            self::assertSame('retention.tombstone_collision', $exception->failureCode());
            self::assertSame($foreign->encode(), file_get_contents($path));
        }

        // A record whose content contradicts its path never counts as an expiry.
        self::assertSame([], $store->all($identity)['tombstones']);
        self::assertCount(1, $store->all($identity)['malformed']);

        // Combined records are refused: one record, one component.
        $this->expectException(RetentionFailed::class);
        $store->put(RetentionTombstone::make($old->uuid, $identity, ['application_archive', 'media_snapshot'], CarbonImmutable::now('UTC'), $old->uuid));
    }

    public function test_archive_deleted_then_forget_fails_is_immediately_remote_truth_and_later_completed(): void
    {
        $old = $this->manager()->run(BackupProfile::Recovery)->run;
        $this->manager()->run(BackupProfile::Recovery);
        $this->keepOneOfEachFamily();
        $layout = $this->app->make(RemoteStorage::class)->layout();
        $archive = $old->artifacts()->where('kind', 'application_archive')->firstOrFail();
        $snapshot = $old->artifacts()->where('kind', 'restic_snapshot')->firstOrFail();

        // archive deleted → Restic forget fails.
        $this->writeScenario(['repository' => 'ready', 'commands' => ['forget' => ['exit' => 1, 'stderr' => 'injected forget failure']]]);
        $exit = Artisan::call('quraba:backup:retention', ['--execute' => true, '--json' => true]);
        $report = json_decode(Artisan::output(), true);
        self::assertSame(1, $exit);
        self::assertSame('indeterminate', $report['status']);
        self::assertSame('retention.deletion_unproven', $report['failure']['code']);

        // Physical truth and remote truth agree, per component, right now.
        self::assertFileDoesNotExist($this->bucketPath((string) $archive->locator));
        self::assertFileExists($this->bucketPath($layout->componentTombstone($old->uuid, 'application_archive')));
        self::assertFileDoesNotExist($this->bucketPath($layout->componentTombstone($old->uuid, 'media_snapshot')));
        self::assertSame(ArtifactStatus::Expired, $archive->refresh()->status);
        self::assertSame(ArtifactStatus::Verified, $snapshot->refresh()->status, 'A snapshot that is still present is never marked expired.');

        self::assertSame(0, Artisan::call('quraba:backup:discover', ['--remote' => true, '--json' => true]));
        $row = array_values(array_filter(json_decode(Artisan::output(), true)['runs'], static fn (array $r): bool => $r['run_uuid'] === $old->uuid))[0];
        self::assertFalse($row['archive_available']);
        self::assertTrue($row['snapshot_available']);
        self::assertFalse($row['complete_recovery_point']);
        self::assertSame(['application_archive'], $row['expired_components']);

        // Restore source resolution consumes the component record at once.
        self::assertSame('restore.source_expired', $this->restore($old->uuid, 'full')['error']['code']);
        self::assertSame('restore.source_expired', $this->restore($old->uuid, 'database')['error']['code']);

        // Reconciliation settles what is proven and keeps the rest open.
        self::assertSame(1, Artisan::call('quraba:backup:reconcile', ['--json' => true]));
        $reconciled = json_decode(Artisan::output(), true);
        $intent = array_values(array_filter($reconciled['maintenance'], static fn (array $item): bool => $item['type'] === 'retention_intent'))[0];
        self::assertSame('partially_settled', $intent['outcome']);
        self::assertSame(['media_snapshot'], $intent['present']);
        self::assertIsArray($old->refresh()->metadata['retention_pending']);

        // Once Restic works again a later pass completes the partial retention.
        $this->writeScenario(['repository' => 'ready']);
        self::assertSame(0, Artisan::call('quraba:backup:retention', ['--execute' => true, '--json' => true]), Artisan::output());
        self::assertSame(ArtifactStatus::Expired, $snapshot->refresh()->status);
        self::assertFileExists($this->bucketPath($layout->componentTombstone($old->uuid, 'media_snapshot')));
        self::assertNull($old->refresh()->metadata['retention_pending'] ?? null);
        self::assertSame(['application_archive', 'media_snapshot'], $old->metadata['retention']['components']);

        self::assertSame(0, Artisan::call('quraba:backup:discover', ['--remote' => true, '--json' => true]));
        $row = array_values(array_filter(json_decode(Artisan::output(), true)['runs'], static fn (array $r): bool => $r['run_uuid'] === $old->uuid))[0];
        self::assertSame(['application_archive', 'media_snapshot'], $row['expired_components']);
        self::assertSame(0, Artisan::call('quraba:backup:reconcile', ['--json' => true]), Artisan::output());
    }

    public function test_legacy_combined_tombstones_remain_readable_and_are_unioned(): void
    {
        $run = $this->manager()->run(BackupProfile::Recovery)->run;
        $identity = $this->app->make(IdentityResolver::class)->current();
        $layout = $this->app->make(RemoteStorage::class)->layout();
        $legacy = RetentionTombstone::make($run->uuid, $identity, ['application_archive'], CarbonImmutable::now('UTC'), $run->uuid);
        @mkdir(dirname($this->bucketPath($layout->tombstone($run->uuid))), 0700, true);
        file_put_contents($this->bucketPath($layout->tombstone($run->uuid)), $legacy->encode());
        $store = $this->app->make(RetentionTombstoneStore::class);

        self::assertSame(['application_archive'], $store->find($run->uuid)?->components);
        self::assertSame('restore.source_expired', $this->restore($run->uuid, 'database')['error']['code']);

        $store->put(RetentionTombstone::make($run->uuid, $identity, ['media_snapshot'], CarbonImmutable::now('UTC'), $run->uuid));
        self::assertSame(['application_archive', 'media_snapshot'], $store->find($run->uuid)?->components);
        self::assertSame(['application_archive', 'media_snapshot'], $store->all($identity)['tombstones'][$run->uuid]->components);
        self::assertSame([], $store->all($identity)['malformed']);
    }

    public function test_interrupted_check_is_closed_and_actual_prune_remains_indeterminate(): void
    {
        $check = BackupMaintenanceRun::plan(MaintenanceOperation::ResticCheck, false)->markRunning();
        $prune = BackupMaintenanceRun::plan(MaintenanceOperation::ResticPrune, false)->markRunning();

        $items = $this->app->make(MaintenanceReconciler::class)->reconcile(
            $this->app->make(IdentityResolver::class)->current(), false, 'current-reconciliation',
        );

        self::assertCount(2, $items);
        self::assertSame(MaintenanceStatus::Failed, $check->refresh()->status);
        self::assertSame(MaintenanceStatus::Indeterminate, $prune->refresh()->status);
    }

    public function test_retention_forgets_only_exact_full_snapshot_id(): void
    {
        $old = $this->manager()->run(BackupProfile::Media)->run;
        $new = $this->manager()->run(BackupProfile::Media)->run;
        $this->keepOneOfEachFamily();
        $oldId = $old->artifacts()->where('kind', 'restic_snapshot')->value('snapshot_id');
        $newId = $new->artifacts()->where('kind', 'restic_snapshot')->value('snapshot_id');
        self::assertIsString($oldId);
        self::assertIsString($newId);

        $exit = Artisan::call('quraba:backup:retention', ['--execute' => true, '--json' => true]);
        self::assertSame(0, $exit, Artisan::output());
        self::assertNotEmpty(array_filter($this->invokedCommands(), static fn (string $command): bool => $command === 'forget --json -- '.$oldId));
        self::assertEmpty(array_filter($this->invokedCommands(), static fn (string $command): bool => $command === 'forget --json -- '.$newId));
        self::assertSame(ArtifactStatus::Expired, $old->artifacts()->where('kind', 'restic_snapshot')->firstOrFail()->status);
    }

    public function test_remote_repository_identity_conflict_refuses_to_choose_newest(): void
    {
        $first = $this->manager()->run(BackupProfile::Media)->run;
        $second = $this->manager()->run(BackupProfile::Media)->run;
        $manifest = $second->artifacts()->where('kind', 'remote_manifest')->value('locator');
        self::assertIsString($manifest);
        $path = $this->bucketPath($manifest);
        $json = json_decode((string) file_get_contents($path), true);
        $json['restic']['repository_id'] = str_repeat('cd', 32);
        file_put_contents($path, json_encode($json));

        self::assertNotSame($first->uuid, $second->uuid);
        $this->expectException(RepositoryIdentityMismatch::class);
        $this->app->make(ManifestStore::class)->repositoryIdConsensus($this->app->make(IdentityResolver::class)->current());
    }

    public function test_tombstone_upload_failure_after_physical_delete_is_reconciled(): void
    {
        $old = $this->manager()->run(BackupProfile::Database)->run;
        $this->manager()->run(BackupProfile::Database);
        $this->keepOneOfEachFamily();
        $flaky = $this->injectFlakyStorage();
        $flaky->failWritesUnder = ['/retention/'];

        $exit = Artisan::call('quraba:backup:retention', ['--execute' => true, '--json' => true]);
        $report = json_decode(Artisan::output(), true);
        self::assertSame(1, $exit);
        self::assertSame('indeterminate', $report['status']);
        $archive = $old->artifacts()->where('kind', 'application_archive')->firstOrFail();
        self::assertFileDoesNotExist($this->bucketPath((string) $archive->locator));
        self::assertSame(ArtifactStatus::Verified, $archive->status);

        $flaky->failWritesUnder = [];
        $reconcileExit = Artisan::call('quraba:backup:reconcile', ['--json' => true]);
        self::assertSame(0, $reconcileExit, Artisan::output());
        self::assertSame(ArtifactStatus::Expired, $archive->refresh()->status);
        self::assertFileExists($this->bucketPath($this->app->make(RemoteStorage::class)->layout()->componentTombstone($old->uuid, 'application_archive')));
    }

    public function test_catalog_write_failure_after_delete_remains_reconcilable(): void
    {
        $old = $this->manager()->run(BackupProfile::Database)->run;
        $this->manager()->run(BackupProfile::Database);
        $this->keepOneOfEachFamily();
        $database = $this->app->make('db')->connection();
        $database->statement("CREATE TRIGGER block_expiry BEFORE UPDATE OF status ON quraba_backup_artifacts WHEN NEW.status = 'expired' BEGIN SELECT RAISE(FAIL, 'injected catalog write failure'); END");

        try {
            $exit = Artisan::call('quraba:backup:retention', ['--execute' => true, '--json' => true]);
            $report = json_decode(Artisan::output(), true);
            self::assertSame(1, $exit);
            self::assertSame('indeterminate', $report['status']);
            self::assertFileExists($this->bucketPath($this->app->make(RemoteStorage::class)->layout()->componentTombstone($old->uuid, 'application_archive')));
        } finally {
            $database->statement('DROP TRIGGER block_expiry');
        }

        self::assertSame(0, Artisan::call('quraba:backup:reconcile', ['--json' => true]), Artisan::output());
        self::assertSame(ArtifactStatus::Expired, $old->artifacts()->where('kind', 'application_archive')->firstOrFail()->status);
    }

    public function test_exact_snapshot_forget_before_catalog_failure_is_reconciled(): void
    {
        $old = $this->manager()->run(BackupProfile::Media)->run;
        $this->manager()->run(BackupProfile::Media);
        $this->keepOneOfEachFamily();
        $snapshotId = $old->artifacts()->where('kind', 'restic_snapshot')->value('snapshot_id');
        self::assertIsString($snapshotId);

        $database = $this->app->make('db')->connection();
        $database->statement("CREATE TRIGGER block_snapshot_expiry BEFORE UPDATE OF status ON quraba_backup_artifacts WHEN NEW.status = 'expired' BEGIN SELECT RAISE(FAIL, 'injected catalog write failure'); END");
        try {
            $exit = Artisan::call('quraba:backup:retention', ['--execute' => true, '--json' => true]);
            self::assertSame(1, $exit);
            self::assertSame('indeterminate', json_decode(Artisan::output(), true)['status']);
            self::assertNotEmpty(array_filter($this->invokedCommands(), static fn (string $command): bool => $command === 'forget --json -- '.$snapshotId));
        } finally {
            $database->statement('DROP TRIGGER block_snapshot_expiry');
        }

        self::assertSame(0, Artisan::call('quraba:backup:reconcile', ['--json' => true]), Artisan::output());
        self::assertSame(ArtifactStatus::Expired, $old->artifacts()->where('kind', 'restic_snapshot')->firstOrFail()->status);
    }
}
