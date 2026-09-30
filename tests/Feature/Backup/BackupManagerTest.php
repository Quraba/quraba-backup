<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Feature\Backup;

use Illuminate\Support\Facades\Artisan;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Quraba\Backup\Backup\BackupRunResult;
use Quraba\Backup\Contracts\DatabaseDumper;
use Quraba\Backup\Contracts\QuiescenceProvider;
use Quraba\Backup\Coordination\LockName;
use Quraba\Backup\Enums\ArtifactKind;
use Quraba\Backup\Enums\ArtifactStatus;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Enums\BackupStatus;
use Quraba\Backup\Enums\ConsistencyLevel;
use Quraba\Backup\Exceptions\OperationBusy;
use Quraba\Backup\Identity\IdentityResolver;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Security\SecretRedactor;
use Quraba\Backup\Storage\FlysystemObjectStorage;
use Quraba\Backup\Storage\RemoteLayout;
use Quraba\Backup\Storage\RemoteStorage;
use Quraba\Backup\Tests\Support\BuildsBackups;
use Quraba\Backup\Tests\Support\ChildProcess;
use Quraba\Backup\Tests\Support\FlakyObjectStorage;
use Quraba\Backup\Tests\Support\LockingTestDumper;
use Quraba\Backup\Tests\Support\RecordingQuiescenceProvider;
use Quraba\Backup\Tests\Support\Sentinels;
use Quraba\Backup\Tests\Support\UsesFakeRestic;
use Quraba\Backup\Tests\TestCase;

final class BackupManagerTest extends TestCase
{
    use BuildsBackups;
    use UsesFakeRestic;

    protected FlakyObjectStorage $objects;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useFakeRestic(['repository' => 'ready']);
        $this->prepareBackupPipeline();

        $redactor = $this->app->make(SecretRedactor::class);
        $filesystem = new Filesystem(new LocalFilesystemAdapter($this->sandbox.'/b2'));
        $this->app->instance(RemoteStorage::class, new RemoteStorage(
            $this->config(),
            $this->app->make(IdentityResolver::class),
            function (RemoteLayout $layout) use ($filesystem, $redactor): FlakyObjectStorage {
                return $this->objects = new FlakyObjectStorage(new FlysystemObjectStorage($filesystem, [$layout->resticRoot()], 'local', $redactor));
            },
        ));
        $this->refreshBackupServices();
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(BackupRun $run): array
    {
        $locator = $run->artifacts()->where('kind', ArtifactKind::RemoteManifest->value)->value('locator');
        self::assertIsString($locator);

        return json_decode((string) file_get_contents($this->bucketPath($locator)), true);
    }

    public function test_database_profile_creates_a_verified_archive_and_manifest(): void
    {
        $result = $this->manager()->run(BackupProfile::Database);
        $run = $result->run;

        self::assertSame(BackupStatus::Completed, $run->status);
        self::assertSame(ConsistencyLevel::None, $run->consistency);
        self::assertSame(BackupRunResult::EXIT_COMPLETED, $result->exitCode());

        $archive = $run->artifacts()->where('kind', ArtifactKind::ApplicationArchive->value)->firstOrFail();
        self::assertSame(ArtifactStatus::Verified, $archive->status);
        self::assertSame(hash_file('sha256', $this->bucketPath((string) $archive->locator)), $archive->sha256);
        self::assertSame(filesize($this->bucketPath((string) $archive->locator)), $archive->byte_size);
        self::assertNull($run->artifacts()->where('kind', ArtifactKind::ResticSnapshot->value)->first(), 'No media snapshot for the database profile.');
        self::assertSame([], array_filter($this->invokedCommands(), static fn (string $c): bool => str_starts_with($c, 'backup')));

        $manifest = $this->manifest($run);
        self::assertSame($run->uuid, $manifest['run_uuid']);
        self::assertSame('completed', $manifest['status']);
        self::assertFalse($manifest['recovery_point']);
        self::assertSame($archive->sha256, $manifest['archive']['sha256']);
        self::assertSame('not_requested', $manifest['components']['media_snapshot']);
        Sentinels::assertAbsent((string) json_encode($manifest), 'manifest');
    }

