<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Feature\Recovery;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Quraba\Backup\Enums\ArtifactKind;
use Quraba\Backup\Enums\ArtifactStatus;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Enums\BackupStatus;
use Quraba\Backup\Enums\BackupTrigger;
use Quraba\Backup\Enums\RestoreStatus;
use Quraba\Backup\Models\BackupArtifact;
use Quraba\Backup\Models\BackupMaintenanceRun;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Models\RestoreRun;
use Quraba\Backup\Restore\Journal\RestoreJournal;
use Quraba\Backup\Storage\RemoteStorage;
use Quraba\Backup\Support\LocalCatalog;
use Quraba\Backup\Tests\Support\RunsLiveRestores;
use Quraba\Backup\Tests\Support\Sentinels;
use Quraba\Backup\Tests\TestCase;

/**
 * Disaster recovery onto a clean host: no local catalog, an empty target
 * database without package migrations, no media — only remote storage and
 * the minimal recovery configuration.
 */
final class CleanHostRecoveryTest extends TestCase
{
    use RunsLiveRestores;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareLiveRestore();
    }

    /**
     * What a new server looks like: the database exists but is empty (not
     * even the package's tables), and there is no media.
     */
    private function becomeCleanHost(bool $keepMediaRoot = false): void
    {
        DB::statement('PRAGMA defer_foreign_keys = ON');

        foreach (DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'") as $table) {
            DB::statement('DROP TABLE "'.$table->name.'"');
        }

        self::assertSame([], Schema::getTableListing());
        self::assertFalse($this->app->make(LocalCatalog::class)->available());

        self::removeTree($this->mediaRoot);

        if ($keepMediaRoot) {
            mkdir($this->mediaRoot, 0700);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function runJson(string $command, array $parameters, int $expectedExit = 0): array
    {
        $exit = Artisan::call($command, [...$parameters, '--json' => true]);
        $output = Artisan::output();
        self::assertSame($expectedExit, $exit, $output);
        $decoded = json_decode($output, true);
        self::assertIsArray($decoded, $output);
        Sentinels::assertAbsent($output, $command.' output');

        return $decoded;
    }

    public function test_remote_discovery_and_dry_run_work_without_any_local_catalog(): void
    {
        $source = $this->recoveryPointOfStateA();
        $this->becomeCleanHost();

        $discovered = $this->runJson('quraba:backup:discover', ['--remote' => true]);
        $row = array_values(array_filter($discovered['runs'], static fn (array $run): bool => $run['run_uuid'] === $source->uuid))[0];
        self::assertTrue($row['complete_recovery_point']);
        self::assertTrue($row['archive_available']);
        self::assertTrue($row['snapshot_available']);

        // The dry run needs no audit table and creates none.
        $dryRun = $this->runJson('quraba:backup:restore', ['--run' => $source->uuid, '--profile' => 'full']);
        self::assertTrue($dryRun['ok'], (string) json_encode($dryRun['blockers']));
        self::assertSame('remote', $dryRun['source']);
        self::assertNull($dryRun['restore_run_uuid']);
        self::assertFalse($dryRun['media_roots'][0]['live_exists']);
        self::assertSame([], Schema::getTableListing(), 'Nothing was migrated or created in the empty target database.');
        self::assertDirectoryDoesNotExist($this->mediaRoot);
    }

    public function test_clean_host_restore_needs_no_migrations_and_hands_the_audit_back(): void
    {
        $source = $this->recoveryPointOfStateA();
        $this->becomeCleanHost();

        // Missing catalog tables alone never imply a clean host.
        [$exit, $refused] = $this->liveRestore($source->uuid, 'full');
        self::assertSame(1, $exit);
        self::assertSame('restore.catalog_unavailable', $refused['error']['code']);
        self::assertStringContainsString('--clean-host', $refused['error']['message']);
        self::assertSame([], Schema::getTableListing());

        [$exit, $report] = $this->liveRestore($source->uuid, 'full', ['--clean-host' => true]);
        self::assertSame(0, $exit, (string) json_encode($report));
        self::assertSame('completed', $report['status']);
        self::assertTrue($report['clean_host']);

        // Exactly state A — which is the state the backup captured, catalog included.
        self::assertSame(['note-a1', 'note-a2'], DB::table('app_notes')->orderBy('id')->pluck('body')->all());
        self::assertFalse(Schema::hasTable('b_only'));
        self::assertSame('image-a', file_get_contents($this->mediaRoot.'/uploads/a.jpg'));
        self::assertSame('doc-a', file_get_contents($this->mediaRoot.'/docs/readme.txt'));
        self::assertFileDoesNotExist($this->mediaRoot.'/uploads/new-in-b.txt');
        self::assertTrue($this->app->make(LocalCatalog::class)->available(), 'The imported backup brought its catalog.');

        // No safety backup was possible or needed, and the journal says why.
        $journal = $this->journal($report['restore_uuid']);
        self::assertSame(RestoreJournal::TERMINAL_COMPLETED, $journal->terminalState());
        self::assertSame(RestoreJournal::SAFETY_NOT_REQUIRED, $journal->safetyBackup()['requirement']);
        self::assertSame('not_required_target_proven_empty', $journal->safetyBackup()['requirement']);
        self::assertNull($journal->safetyRunUuid());
        self::assertSame(0, $journal->safetyBackup()['evidence']['database']['objects']);
        self::assertSame(['uploads' => 'absent'], $journal->safetyBackup()['evidence']['media']);
        self::assertNull($journal->media()['uploads']['parked'], 'Nothing existed, so nothing was parked.');
        self::assertSame([], $this->parkedDirectories());
        self::assertSame(0, BackupRun::query()->where('trigger', BackupTrigger::PreRestore->value)->count());

        // The audit row did not exist before the import; the journal handed it back.
        self::assertSame('synced', $journal->annotations()['audit_handback']);
        $audit = RestoreRun::query()->where('uuid', $report['restore_uuid'])->firstOrFail();
        self::assertSame(RestoreStatus::Completed, $audit->status);
        self::assertSame($source->uuid, $audit->source_run_uuid);
        self::assertNull($audit->pre_change_run_uuid);
        self::assertSame('not_required_target_proven_empty', $audit->metadata['safety_backup']);
        self::assertTrue($audit->metadata['clean_host']);
        self::assertTrue($this->quiescenceProvider->active, 'Still quiesced: the operator brings the application up.');

        // The catalog is made coherent again from remote truth.
        $this->runJson('quraba:backup:reconcile', []);
        self::assertSame(BackupStatus::Completed, BackupRun::query()->where('uuid', $source->uuid)->firstOrFail()->status);
        [$reconcileExit, $reconciled] = $this->reconcileRestore($report['restore_uuid']);
        self::assertSame(0, $reconcileExit);
        self::assertSame('completed', $reconciled['outcome']);
    }

    public function test_clean_host_mode_is_refused_unless_the_target_is_proven_empty(): void
    {
        $source = $this->recoveryPointOfStateA();

        // A normal host cannot skip its safety backup by claiming to be clean.
        [$exit, $report] = $this->liveRestore($source->uuid, 'full', ['--clean-host' => true]);
        self::assertSame(1, $exit);
        self::assertSame('restore.clean_host_refused', $report['error']['code']);
        self::assertStringContainsString('schema object(s)', $report['error']['message']);
        $this->assertDatabaseIsStateB();
        $this->assertMediaIsStateB();
        self::assertSame(0, $this->replacement->clears);

        // One application table without any package table is not "empty" either.
        $this->becomeCleanHost();
        DB::statement('CREATE TABLE leftover_orders (id integer)');
        $this->refreshLiveServices();
        [, $report] = $this->liveRestore($source->uuid, 'full', ['--clean-host' => true]);
        self::assertSame('restore.clean_host_refused', $report['error']['code']);
        self::assertTrue(Schema::hasTable('leftover_orders'));
        DB::statement('DROP TABLE leftover_orders');

        // Media that already holds a file is not empty.
        mkdir($this->mediaRoot.'/uploads', 0700, true);
        file_put_contents($this->mediaRoot.'/uploads/someone-elses.jpg', 'existing');
        $this->refreshLiveServices();
        [, $report] = $this->liveRestore($source->uuid, 'full', ['--clean-host' => true]);
        self::assertSame('restore.clean_host_refused', $report['error']['code']);
        self::assertStringContainsString('media root [uploads]', $report['error']['message']);
        self::assertFileExists($this->mediaRoot.'/uploads/someone-elses.jpg');
        self::assertSame([], Schema::getTableListing(), 'Refused before anything was imported.');
    }

    public function test_a_media_root_holding_only_framework_placeholders_counts_as_empty(): void
    {
        $source = $this->recoveryPointOfStateA();
        $this->becomeCleanHost(keepMediaRoot: true);
        file_put_contents($this->mediaRoot.'/.gitignore', "*\n!.gitignore\n");

        [$exit, $report] = $this->liveRestore($source->uuid, 'media', ['--clean-host' => true]);
        self::assertSame(0, $exit, (string) json_encode($report));
        self::assertSame('image-a', file_get_contents($this->mediaRoot.'/uploads/a.jpg'));
        self::assertFileDoesNotExist($this->mediaRoot.'/.gitignore', 'Exact replacement: the placeholder directory was parked.');
        self::assertCount(1, $this->parkedDirectories());
        self::assertSame(['uploads' => 'empty'], $this->journal($report['restore_uuid'])->safetyBackup()['evidence']['media']);
        // A media-only clean-host restore leaves the empty database alone.
        self::assertSame([], Schema::getTableListing());
    }

    public function test_bootstrap_env_recovers_only_the_env_privately_from_the_remote_manifest(): void
    {
        $source = $this->manager()->run(BackupProfile::Database)->run;
        $original = (string) file_get_contents($this->sandbox.'/.env');
        $this->becomeCleanHost();
        $target = $this->sandbox.'/recovered.env';

        $result = $this->runJson('quraba:backup:bootstrap-env', ['--run' => $source->uuid, '--target' => $target]);
        self::assertTrue($result['ok']);
        self::assertSame(str_replace('\\', '/', $target), str_replace('\\', '/', $result['target']));
        self::assertSame(strlen($original), $result['bytes']);
        self::assertSame($original, file_get_contents($target), 'Exactly the archived .env.');
        self::assertSame($source->artifacts()->getQuery()->getConnection()->getName(), 'testing');

        if (PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(0600, fileperms($target) & 0777);
        }

        // Human output: never the content.
        self::assertSame(1, Artisan::call('quraba:backup:bootstrap-env', ['--run' => $source->uuid, '--target' => $target]));
        $text = Artisan::output();
        Sentinels::assertAbsent($text, 'bootstrap-env output');
        self::assertStringContainsString('never overwritten by default', $text);
        self::assertSame($original, file_get_contents($target));

        // The live .env is never the default target and is never touched.
        self::assertSame($original, file_get_contents($this->sandbox.'/.env'));
        self::assertSame([], Schema::getTableListing(), 'No database is needed and none is touched.');
        self::assertDirectoryDoesNotExist($this->mediaRoot, 'Nothing else is restored.');

        // Overwriting needs the strong flag AND the exact phrase.
        file_put_contents($target, 'stale');
        $refused = $this->runJson('quraba:backup:bootstrap-env', ['--run' => $source->uuid, '--target' => $target, '--overwrite' => true], 1);
        self::assertSame('restore.confirmation_required', $refused['error']['code']);
        self::assertSame('stale', file_get_contents($target));
        $overwritten = $this->runJson('quraba:backup:bootstrap-env', ['--run' => $source->uuid, '--target' => $target, '--overwrite' => true, '--confirm' => 'OVERWRITE_ENV']);
        self::assertTrue($overwritten['overwritten']);
        self::assertSame($original, file_get_contents($target));
        self::assertSame([], glob($this->sandbox.'/recovered.env.quraba-*') ?: []);
    }

    public function test_bootstrap_env_refuses_unsafe_targets_wrong_secrets_and_expired_or_unknown_runs(): void
    {
        $old = $this->manager()->run(BackupProfile::Database)->run;
        $source = $this->manager()->run(BackupProfile::Database)->run;

        // Inside the public web directory.
        @mkdir($this->app->publicPath(), 0700, true);
        $public = $this->runJson('quraba:backup:bootstrap-env', ['--run' => $source->uuid, '--target' => $this->app->publicPath('.env')], 1);
        self::assertSame('recovery.env_bootstrap_failed', $public['error']['code']);
        self::assertStringContainsString('public web directory', $public['error']['message']);
        self::assertFileDoesNotExist($this->app->publicPath('.env'));

        // A relative target.
        self::assertSame('recovery.env_bootstrap_failed', $this->runJson('quraba:backup:bootstrap-env', ['--run' => $source->uuid, '--target' => 'relative.env'], 1)['error']['code']);

        // Not an exact run.
        self::assertSame(1, Artisan::call('quraba:backup:bootstrap-env', ['--run' => 'latest', '--target' => $this->sandbox.'/x.env', '--json' => true]));
        self::assertSame('restore.source_unavailable', $this->runJson('quraba:backup:bootstrap-env', ['--run' => '0198c0de-0000-7000-8000-00000000abcd', '--target' => $this->sandbox.'/x.env'], 1)['error']['code']);

        // The wrong archive password: nothing is written.
        $this->config()->set('quraba-backup.archive.password', 'not-the-archive-password');
        $wrong = $this->runJson('quraba:backup:bootstrap-env', ['--run' => $source->uuid, '--target' => $this->sandbox.'/x.env'], 1);
        self::assertSame('recovery.env_bootstrap_failed', $wrong['error']['code']);
        self::assertFileDoesNotExist($this->sandbox.'/x.env');
        $this->config()->set('quraba-backup.archive.password', Sentinels::ARCHIVE_PASSWORD);

        // A tampered archive: its SHA-256 no longer equals the manifest.
        $locator = (string) $source->artifacts()->where('kind', 'application_archive')->value('locator');
        $bytes = (string) file_get_contents($this->bucketPath($locator));
        file_put_contents($this->bucketPath($locator), $bytes.'x');
        $tampered = $this->runJson('quraba:backup:bootstrap-env', ['--run' => $source->uuid, '--target' => $this->sandbox.'/x.env'], 1);
        self::assertStringContainsString('SHA-256 differs', $tampered['error']['message']);
        self::assertFileDoesNotExist($this->sandbox.'/x.env');
        file_put_contents($this->bucketPath($locator), $bytes);

        // An archive expired by retention.
        $this->config()->set('quraba-backup.retention.database', ['keep_latest' => 1]);
        $this->runJson('quraba:backup:retention', ['--execute' => true]);
        self::assertSame('restore.source_expired', $this->runJson('quraba:backup:bootstrap-env', ['--run' => $old->uuid, '--target' => $this->sandbox.'/x.env'], 1)['error']['code']);
        self::assertFileDoesNotExist($this->sandbox.'/x.env');
    }

    public function test_recovery_checklist_names_what_is_missing_without_ever_printing_a_value(): void
    {
        $this->manager()->run(BackupProfile::Media);

        $ready = $this->runJson('quraba:backup:recovery-checklist', []);
        self::assertTrue($ready['ready']);
        $states = array_column($ready['items'], 'state', 'key');
        foreach (['QURABA_BACKUP_APP_ID', 'QURABA_BACKUP_B2_ENDPOINT', 'QURABA_BACKUP_B2_BUCKET', 'QURABA_BACKUP_B2_KEY_ID', 'QURABA_BACKUP_B2_APPLICATION_KEY', 'QURABA_BACKUP_ARCHIVE_PASSWORD', 'QURABA_BACKUP_RESTIC_PASSWORD_FILE'] as $key) {
            self::assertSame('configured', $states[$key], $key);
        }
        self::assertSame(str_repeat('ab', 32), $ready['repository_id'], 'The repository ID is an identity, not a secret.');
        self::assertSame('local catalog', $ready['repository_id_source']);

        // Human output: names and guidance only.
        Artisan::call('quraba:backup:recovery-checklist');
        $text = Artisan::output();
        Sentinels::assertAbsent($text, 'recovery checklist');
        self::assertStringContainsString('QURABA_BACKUP_ARCHIVE_PASSWORD', $text);
        self::assertStringContainsString('CONFIGURED', $text);
        self::assertStringNotContainsString(self::APP_ID, $text, 'Not even the application ID value is printed.');

        // Missing material is named and fails the command.
        $this->config()->set('quraba-backup.archive.password', '');
        $this->config()->set('quraba-backup.storage.b2.application_key', null);
        $missing = $this->runJson('quraba:backup:recovery-checklist', [], 1);
        self::assertFalse($missing['ready']);
        $states = array_column($missing['items'], 'state', 'key');
        self::assertSame('missing', $states['QURABA_BACKUP_ARCHIVE_PASSWORD']);
        self::assertSame('missing', $states['QURABA_BACKUP_B2_APPLICATION_KEY']);

        // On a clean host the repository identity comes from the manifests.
        $this->becomeCleanHost();
        self::assertNull($this->runJson('quraba:backup:recovery-checklist', [], 1)['repository_id']);
        $remote = $this->runJson('quraba:backup:recovery-checklist', ['--remote' => true], 1);
        self::assertSame(str_repeat('ab', 32), $remote['repository_id']);
        self::assertSame('remote manifests', $remote['repository_id_source']);
    }

    public function test_catalog_rebuild_plans_by_default_and_adopts_only_what_is_physically_present(): void
    {
        $database = $this->manager()->run(BackupProfile::Database)->run;
        $media = $this->manager()->run(BackupProfile::Media)->run;
        $recovery = $this->manager()->run(BackupProfile::Recovery)->run;
        $expired = $this->manager()->run(BackupProfile::Database)->run;
        $gone = $this->manager()->run(BackupProfile::Database)->run;
        $newest = $this->manager()->run(BackupProfile::Database)->run;
        $requestedAt = $recovery->requested_at;
        $layout = $this->app->make(RemoteStorage::class)->layout();

        // Retention removes one archive properly (expiry record) …
        $this->config()->set('quraba-backup.retention.database', ['keep_latest' => 1]);
        $database->pin(now('UTC')->addDay(), 'keep for the test');
        $gone->pin(now('UTC')->addDay(), 'keep for the test');
        $this->runJson('quraba:backup:retention', ['--execute' => true]);
        self::assertFileExists($this->bucketPath($layout->componentTombstone($expired->uuid, 'application_archive')));
        // … another archive simply vanished, and the recovery run lost its snapshot.
        unlink($this->bucketPath((string) $gone->artifacts()->where('kind', 'application_archive')->value('locator')));
        $recoverySnapshot = $recovery->artifacts()->where('kind', 'restic_snapshot')->value('snapshot_id');
        $snapshotsFile = dirname($this->fakeRestic).'/snapshots.json';
        file_put_contents($snapshotsFile, json_encode(array_values(array_filter(json_decode((string) file_get_contents($snapshotsFile), true), static fn (array $s): bool => $s['id'] !== $recoverySnapshot))));

        // The catalog is lost (tables exist, rows are gone).
        BackupArtifact::query()->delete();
        BackupRun::query()->delete();

        // PLAN ONLY: nothing is written.
        $plan = $this->runJson('quraba:backup:catalog:rebuild', []);
        self::assertSame('plan', $plan['mode']);
        $actions = array_column($plan['runs'], null, 'run_uuid');
        self::assertSame('adopt', $actions[$database->uuid]['action']);
        self::assertSame('adopt', $actions[$media->uuid]['action']);
        self::assertSame('adopt', $actions[$recovery->uuid]['action']);
        self::assertSame('skip_nothing_present', $actions[$expired->uuid]['action']);
        self::assertSame('expired', $actions[$expired->uuid]['components']['application_archive']);
        self::assertSame('skip_nothing_present', $actions[$gone->uuid]['action']);
        self::assertSame('missing', $actions[$gone->uuid]['components']['application_archive']);
        self::assertSame(['application_archive' => 'verified', 'media_snapshot' => 'missing'], $actions[$recovery->uuid]['components']);
        self::assertSame(0, BackupRun::query()->count());
        self::assertSame(0, BackupMaintenanceRun::query()->where('operation', 'catalog_rebuild')->count(), 'A plan is not even audited: it wrote nothing.');

        // APPLY.
        $applied = $this->runJson('quraba:backup:catalog:rebuild', ['--apply' => true]);
        self::assertSame('apply', $applied['mode']);
        self::assertCount(4, $applied['applied']);

        $adopted = BackupRun::query()->where('uuid', $database->uuid)->with('artifacts')->firstOrFail();
        self::assertSame(BackupStatus::Completed, $adopted->status);
        self::assertSame(BackupProfile::Database, $adopted->profile);
        self::assertSame($database->requested_at?->toIso8601ZuluString(), $adopted->requested_at?->toIso8601ZuluString(), 'The original request time is kept.');
        self::assertSame($layout->manifest($database), $adopted->metadata['catalog_rebuilt']['manifest']);
        $archive = $adopted->artifacts->firstWhere('kind', ArtifactKind::ApplicationArchive);
        self::assertSame(ArtifactStatus::Verified, $archive->status);
        self::assertSame($database->artifacts()->getModel()->getTable(), 'quraba_backup_artifacts');
        self::assertStringContainsString('physical remote stream size and SHA-256', $archive->metadata['verification']);
        self::assertSame(ArtifactStatus::Verified, $adopted->artifacts->firstWhere('kind', ArtifactKind::RemoteManifest)->status);

        // The run whose snapshot is gone is NOT adopted as a Recovery Point.
        $partial = BackupRun::query()->where('uuid', $recovery->uuid)->with('artifacts')->firstOrFail();
        self::assertSame(BackupStatus::Partial, $partial->status);
        self::assertSame(ArtifactStatus::Failed, $partial->artifacts->firstWhere('kind', ArtifactKind::ResticSnapshot)->status, 'A manifest claim alone never becomes a verified artifact.');
        self::assertSame('catalog.component_missing', $partial->failure_code);
        self::assertSame($requestedAt?->toIso8601ZuluString(), $partial->requested_at?->toIso8601ZuluString());

        // Nothing physically present: not adopted at all.
        self::assertNull(BackupRun::query()->where('uuid', $expired->uuid)->first());
        self::assertNull(BackupRun::query()->where('uuid', $gone->uuid)->first());

        // An adopted run is a normal exact restore source again.
        $dryRun = $this->runJson('quraba:backup:restore', ['--run' => $newest->uuid, '--profile' => 'database']);
        self::assertTrue($dryRun['ok'], (string) json_encode($dryRun['blockers']));
        self::assertSame('local+remote', $dryRun['source']);

        // Idempotent: a second run finds everything present.
        $again = $this->runJson('quraba:backup:catalog:rebuild', ['--apply' => true]);
        self::assertSame([], $again['applied']);
        self::assertSame(2, BackupMaintenanceRun::query()->where('operation', 'catalog_rebuild')->where('status', 'completed')->count());
    }

    public function test_catalog_rebuild_rejects_same_sized_remote_archive_corruption(): void
    {
        $run = $this->manager()->run(BackupProfile::Database)->run;
        $locator = $run->artifacts()->where('kind', 'application_archive')->value('locator');
        self::assertIsString($locator);
        $path = $this->bucketPath($locator);
        $handle = fopen($path, 'r+b');
        self::assertIsResource($handle);
        try {
            $first = fread($handle, 1);
            self::assertIsString($first);
            rewind($handle);
            fwrite($handle, chr(ord($first) ^ 0xFF));
        } finally {
            fclose($handle);
        }

        BackupArtifact::query()->delete();
        BackupRun::query()->delete();

        $plan = $this->runJson('quraba:backup:catalog:rebuild', []);
        $row = array_values(array_filter($plan['runs'], static fn (array $candidate): bool => $candidate['run_uuid'] === $run->uuid))[0];
        self::assertSame('hash_mismatch', $row['components']['application_archive']);
        self::assertSame('skip_nothing_present', $row['action']);

        $applied = $this->runJson('quraba:backup:catalog:rebuild', ['--apply' => true]);
        self::assertSame([], $applied['applied']);
        self::assertNull(BackupRun::query()->where('uuid', $run->uuid)->first());
    }

    public function test_catalog_rebuild_respects_expiry_records_stale_rows_and_repository_identity(): void
    {
        $old = $this->manager()->run(BackupProfile::Database)->run;
        $this->manager()->run(BackupProfile::Database);
        $mediaRun = $this->manager()->run(BackupProfile::Media)->run;

        // An OLDER catalog is restored: it predates the retention that
        // removed $old's archive, so it still calls that archive verified.
        $this->config()->set('quraba-backup.retention.database', ['keep_latest' => 1]);
        $this->runJson('quraba:backup:retention', ['--execute' => true]);
        $archive = $old->artifacts()->where('kind', 'application_archive')->firstOrFail();
        DB::table('quraba_backup_artifacts')->where('id', $archive->id)->update(['status' => 'verified', 'expired_at' => null]);

        // And a stale row frozen mid-run, as every restored catalog contains.
        DB::table('quraba_backup_runs')->where('uuid', $mediaRun->uuid)->update(['status' => 'running']);

        $plan = $this->runJson('quraba:backup:catalog:rebuild', []);
        $actions = array_column($plan['runs'], 'action', 'run_uuid');
        self::assertSame('expire_locally', $actions[$old->uuid]);
        self::assertSame('needs_reconcile', $actions[$mediaRun->uuid]);
        self::assertSame(ArtifactStatus::Verified, $archive->refresh()->status, 'Plan only.');

        $this->runJson('quraba:backup:catalog:rebuild', ['--apply' => true]);
        self::assertSame(ArtifactStatus::Expired, $archive->refresh()->status);
        self::assertSame('running', BackupRun::query()->where('uuid', $mediaRun->uuid)->firstOrFail()->status->value, 'Stale rows belong to quraba:backup:reconcile.');

        // A replaced repository is never adopted from.
        BackupArtifact::query()->delete();
        BackupRun::query()->delete();
        file_put_contents(dirname($this->fakeRestic).'/repo-id', str_repeat('ef', 32));
        $foreign = $this->runJson('quraba:backup:catalog:rebuild', []);
        self::assertSame('other_repository', array_column($foreign['runs'], null, 'run_uuid')[$mediaRun->uuid]['components']['media_snapshot']);
        $refused = $this->runJson('quraba:backup:catalog:rebuild', ['--apply' => true], 1);
        self::assertSame('restic.repository_identity_mismatch', $refused['error']['code']);
        self::assertSame(0, BackupRun::query()->count(), 'Nothing is adopted while the repository identity is in doubt.');
        @unlink(dirname($this->fakeRestic).'/repo-id');

        // Without catalog tables only a plan is possible.
        $this->becomeCleanHost();
        $plan = $this->runJson('quraba:backup:catalog:rebuild', []);
        self::assertFalse($plan['catalog_available']);
        self::assertSame('restore.catalog_unavailable', $this->runJson('quraba:backup:catalog:rebuild', ['--apply' => true], 1)['error']['code']);
    }
}
