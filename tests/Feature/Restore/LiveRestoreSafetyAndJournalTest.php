<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Feature\Restore;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Quraba\Backup\Archive\ArchiveStore;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Enums\BackupStatus;
use Quraba\Backup\Enums\BackupTrigger;
use Quraba\Backup\Enums\RestoreProfile;
use Quraba\Backup\Exceptions\RestoreFailed;
use Quraba\Backup\Identity\IdentityResolver;
use Quraba\Backup\Manifest\ManifestStore;
use Quraba\Backup\Manifest\RemoteManifestCatalog;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Restore\Journal\JournalPhase;
use Quraba\Backup\Restore\Journal\RestoreJournal;
use Quraba\Backup\Restore\Journal\RestoreJournalStore;
use Quraba\Backup\Restore\RestoreSource;
use Quraba\Backup\Security\SecretRedactor;
use Quraba\Backup\Storage\FlysystemObjectStorage;
use Quraba\Backup\Storage\RemoteLayout;
use Quraba\Backup\Storage\RemoteStorage;
use Quraba\Backup\Support\PackagePaths;
use Quraba\Backup\Tests\Support\FlakyObjectStorage;
use Quraba\Backup\Tests\Support\RunsLiveRestores;
use Quraba\Backup\Tests\TestCase;
use Quraba\Backup\Workspace\WorkspaceManager;

/**
 * The two things that must hold BEFORE the destructive boundary: a
 * positively verified safety backup and a durable journal record.
 */