    public function test_media_profile_creates_a_verified_snapshot_and_manifest(): void
    {
        $result = $this->manager()->run(BackupProfile::Media);
        $run = $result->run;

        self::assertSame(BackupStatus::Completed, $run->status);
        self::assertSame(0, $this->dumper->dumps, 'No archive for the media profile.');

        $snapshot = $run->artifacts()->where('kind', ArtifactKind::ResticSnapshot->value)->firstOrFail();
        $manifest = $this->manifest($run);

        self::assertSame($snapshot->snapshot_id, $manifest['restic']['snapshot']['id']);
        self::assertSame('media', $manifest['restic']['snapshot']['kind']);
        self::assertSame(str_repeat('ab', 32), $manifest['restic']['repository_id']);
        self::assertNull($manifest['archive']);
    }

    public function test_recovery_is_the_default_and_one_run_holds_both_components(): void
    {
        $exit = Artisan::call('quraba:backup:run', ['--json' => true]);
        $output = json_decode(Artisan::output(), true);

        self::assertSame(0, $exit);
        self::assertSame('recovery', $output['profile']);
        self::assertSame('completed', $output['status']);
        self::assertSame('best_effort', $output['consistency'], 'Without proven quiescence a Recovery Point is best_effort.');

        $run = BackupRun::query()->where('uuid', $output['run_uuid'])->firstOrFail();
        $manifest = $this->manifest($run);

        self::assertTrue($manifest['recovery_point']);
        self::assertSame('best_effort', $manifest['consistency']);
        self::assertSame('recovery_media', $manifest['restic']['snapshot']['kind']);
        self::assertSame(3, $run->artifacts()->where('status', ArtifactStatus::Verified->value)->count());
        self::assertSame(1, BackupRun::query()->count(), 'A Recovery Point is ONE run.');
    }

    public function test_media_failure_makes_a_recovery_run_partial_and_not_a_recovery_point(): void
    {
        $this->writeScenario(['repository' => 'ready', 'commands' => ['backup' => ['exit' => 1, 'stderr' => "Fatal: unable to save snapshot\n"]]]);

        $result = $this->manager()->run(BackupProfile::Recovery);

        self::assertSame(BackupStatus::Partial, $result->run->status);
        self::assertSame(BackupRunResult::EXIT_PARTIAL, $result->exitCode());
        self::assertSame('verified', $result->components['application_archive']->state);
        self::assertSame('failed', $result->components['restic_snapshot']->state);

        $manifest = $this->manifest($result->run);
        self::assertSame('partial', $manifest['status']);
        self::assertFalse($manifest['recovery_point']);
        self::assertSame('failed', $manifest['components']['media_snapshot']);
        self::assertNotNull($manifest['archive'], 'The valid database component stays visible.');
    }

    public function test_failure_without_any_usable_component_fails_without_a_manifest(): void
    {
        $this->dumper->fail = true;

        $result = $this->manager()->run(BackupProfile::Database);

        self::assertSame(BackupStatus::Failed, $result->run->status);
        self::assertSame('archive.database_dump_failed', $result->run->failure_code);
        self::assertSame(BackupRunResult::EXIT_FAILED, $result->exitCode());
        self::assertNull($result->manifestLocator);
        self::assertDirectoryDoesNotExist($this->sandbox.'/b2/quraba-backup/'.self::APP_ID.'/manifests');
    }

    public function test_preflight_refuses_a_missing_archive_password_before_any_work(): void
    {
        $this->config()->set('quraba-backup.archive.password', '');

        $result = $this->manager()->run(BackupProfile::Recovery);

        self::assertSame(BackupStatus::Failed, $result->run->status);
        self::assertSame('archive.password_missing', $result->run->failure_code);
        self::assertSame('preflight', $result->run->failure_stage);
        self::assertSame(0, $this->dumper->dumps);
        self::assertSame(0, $result->run->artifacts()->count());
    }

    public function test_workspaces_are_cleaned_up(): void
    {
        $this->manager()->run(BackupProfile::Recovery);

        $leftovers = glob($this->sandbox.'/private/work/op-*') ?: [];
        self::assertSame([], $leftovers);
    }

    public function test_a_concurrent_operation_is_refused_without_creating_a_run(): void
    {
        [$child] = ChildProcess::start($this->sandbox.'/private', LockName::GlobalOperation->value);

        try {
            try {
                $this->manager()->run(BackupProfile::Database);
                self::fail('A second write operation must be refused.');
            } catch (OperationBusy) {
                self::addToAssertionCount(1);
            }

            self::assertSame(1, Artisan::call('quraba:backup:run', ['--profile' => 'database']));
        } finally {
            ChildProcess::kill($child);
        }

        self::assertSame(0, BackupRun::query()->count());
    }

    public function test_quiesced_is_recorded_only_when_the_provider_proves_it(): void
    {
        $provider = new RecordingQuiescenceProvider(ConsistencyLevel::Quiesced);
        $this->app->instance(QuiescenceProvider::class, $provider);
        $this->refreshBackupServices();
        $this->app->instance(QuiescenceProvider::class, $provider);

        $result = $this->manager()->run(BackupProfile::Recovery);

        self::assertSame(ConsistencyLevel::Quiesced, $result->run->consistency);
        self::assertSame(1, $provider->entered);
        self::assertSame(1, $provider->released);
        self::assertSame('quiesced', $this->manifest($result->run)['consistency']);
    }

    public function test_laravel_maintenance_mode_alone_is_best_effort_and_is_always_released(): void
    {
        $this->config()->set('quraba-backup.consistency.provider', 'laravel_maintenance');
        $this->refreshBackupServices();

        $result = $this->manager()->run(BackupProfile::Recovery);

        self::assertSame(ConsistencyLevel::BestEffort, $result->run->consistency, 'artisan down does not stop queue workers or CLI writers.');
        self::assertFalse($this->app->maintenanceMode()->active(), 'The application must not be left in maintenance mode.');

        // With the explicit declaration that no background writers exist, it is quiesced.
        $this->config()->set('quraba-backup.consistency.no_background_writers', true);
        $this->refreshBackupServices();

        $strict = $this->manager()->run(BackupProfile::Recovery);

        self::assertSame(ConsistencyLevel::Quiesced, $strict->run->consistency);
        self::assertFalse($this->app->maintenanceMode()->active());
    }

    public function test_maintenance_mode_is_released_even_when_a_component_fails(): void
    {
        $this->config()->set('quraba-backup.consistency.provider', 'laravel_maintenance');
        $this->refreshBackupServices();
        $this->dumper->fail = true;

        $this->manager()->run(BackupProfile::Recovery);

        self::assertFalse($this->app->maintenanceMode()->active());
    }

    public function test_required_quiescence_that_cannot_be_proven_is_refused_or_explicitly_downgraded(): void
    {
        $this->config()->set('quraba-backup.consistency.require_quiesced', true);
        $this->refreshBackupServices();

        $refused = $this->manager()->run(BackupProfile::Recovery);
        self::assertSame(BackupStatus::Failed, $refused->run->status);
        self::assertSame('quiescence.failed', $refused->run->failure_code);
        self::assertSame(0, $this->dumper->dumps);

        $this->config()->set('quraba-backup.consistency.provider', 'laravel_maintenance');
        $this->config()->set('quraba-backup.consistency.allow_downgrade', true);
        $this->refreshBackupServices();

        $downgraded = $this->manager()->run(BackupProfile::Recovery);
        self::assertSame(BackupStatus::Completed, $downgraded->run->status);
        self::assertSame(ConsistencyLevel::BestEffort, $downgraded->run->consistency);
        self::assertStringContainsString('downgraded', (string) ($downgraded->run->metadata['consistency_explanation'] ?? ''));
    }