final class LiveRestoreSafetyAndJournalTest extends TestCase
{
    use RunsLiveRestores;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareLiveRestore();
    }

    private function assertNothingChanged(array $report): void
    {
        self::assertSame('failed', $report['status'], (string) json_encode($report));
        self::assertFalse($report['destructive_boundary_crossed']);
        self::assertSame(0, $this->replacement->clears);
        self::assertSame(0, $this->replacement->imports);
        $this->assertDatabaseIsStateB();
        $this->assertMediaIsStateB();
        self::assertSame([], $this->parkedDirectories());
        self::assertFalse($this->quiescenceProvider->active, 'Nothing changed, so quiescence entered by the package is given back.');
    }

    private function flakyStorage(): FlakyObjectStorage
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
        foreach ([ArchiveStore::class, ManifestStore::class, RemoteManifestCatalog::class] as $service) {
            $this->app->forgetInstance($service);
        }
        $this->refreshLiveServices();
        $this->app->make(RemoteStorage::class)->objects();
        self::assertInstanceOf(FlakyObjectStorage::class, $flaky);

        return $flaky;
    }

    public function test_archive_safety_succeeds_but_media_safety_fails_so_nothing_is_destroyed(): void
    {
        $source = $this->recoveryPointOfStateA();
        // Restic stops working for the safety snapshot only.
        $this->faults->at('quiesced', function (): void {
            $this->writeScenario(['repository' => 'ready', 'commands' => ['backup' => ['exit' => 1, 'stderr' => 'Fatal: injected backup failure']]]);
        });

        [$exit, $report] = $this->liveRestore($source->uuid, 'full');
        self::assertSame(1, $exit);
        self::assertSame('restore.safety_backup_failed', $report['error']['code']);
        $this->assertNothingChanged($report);

        // The half-made safety run is linked in the journal; it is not "verified".
        $journal = $this->journal($report['restore_uuid']);
        self::assertSame(JournalPhase::SafetyBackupStarting, $journal->phase());
        self::assertFalse($journal->safetyVerified());
        $safety = BackupRun::query()->where('uuid', $journal->safetyRunUuid())->firstOrFail();
        self::assertSame(BackupStatus::Partial, $safety->status, 'The archive half succeeded, the media half did not.');
        self::assertSame(BackupTrigger::PreRestore, $safety->trigger);
        self::assertSame($report['restore_uuid'], $safety->metadata['restore_uuid']);
    }

    public function test_a_safety_backup_whose_manifest_cannot_be_written_is_not_a_safety_backup(): void
    {
        $source = $this->recoveryPointOfStateA();
        $flaky = $this->flakyStorage();
        $this->faults->at('quiesced', static function () use ($flaky): void {
            $flaky->failWritesUnder = ['/manifests/'];
        });

        [$exit, $report] = $this->liveRestore($source->uuid, 'database');
        self::assertSame(1, $exit);
        self::assertSame('restore.safety_backup_failed', $report['error']['code']);
        $this->assertNothingChanged($report);
        self::assertSame(BackupStatus::Indeterminate, BackupRun::query()->where('trigger', BackupTrigger::PreRestore->value)->firstOrFail()->status);
    }

    public function test_safety_catalog_write_failure_after_physical_success_stops_the_restore_and_is_adopted_by_reconciliation(): void
    {
        $source = $this->recoveryPointOfStateA();
        $this->faults->at('quiesced', static function (): void {
            DB::statement("CREATE TRIGGER block_safety_completion BEFORE UPDATE OF status ON quraba_backup_runs WHEN NEW.status = 'completed' AND OLD.\"trigger\" = 'pre_restore' BEGIN SELECT RAISE(FAIL, 'injected catalog write failure'); END");
        });

        [$exit, $report] = $this->liveRestore($source->uuid, 'database');
        self::assertSame(1, $exit);
        self::assertSame('restore.safety_backup_failed', $report['error']['code']);
        $this->assertNothingChanged($report);

        $journal = $this->journal($report['restore_uuid']);
        $safety = BackupRun::query()->where('uuid', $journal->safetyRunUuid())->firstOrFail();
        self::assertSame(BackupStatus::Indeterminate, $safety->status, 'Physically complete, but the catalog could not say so.');
        self::assertTrue($safety->isPinned());

        // Backup reconciliation adopts the physically complete safety run.
        DB::statement('DROP TRIGGER block_safety_completion');
        self::assertSame(0, Artisan::call('quraba:backup:reconcile', ['--json' => true]), Artisan::output());
        self::assertSame(BackupStatus::Completed, $safety->refresh()->status);

        // It stays protected until the failed restore is explicitly acknowledged …
        $this->keepOneOfEachFamilyAndDisableSafetyWindow();
        $decision = $this->retentionDecision($safety->uuid);
        self::assertSame('keep', $decision['decision']);
        self::assertContains('protected:restore_safety_backup_until_resolved', $decision['reasons']);

        // … and then only for the configured safety window.
        [$reconcileExit, $reconciled] = $this->reconcileRestore($report['restore_uuid']);
        self::assertSame(0, $reconcileExit);
        self::assertSame('failed', $reconciled['outcome']);
        self::assertStringStartsWith('protected_until_', $reconciled['safety_backup_pin']);
        self::assertContains('protected:restore_safety_backup_window', $this->retentionDecision($safety->uuid)['reasons']);

        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addDays(31));
        Carbon::setTestNow(CarbonImmutable::now('UTC'));
        try {
            self::assertSame('expire', $this->retentionDecision($safety->uuid)['decision'], 'After the window the settled restore no longer needs its safety backup.');
        } finally {
            CarbonImmutable::setTestNow();
            Carbon::setTestNow();
        }
    }

    private function keepOneOfEachFamilyAndDisableSafetyWindow(): void
    {
        foreach (['database', 'media', 'recovery'] as $family) {
            $this->config()->set('quraba-backup.retention.'.$family, ['keep_latest' => 1]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function retentionDecision(string $runUuid): array
    {
        $exit = Artisan::call('quraba:backup:retention', ['--json' => true]);
        $output = Artisan::output();
        self::assertSame(0, $exit, $output);
        $decoded = json_decode($output, true);
        self::assertIsArray($decoded['plan'] ?? null, $output);
        $decisions = array_column($decoded['plan']['decisions'], null, 'run_uuid');
        self::assertArrayHasKey($runUuid, $decisions);

        return $decisions[$runUuid];
    }

    public function test_a_journal_that_cannot_record_the_boundary_stops_the_restore_before_the_mutation(): void
    {
        $source = $this->recoveryPointOfStateA();
        // The journal fails exactly when asked to record the destructive boundary.
        $this->journalFault = static fn (string $contents): bool => str_contains($contents, '"phase": "db_clear_starting"');

        [$exit, $report] = $this->liveRestore($source->uuid, 'database');
        self::assertSame(1, $exit);
        self::assertSame('restore.journal_failed', $report['error']['code']);
        $this->assertNothingChanged($report);
        $journal = $this->journal($report['restore_uuid']);
        self::assertSame(JournalPhase::Applying, $journal->phase(), 'The last durable record is the one before the boundary.');
        self::assertSame(RestoreJournal::TERMINAL_FAILED, $journal->terminalState());
        self::assertNull($journal->destructiveStartedAt());
    }

    public function test_a_journal_that_cannot_record_a_media_boundary_prevents_the_rename(): void
    {
        $source = $this->recoveryPointOfStateA();
        $this->journalFault = static fn (string $contents): bool => str_contains($contents, 'root:uploads:swap_starting');

        [$exit, $report] = $this->liveRestore($source->uuid, 'media');
        self::assertSame(1, $exit);
        self::assertSame('restore.journal_failed', $report['error']['code']);
        $this->assertNothingChanged($report);
    }

    public function test_a_journal_that_cannot_be_created_refuses_the_restore_before_any_work(): void
    {
        $source = $this->recoveryPointOfStateA();
        $entered = $this->quiescenceProvider->entered;
        $this->journalDead = true;

        [$exit, $report] = $this->liveRestore($source->uuid, 'full');
        self::assertSame(1, $exit);
        self::assertSame('restore.journal_failed', $report['error']['code']);
        $this->assertNothingChanged($report);
        self::assertSame($entered, $this->quiescenceProvider->entered, 'No quiescence, no safety backup, no download without a journal.');
        self::assertSame(0, BackupRun::query()->where('trigger', BackupTrigger::PreRestore->value)->count());
        self::assertSame([], (new RestoreJournalStore($this->app->make(PackagePaths::class)))->all()['journals']);
    }

    public function test_a_journal_failure_after_a_mutation_leaves_the_restore_unresolved_never_failed(): void
    {
        $source = $this->recoveryPointOfStateA();
        // The clear happened; recording "cleared" fails, and so does everything after.
        $this->journalFault = static fn (string $contents): bool => str_contains($contents, '"db_cleared"');

        [$exit, $report] = $this->liveRestore($source->uuid, 'database');
        self::assertSame(3, $exit);
        self::assertSame('indeterminate', $report['status']);
        self::assertTrue($report['destructive_boundary_crossed']);
        self::assertSame(1, $this->replacement->clears);
        self::assertSame(0, $this->replacement->imports, 'It stopped: the import never started without its record.');
        $journal = $this->journal($report['restore_uuid']);
        self::assertSame('clear_starting', $journal->databaseState());
        self::assertTrue($journal->isUnresolved());
        self::assertTrue($this->quiescenceProvider->active);
        $retained = $this->app->make(WorkspaceManager::class);
        self::assertCount(1, $retained->retainedRestores());
        $kept = $report['workspace_kept'];
        self::assertIsString($kept);
        self::assertDirectoryExists($kept);
        self::assertFalse($retained->cleanupRestore($report['restore_uuid'], $this->app->make(RestoreJournalStore::class))->succeeded());
        self::assertDirectoryExists($kept);

        $store = $this->app->make(RestoreJournalStore::class);
        $store->save($journal->withResolution(RestoreJournal::RESOLUTION_ABANDONED, ['reason' => 'test operator decision']));
        self::assertTrue($retained->cleanupRestore($report['restore_uuid'], $store)->succeeded());
        self::assertDirectoryDoesNotExist($kept);
    }

    public function test_journal_store_is_forward_only_private_and_refuses_tampering(): void
    {
        $paths = $this->app->make(PackagePaths::class);
        $store = new RestoreJournalStore($paths);
        $identity = $this->app->make(IdentityResolver::class)->current();
        $uuid = '0198c0de-0000-7000-8000-000000000001';
        $source = new RestoreSource('0198c0de-0000-7000-8000-000000000002', $identity->appId, $identity->environment, 'recovery', 'completed', 'quiesced',
            'quraba-backup/'.$identity->appId.'/archives/2026/10/01/0198c0de-0000-7000-8000-000000000002/application.zip', str_repeat('1', 64), 100, str_repeat('ab', 32), str_repeat('cd', 32), 'recovery_media', [['name' => 'uploads', 'path' => '/srv/media']], 1, 'local+remote');

        $journal = $store->create(RestoreJournal::open($uuid, $identity, RestoreProfile::Full, $source, false));
        $path = $paths->journal.'/'.$uuid.'.json';
        self::assertFileExists($path);
        self::assertSame([], glob($paths->journal.'/*.tmp-*') ?: [], 'No temporary file is left behind.');
        if (PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(0600, fileperms($path) & 0777);
            self::assertSame(0700, fileperms($paths->journal) & 0777);
        }
        self::assertStringNotContainsString('password', strtolower((string) file_get_contents($path)));

        // A second journal for the same restore is refused.
        try {
            $store->create(RestoreJournal::open($uuid, $identity, RestoreProfile::Full, $source, false));
            self::fail('A journal was overwritten.');
        } catch (RestoreFailed $exception) {
            self::assertSame('restore.journal_failed', $exception->failureCode());
        }

        $validated = $store->save($journal->withPhase(JournalPhase::Validated));
        self::assertSame(2, $validated->sequence());

        // Stale writer: the version on disk moved on.
        $this->assertJournalRefused(fn () => $store->save($journal->withPhase(JournalPhase::Quiesced)), 'sequence');
        // Backwards.
        $applying = $store->save($validated->withPhase(JournalPhase::Applying));
        $this->assertJournalRefused(fn () => $store->save($applying->withPhase(JournalPhase::Validated)), 'backwards');
        // Frozen identities.
        $tampered = RestoreJournal::fromArray([...$applying->withPhase(JournalPhase::Applying)->toArray(), 'source' => [...$applying->source(), 'snapshot_id' => str_repeat('ef', 32)]]);
        $this->assertJournalRefused(fn () => $store->save($tampered), 'frozen');
        // Short or "latest" identities are not a journal at all.
        foreach (['abcdef12', 'latest'] as $selector) {
            try {
                RestoreJournal::fromArray([...$applying->toArray(), 'source' => [...$applying->source(), 'snapshot_id' => $selector]]);
                self::fail('A selector was accepted as a snapshot identity.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('full identities only', $exception->getMessage());
            }
        }

        // No database is part of a journal that never planned one.
        try {
            $applying->withDatabaseState('clear_starting');
            self::fail('A database step without a database plan.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        // After the boundary a restore can never be recorded or resolved as failed.
        $planned = $store->save($applying->withDatabasePlan(['connection' => 'mysql', 'name' => 'app', 'server' => 'db:3306']));
        $crossed = $store->save($planned->withDatabaseState('clear_starting'));
        self::assertNotNull($crossed->destructiveStartedAt());
        foreach ([fn () => $crossed->withTerminal(RestoreJournal::TERMINAL_FAILED), fn () => $crossed->withResolution(RestoreJournal::TERMINAL_FAILED, ['x' => 1]), fn () => $crossed->withTerminal(RestoreJournal::TERMINAL_COMPLETED)] as $illegal) {
            try {
                $illegal();
                self::fail('An unprovable outcome was accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        // A terminal journal accepts only a resolution.
        $terminal = $store->save($crossed->withTerminal(RestoreJournal::TERMINAL_INDETERMINATE));
        self::assertTrue($terminal->isUnresolved());
        $this->assertJournalRefused(fn () => $store->save($terminal->withDatabaseState('cleared')), 'terminal');
        $resolved = $store->save($terminal->withResolution(RestoreJournal::RESOLUTION_ABANDONED, ['operator' => 'decision']));
        self::assertFalse($resolved->isUnresolved());
        $this->assertJournalRefused(fn () => $store->save($resolved->withResolution(RestoreJournal::TERMINAL_COMPLETED, ['x' => 1])), 'replaced');

        // A journal that does not match its file name is reported, not trusted.
        copy($path, $paths->journal.'/0198c0de-0000-7000-8000-00000000000f.json');
        self::assertSame(['0198c0de-0000-7000-8000-00000000000f.json'], $store->all()['unreadable']);
        self::assertCount(1, $store->all()['journals']);
    }

    public function test_journal_paths_that_are_symbolic_links_are_refused(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Symbolic link replacement of the journal runs in Linux CI.');
        }

        $paths = $this->app->make(PackagePaths::class);
        $store = new RestoreJournalStore($paths);
        $identity = $this->app->make(IdentityResolver::class)->current();
        $uuid = '0198c0de-0000-7000-8000-000000000011';
        $source = new RestoreSource('0198c0de-0000-7000-8000-000000000012', $identity->appId, $identity->environment, 'database', 'completed', 'none',
            'quraba-backup/'.$identity->appId.'/archives/2026/10/01/0198c0de-0000-7000-8000-000000000012/application.zip', str_repeat('1', 64), 100, null, null, null, [], 1, 'local+remote');
        $journal = $store->create(RestoreJournal::open($uuid, $identity, RestoreProfile::Database, $source, false));
        $path = $paths->journal.'/'.$uuid.'.json';

        // The journal file is swapped for a link to a file elsewhere.
        $elsewhere = $this->sandbox.'/elsewhere.json';
        copy($path, $elsewhere);
        unlink($path);
        symlink($elsewhere, $path);

        foreach ([fn () => $store->find($uuid), fn () => $store->save($journal->withPhase(JournalPhase::Validated))] as $operation) {
            try {
                $operation();
                self::fail('A journal was read or written through a symbolic link.');
            } catch (RestoreFailed $exception) {
                self::assertStringContainsString('symbolic link', $exception->getMessage());
            }
        }

        self::assertSame(1, json_decode((string) file_get_contents($elsewhere), true)['sequence'], 'The link target was not written.');

        // The journal directory itself must not be a link either.
        unlink($path);
        rename($paths->journal, $paths->journal.'-real');
        symlink($paths->journal.'-real', $paths->journal);
        $this->expectException(RestoreFailed::class);
        $store->create(RestoreJournal::open('0198c0de-0000-7000-8000-000000000013', $identity, RestoreProfile::Database, $source, false));
    }

    private function assertJournalRefused(callable $operation, string $expectedFragment): void
    {
        try {
            $operation();
            self::fail('A journal update that is not a forward step was accepted ('.$expectedFragment.').');
        } catch (RestoreFailed $exception) {
            self::assertSame('restore.journal_failed', $exception->failureCode());
            self::assertStringContainsString($expectedFragment, $exception->getMessage());
        }
    }

    public function test_unrelated_backup_profiles_are_not_a_substitute_for_the_required_safety_backup(): void
    {
        $source = $this->recoveryPointOfStateA();
        // A fresh ordinary backup exists; the restore still takes its own.
        $this->manager()->run(BackupProfile::Database);
        [$exit, $report] = $this->liveRestore($source->uuid, 'database');
        self::assertSame(0, $exit, (string) json_encode($report));
        $safety = $this->journal($report['restore_uuid'])->safetyRunUuid();
        self::assertNotNull($safety);
        self::assertSame($safety, $report['safety_backup']['run_uuid']);
        self::assertSame('verified', $report['safety_backup']['status']);
    }
}