    public function test_quiescence_release_failure_is_reported_loudly(): void
    {
        $provider = new RecordingQuiescenceProvider(ConsistencyLevel::Quiesced, failRelease: true);
        $this->app->instance(QuiescenceProvider::class, $provider);
        $this->refreshBackupServices();
        $this->app->instance(QuiescenceProvider::class, $provider);

        $result = $this->manager()->run(BackupProfile::Recovery);

        self::assertSame(BackupStatus::Completed, $result->run->status, 'Verified artifacts stay valid.');
        self::assertTrue($result->quiescenceReleaseFailed);
        self::assertSame(BackupRunResult::EXIT_FAILED, $result->exitCode());
        self::assertNotSame([], $result->warnings);
        self::assertNotSame([], array_filter($this->logs->records, static fn (array $r): bool => $r['level'] === 'critical'));
    }

    public function test_manifest_write_failure_leaves_the_run_indeterminate_and_reconciliation_completes_it(): void
    {
        $this->manager();
        $this->app->make(RemoteStorage::class)->objects();
        $this->objects->failWritesUnder = ['/manifests/'];

        $result = $this->manager()->run(BackupProfile::Recovery);

        self::assertSame(BackupStatus::Indeterminate, $result->run->status);
        self::assertSame('manifest.upload_failed', $result->run->failure_code);
        self::assertSame(BackupRunResult::EXIT_INDETERMINATE, $result->exitCode());

        $this->objects->failWritesUnder = [];
        $report = $this->reconciler()->reconcile();

        self::assertSame(0, $report->unresolved());
        $run = $result->run->refresh();
        self::assertSame(BackupStatus::Completed, $run->status);
        self::assertTrue($this->manifest($run)['recovery_point']);
        self::assertSame(1, count(array_filter($this->invokedCommands(), static fn (string $c): bool => str_starts_with($c, 'backup'))), 'Reconciliation never creates a second snapshot.');
    }

    public function test_workspace_cleanup_failure_is_a_warning_not_a_failed_backup(): void
    {
        $locking = new LockingTestDumper($this->dumper);
        $this->app->instance(DatabaseDumper::class, $locking);
        $this->refreshBackupServices();

        try {
            $result = $this->manager()->run(BackupProfile::Database);

            self::assertSame(BackupStatus::Completed, $result->run->status);
            self::assertSame(BackupRunResult::EXIT_COMPLETED, $result->exitCode());
            self::assertNotSame([], array_filter($result->warnings, static fn (string $w): bool => str_contains($w, 'Workspace cleanup failed')));
            self::assertSame($result->warnings, $result->run->metadata['warnings'] ?? null);
        } finally {
            $locking->unlock();
        }
    }

    public function test_run_command_reports_partial_with_exit_code_2(): void
    {
        $this->writeScenario(['repository' => 'ready', 'commands' => ['backup' => ['exit' => 1, 'stderr' => "Fatal: no\n"]]]);

        $this->artisan('quraba:backup:run')
            ->expectsOutputToContain('PARTIAL')
            ->assertExitCode(2);

        $this->artisan('quraba:backup:run', ['--profile' => 'nonsense'])->assertExitCode(1);
    }

    public function test_list_command_shows_the_local_catalog(): void
    {
        $this->manager()->run(BackupProfile::Database);
        $this->manager()->run(BackupProfile::Media);

        $exit = Artisan::call('quraba:backup:list', ['--json' => true]);
        $rows = json_decode(Artisan::output(), true)['runs'];

        self::assertSame(0, $exit);
        self::assertCount(2, $rows);
        self::assertSame('media', $rows[0]['profile']);
        self::assertSame('verified', $rows[0]['media_status']);
        self::assertSame('-', $rows[0]['archive_status']);
        self::assertSame('verified', $rows[1]['archive_status']);
        self::assertSame('completed', $rows[1]['status']);

        $this->artisan('quraba:backup:list')->expectsOutputToContain('Run UUID')->assertSuccessful();
    }
}
