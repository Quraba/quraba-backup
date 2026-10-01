<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Feature\Restore;

use Illuminate\Support\Facades\Artisan;
use Quraba\Backup\Consistency\QuiescenceSession;
use Quraba\Backup\Contracts\LockManager;
use Quraba\Backup\Contracts\QuiescenceProvider;
use Quraba\Backup\Coordination\LockName;
use Quraba\Backup\Enums\ArtifactKind;
use Quraba\Backup\Enums\ArtifactStatus;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Enums\BackupTrigger;
use Quraba\Backup\Enums\ConsistencyLevel;
use Quraba\Backup\Enums\RestoreMode;
use Quraba\Backup\Enums\RestoreStatus;
use Quraba\Backup\Exceptions\OperationBusy;
use Quraba\Backup\Health\BackupHealthService;
use Quraba\Backup\Identity\IdentityResolver;
use Quraba\Backup\Manifest\RemoteManifestCatalog;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Models\RestoreRun;
use Quraba\Backup\Restore\Journal\JournalPhase;
use Quraba\Backup\Restore\Journal\RestoreJournal;
use Quraba\Backup\Restore\Journal\RestoreJournalStore;
use Quraba\Backup\Restore\Live\LiveRestoreService;
use Quraba\Backup\Support\PackagePaths;
use Quraba\Backup\Tests\Support\RecordingQuiescenceProvider;
use Quraba\Backup\Tests\Support\RunsLiveRestores;
use Quraba\Backup\Tests\Support\Sentinels;
use Quraba\Backup\Tests\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use ZipArchive;

/**
 * The live restore: its gate, its three profiles, and the state it leaves
 * behind (journal, audit, safety backup, parked media, maintenance mode).
 */
final class LiveRestoreTest extends TestCase
{
    use RunsLiveRestores;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareLiveRestore();
    }

    protected function tearDown(): void
    {
        if ($this->app->maintenanceMode()->active()) {
            Artisan::call('up');
        }

        parent::tearDown();
    }

    private function safetyArchiveSql(string $safetyRunUuid): string
    {
        $manifest = $this->app->make(RemoteManifestCatalog::class)->find($this->app->make(IdentityResolver::class)->current(), $safetyRunUuid);
        self::assertNotNull($manifest, 'The safety backup has an immutable remote manifest.');
        $zip = new ZipArchive;
        self::assertTrue($zip->open($this->bucketPath((string) $manifest->archiveLocator)));
        $zip->setPassword(Sentinels::ARCHIVE_PASSWORD);
        $sql = (string) $zip->getFromName('database/database.sql');
        $zip->close();

        return $sql;
    }

    public function test_dry_run_is_the_default_and_force_alone_or_a_wrong_phrase_changes_nothing(): void
    {
        $source = $this->recoveryPointOfStateA();

        // No flags: a dry run.
        self::assertSame(0, Artisan::call('quraba:backup:restore', ['--run' => $source->uuid, '--profile' => 'full', '--json' => true]));
        self::assertSame('dry_run', json_decode(Artisan::output(), true)['mode']);

        foreach ([
            ['--force' => true],
            ['--confirm' => 'RESTORE_APPLICATION'],
            ['--force' => true, '--confirm' => 'restore_application'],
            ['--force' => true, '--confirm' => 'RESTORE_APPLICATION '],
            ['--force' => true, '--confirm' => 'yes'],
            ['--clean-host' => true],
        ] as $options) {
            self::assertSame(1, Artisan::call('quraba:backup:restore', ['--run' => $source->uuid, '--profile' => 'full', '--json' => true, ...$options]), (string) json_encode($options));
            self::assertSame('restore.confirmation_required', json_decode(Artisan::output(), true)['error']['code']);
        }

        // The phrase comes from configuration and must then match THAT exactly.
        $this->config()->set('quraba-backup.restore.confirmation_phrase', 'REPLACE_PRODUCTION_NOW');
        self::assertSame(1, Artisan::call('quraba:backup:restore', ['--run' => $source->uuid, '--force' => true, '--confirm' => 'RESTORE_APPLICATION', '--json' => true]));

        $this->assertDatabaseIsStateB();
        $this->assertMediaIsStateB();
        self::assertSame([], $this->app->make(RestoreJournalStore::class)->all()['journals'], 'No journal: no live restore ever started.');
        self::assertSame(0, BackupRun::query()->where('trigger', BackupTrigger::PreRestore->value)->count());
        self::assertSame(1, $this->quiescenceProvider->entered, 'Only the Recovery Point itself ever entered quiescence.');
        self::assertSame(0, RestoreRun::query()->where('mode', RestoreMode::Restore->value)->count());
    }

    public function test_unconfirmed_live_request_shows_exactly_what_would_be_replaced(): void
    {
        $source = $this->recoveryPointOfStateA();
        $archive = $source->artifacts()->where('kind', 'application_archive')->firstOrFail();
        $snapshot = $source->artifacts()->where('kind', 'restic_snapshot')->firstOrFail();

        self::assertSame(1, Artisan::call('quraba:backup:restore', ['--run' => $source->uuid, '--profile' => 'full', '--force' => true]));
        $output = Artisan::output();

        foreach ([$source->uuid, 'full', 'quiesced', (string) $archive->sha256, (string) $snapshot->snapshot_id, str_repeat('ab', 32), 'artifact', 'main on sqlite-memory', 'uploads', 'safety backup', 'recovery', 'quiescence', 'maintenance mode', '--force --confirm=RESTORE_APPLICATION', 'Nothing was changed in the live application.'] as $expected) {
            self::assertStringContainsString($expected, $output, 'The pre-confirmation summary must show: '.$expected);
        }

        Sentinels::assertAbsent($output, 'restore summary');
        $this->assertDatabaseIsStateB();
        $this->assertMediaIsStateB();
    }

    public function test_full_live_restore_replaces_database_and_media_exactly_and_keeps_everything_needed_to_go_back(): void
    {
        // Real maintenance mode, declared free of background writers.
        $this->config()->set('quraba-backup.consistency.provider', 'laravel_maintenance');
        $this->config()->set('quraba-backup.consistency.no_background_writers', true);
        $this->app->forgetInstance(QuiescenceProvider::class);
        $this->refreshBackupServices();

        $source = $this->recoveryPointOfStateA();
        $archive = $source->artifacts()->where('kind', 'application_archive')->firstOrFail();
        $snapshot = $source->artifacts()->where('kind', 'restic_snapshot')->firstOrFail();

        [$exit, $report] = $this->liveRestore($source->uuid, 'full');

        self::assertSame(0, $exit, (string) json_encode($report));
        self::assertSame('completed', $report['status']);
        self::assertTrue($report['destructive_boundary_crossed']);
        self::assertSame(LiveRestoreService::COMPLETED_NOTICE, $report['notice']);
        self::assertSame('Restore completed. The application remains in maintenance mode for operator verification.', $report['notice']);

        // DB exactly A, media exactly A.
        $this->assertDatabaseIsStateA();
        $this->assertMediaIsStateA();

        // The application stays in maintenance mode; nothing brings it up.
        self::assertTrue($this->app->maintenanceMode()->active());

        // Journal: terminal, every stage recorded in order, frozen identities.
        $journal = $this->journal($report['restore_uuid']);
        self::assertSame(RestoreJournal::TERMINAL_COMPLETED, $journal->terminalState());
        self::assertFalse($journal->isUnresolved());
        self::assertSame(JournalPhase::Verified, $journal->phase());
        self::assertSame('verified', $journal->databaseState());
        self::assertSame('verified', $journal->rootState('uploads'));
        self::assertSame($source->uuid, $journal->sourceRunUuid());
        self::assertSame($archive->sha256, $journal->source()['archive_sha256']);
        self::assertSame($archive->locator, $journal->source()['archive_locator']);
        self::assertSame($snapshot->snapshot_id, $journal->source()['snapshot_id']);
        self::assertSame(str_repeat('ab', 32), $journal->source()['repository_id']);
        self::assertNotNull($journal->destructiveStartedAt());
        self::assertSame('synced', $journal->annotations()['audit_handback']);
        $events = array_column($journal->history(), 'event');
        $expectedOrder = ['created', 'db_planned', 'root:uploads:planned', 'root:uploads:staged', 'validated', 'quiesced', 'safety_backup_starting', 'safety_backup_verified', 'applying',
            'db_clear_starting', 'db_cleared', 'db_import_starting', 'db_import_completed', 'db_verified', 'audit_restored',
            'root:uploads:swap_starting', 'root:uploads:parked', 'root:uploads:activated', 'root:uploads:verified', 'media_applied', 'verifying', 'verified', 'terminal:completed'];
        self::assertSame($expectedOrder, $events, 'Database first, then media, each step journaled.');
        Sentinels::assertAbsent((string) json_encode($journal->toArray()), 'restore journal');

        // Safety backup: a verified Recovery Point of STATE B, linked and pinned.
        $safetyUuid = $journal->safetyRunUuid();
        self::assertNotNull($safetyUuid);
        self::assertTrue($journal->safetyVerified());
        self::assertSame('recovery', $journal->safetyBackup()['profile']);
        $safetySql = $this->safetyArchiveSql($safetyUuid);
        self::assertStringContainsString('note-b1', $safetySql, 'The safety backup contains state B rows.');
        self::assertStringContainsString('only-in-b', $safetySql, 'The safety backup contains the B-only table.');
        $safetyManifest = $this->app->make(RemoteManifestCatalog::class)->find($this->app->make(IdentityResolver::class)->current(), $safetyUuid);
        self::assertSame(BackupTrigger::PreRestore, $safetyManifest?->trigger);
        self::assertTrue($safetyManifest->recoveryPoint);
        $safetyData = glob(dirname($this->fakeRestic).'/data/'.$safetyManifest->snapshotId.'/*', GLOB_ONLYDIR) ?: [];
        self::assertNotSame([], $safetyData, 'The safety snapshot holds the state B media.');

        // Parked: the complete old (state B) tree, untouched, next to the live root.
        $parked = $this->parkedDirectories();
        self::assertCount(1, $parked);
        self::assertSame($parked, $report['parked_media']);
        self::assertSame('image-B-changed', file_get_contents($parked[0].'/uploads/a.jpg'));
        self::assertFileExists($parked[0].'/uploads/new-in-b.txt');

        // Catalog self-restore: the imported catalog is the OLD one, yet the
        // audit row of THIS restore was handed back from the journal.
        $audit = RestoreRun::query()->where('uuid', $report['restore_uuid'])->firstOrFail();
        self::assertSame(RestoreStatus::Completed, $audit->status);
        self::assertSame(RestoreMode::Restore, $audit->mode);
        self::assertSame($source->uuid, $audit->source_run_uuid);
        self::assertSame($safetyUuid, $audit->pre_change_run_uuid);
        self::assertSame($archive->sha256, $audit->source_archive_sha256);
        self::assertNotNull($audit->destructive_started_at);
        self::assertNotNull($audit->completed_at);
        // The restored catalog shows the source run frozen mid-run and knows
        // nothing of the safety backup — stale state that is not trusted.
        self::assertSame('running', BackupRun::query()->where('uuid', $source->uuid)->firstOrFail()->status->value);
        self::assertNull(BackupRun::query()->where('uuid', $safetyUuid)->first());

        // Coherent again after the documented follow-up steps.
        self::assertStringContainsString('quraba:backup:reconcile', implode("\n", $report['follow_up']));
        self::assertSame(0, Artisan::call('quraba:backup:reconcile', ['--json' => true]), Artisan::output());
        self::assertSame('completed', BackupRun::query()->where('uuid', $source->uuid)->firstOrFail()->status->value);
        self::assertSame(0, Artisan::call('quraba:backup:catalog:rebuild', ['--apply' => true, '--json' => true]), Artisan::output());
        $safety = BackupRun::query()->where('uuid', $safetyUuid)->with('artifacts')->firstOrFail();
        self::assertSame(BackupTrigger::PreRestore, $safety->trigger);
        self::assertSame('completed', $safety->status->value);
        self::assertSame(ArtifactStatus::Verified, $safety->artifacts->firstWhere('kind', ArtifactKind::ApplicationArchive)?->status);

        // Retention keeps the safety backup (journal evidence), and a second
        // restore of the same exact source is possible.
        Artisan::call('quraba:backup:retention', ['--json' => true]);
        $decisions = array_column(json_decode(Artisan::output(), true)['plan']['decisions'], null, 'run_uuid');
        self::assertSame('keep', $decisions[$safetyUuid]['decision']);
        self::assertContains('protected:restore_safety_backup_window', $decisions[$safetyUuid]['reasons']);

        // Reconciliation agrees and removes the parked tree only on request.
        [$reconcileExit, $reconciled] = $this->reconcileRestore($report['restore_uuid']);
        self::assertSame(0, $reconcileExit);
        self::assertSame('completed', $reconciled['outcome']);
        self::assertSame('consistent', $reconciled['audit']);
        self::assertDirectoryExists($parked[0]);
        [, $cleaned] = $this->reconcileRestore($report['restore_uuid'], ['--cleanup-parked' => true]);
        self::assertSame('removed', $cleaned['parked_removed'][0]['result']);
        self::assertDirectoryDoesNotExist($parked[0]);
        $this->assertMediaIsStateA();
        self::assertTrue($this->app->maintenanceMode()->active(), 'Reconciliation never changes maintenance mode.');
    }

    public function test_database_only_restore_leaves_media_untouched(): void
    {
        $source = $this->recoveryPointOfStateA();
        [$exit, $report] = $this->liveRestore($source->uuid, 'database');

        self::assertSame(0, $exit, (string) json_encode($report));
        $this->assertDatabaseIsStateA();
        $this->assertMediaIsStateB();
        self::assertSame([], $this->parkedDirectories());
        $journal = $this->journal($report['restore_uuid']);
        self::assertSame('database', $journal->safetyBackup()['profile'], 'A database restore takes a database safety backup.');
        self::assertSame([], $journal->media());
        self::assertNull($journal->source()['snapshot_id'], 'Only the identities the profile uses are frozen.');
        self::assertStringContainsString('note-b1', $this->safetyArchiveSql((string) $journal->safetyRunUuid()));
        self::assertTrue($this->quiescenceProvider->active, 'Quiescence is kept after a completed restore.');
    }

    public function test_media_only_restore_leaves_the_database_untouched_and_keeps_its_audit_row(): void
    {
        $source = $this->recoveryPointOfStateA();
        [$exit, $report] = $this->liveRestore($source->uuid, 'media');

        self::assertSame(0, $exit, (string) json_encode($report));
        $this->assertDatabaseIsStateB();
        $this->assertMediaIsStateA();
        self::assertSame(0, $this->replacement->clears + $this->replacement->imports);
        $journal = $this->journal($report['restore_uuid']);
        self::assertSame('media', $journal->safetyBackup()['profile'], 'A media restore takes a media safety snapshot.');
        self::assertNull($journal->database());
        self::assertNull($journal->source()['archive_sha256']);

        // The catalog was not replaced: the safety run is still there, verified,
        // linked to this restore and pinned for the safety window.
        $safety = BackupRun::query()->where('uuid', $journal->safetyRunUuid())->firstOrFail();
        self::assertSame(BackupTrigger::PreRestore, $safety->trigger);
        self::assertSame(BackupProfile::Media, $safety->profile);
        self::assertSame($report['restore_uuid'], $safety->metadata['restore_uuid']);
        self::assertTrue($safety->isPinned());
        self::assertTrue($safety->pinned_until->lessThan(now('UTC')->addDays(31)), 'Once settled, the pin is the configured safety window.');
        $audit = RestoreRun::query()->where('uuid', $report['restore_uuid'])->firstOrFail();
        self::assertSame(RestoreStatus::Completed, $audit->status);
        self::assertSame($safety->uuid, $audit->pre_change_run_uuid);
    }

    public function test_human_output_shows_the_target_first_and_ends_with_the_maintenance_notice(): void
    {
        $source = $this->recoveryPointOfStateA();
        $output = new BufferedOutput;
        $exit = Artisan::call('quraba:backup:restore', ['--run' => $source->uuid, '--profile' => 'media', '--force' => true, '--confirm' => 'RESTORE_APPLICATION'], $output);
        $text = $output->fetch();

        self::assertSame(0, $exit, $text);
        Sentinels::assertAbsent($text, 'live restore output');
        $warning = strpos($text, 'LIVE RESTORE');
        $completed = strpos($text, 'Restore completed. The application remains in maintenance mode for operator verification.');
        self::assertNotFalse($warning);
        self::assertNotFalse($completed);
        self::assertLessThan($completed, $warning, 'What will be replaced is shown before the outcome.');

        foreach (['source run', $source->uuid, 'snapshot id', 'repository id', 'media destinations', 'safety backup', 'quiescence', 'restore-reconcile --restore=', '--cleanup-parked', 'php artisan up'] as $expected) {
            self::assertStringContainsString($expected, $text);
        }

        $this->assertMediaIsStateA();
        self::assertTrue($this->quiescenceProvider->active, 'Nothing brought the application up.');
    }

    public function test_a_stale_dry_run_is_never_trusted(): void
    {
        $source = $this->recoveryPointOfStateA();
        self::assertSame(0, Artisan::call('quraba:backup:restore', ['--run' => $source->uuid, '--profile' => 'database', '--json' => true]));

        // The archive is damaged AFTER the successful dry run.
        $archive = $source->artifacts()->where('kind', 'application_archive')->firstOrFail();
        file_put_contents($this->bucketPath((string) $archive->locator), 'corrupted after the dry run');

        $enteredBefore = $this->quiescenceProvider->entered;
        [$exit, $report] = $this->liveRestore($source->uuid, 'database');
        self::assertSame(1, $exit);
        self::assertSame('failed', $report['status']);
        self::assertFalse($report['destructive_boundary_crossed']);
        self::assertSame('Nothing was changed in the live application.', substr($report['notice'], -44));
        $this->assertDatabaseIsStateB();
        self::assertSame($enteredBefore, $this->quiescenceProvider->entered, 'It failed before quiescence and before any safety backup.');
        self::assertSame(0, BackupRun::query()->where('trigger', BackupTrigger::PreRestore->value)->count());
        self::assertSame(RestoreJournal::TERMINAL_FAILED, $this->onlyJournal()->terminalState());
        self::assertSame(RestoreStatus::Failed, RestoreRun::query()->where('uuid', $report['restore_uuid'])->firstOrFail()->status);
    }

    public function test_a_live_restore_requires_proven_quiescence_and_has_no_downgrade(): void
    {
        $source = $this->recoveryPointOfStateA();

        // No provider at all: refused before any work.
        $this->config()->set('quraba-backup.consistency.provider', 'none');
        $this->config()->set('quraba-backup.consistency.allow_downgrade', true);
        $this->app->forgetInstance(QuiescenceProvider::class);
        $this->refreshBackupServices();
        [$exit, $report] = $this->liveRestore($source->uuid, 'full');
        self::assertSame(1, $exit);
        self::assertSame('restore.quiescence_unproven', $report['error']['code']);
        self::assertNull($report['journal']);

        // Maintenance mode without the "no background writers" declaration.
        $this->config()->set('quraba-backup.consistency.provider', 'laravel_maintenance');
        $this->config()->set('quraba-backup.consistency.no_background_writers', false);
        $this->app->forgetInstance(QuiescenceProvider::class);
        $this->app->forgetInstance(LiveRestoreService::class);
        [, $report] = $this->liveRestore($source->uuid, 'full');
        self::assertSame('restore.quiescence_unproven', $report['error']['code']);
        self::assertFalse($this->app->maintenanceMode()->active());

        // A provider that claims it but then only delivers best_effort.
        $this->app->forgetInstance(LiveRestoreService::class);
        $this->useQuiescence(new class extends \stdClass implements QuiescenceProvider
        {
            public int $released = 0;

            public function name(): string
            {
                return 'overclaiming';
            }

            public function claimsQuiescence(): bool
            {
                return true;
            }

            public function enter(): QuiescenceSession
            {
                return new QuiescenceSession('overclaiming', ConsistencyLevel::BestEffort, 'writers may continue', function (): void {
                    $this->released++;
                }, static fn (): bool => true);
            }
        });
        [$exit, $report] = $this->liveRestore($source->uuid, 'full');
        self::assertSame(1, $exit);
        self::assertSame('failed', $report['status']);
        self::assertSame('restore.quiescence_unproven', $report['error']['code']);
        self::assertSame(0, BackupRun::query()->where('trigger', BackupTrigger::PreRestore->value)->count());
        $this->assertDatabaseIsStateB();
        $this->assertMediaIsStateB();
    }

    public function test_an_application_already_in_maintenance_stays_there_even_when_the_restore_fails_early(): void
    {
        $this->config()->set('quraba-backup.consistency.provider', 'laravel_maintenance');
        $this->config()->set('quraba-backup.consistency.no_background_writers', true);
        $this->app->forgetInstance(QuiescenceProvider::class);
        $this->refreshBackupServices();
        $source = $this->recoveryPointOfStateA();

        Artisan::call('down');
        $this->faults->crashAt('safety_backup.settled');
        [$exit, $report] = $this->liveRestore($source->uuid, 'media');
        self::assertSame(1, $exit);
        self::assertSame('failed', $report['status']);
        self::assertTrue($this->app->maintenanceMode()->active(), 'It was down before; a failed restore must not bring it up.');
        self::assertFalse($this->onlyJournal()->quiescence()['entered_by_package']);

        // … whereas maintenance the package entered itself is undone when
        // nothing was changed.
        Artisan::call('up');
        $this->faults->clear();
        $this->faults->crashAt('safety_backup.settled');
        $this->app->forgetInstance(LiveRestoreService::class);
        [, $report] = $this->liveRestore($source->uuid, 'media');
        self::assertSame('failed', $report['status']);
        self::assertFalse($this->app->maintenanceMode()->active(), 'Entered by the package, nothing changed: back to the prior state.');
    }

    public function test_live_restore_owns_the_global_and_restore_locks_for_its_whole_lifecycle(): void
    {
        $source = $this->recoveryPointOfStateA();
        $observed = [];

        // In the middle of the destructive phase every other write operation is refused.
        $this->faults->at('db.cleared', function () use (&$observed): void {
            foreach ([LockName::GlobalOperation, LockName::Restore] as $lock) {
                try {
                    $this->app->make(LockManager::class)->acquire($lock, 'intruder')->release();
                    $observed[$lock->value] = 'acquired';
                } catch (OperationBusy) {
                    $observed[$lock->value] = 'busy';
                }
            }

            foreach (['backup' => fn () => $this->manager()->run(BackupProfile::Database), 'retention' => fn () => Artisan::call('quraba:backup:retention', ['--execute' => true, '--json' => true])] as $name => $operation) {
                try {
                    $result = $operation();
                    $observed[$name] = is_int($result) && $result !== 0 ? 'refused' : 'ran';
                } catch (OperationBusy) {
                    $observed[$name] = 'refused';
                }
            }

            // Health stays read-only and keeps working.
            $observed['health'] = $this->app->make(BackupHealthService::class)->check(false)->state()->value;
        });

        [$exit, $report] = $this->liveRestore($source->uuid, 'database');
        self::assertSame(0, $exit, (string) json_encode($report));
        self::assertSame('busy', $observed[LockName::GlobalOperation->value]);
        self::assertSame('busy', $observed[LockName::Restore->value]);
        self::assertSame('refused', $observed['backup']);
        self::assertSame('refused', $observed['retention']);
        self::assertNotSame('', $observed['health']);

        // Afterwards both locks are free again.
        $this->app->make(LockManager::class)->acquire(LockName::GlobalOperation, 'after')->release();
        $this->app->make(LockManager::class)->acquire(LockName::Restore, 'after')->release();
    }

    public function test_dry_run_only_needs_the_restore_lock_and_a_running_restore_blocks_it(): void
    {
        $source = $this->recoveryPointOfStateA();
        $locks = $this->app->make(LockManager::class);

        // A backup (global lock) does not block a dry run …
        $global = $locks->acquire(LockName::GlobalOperation, 'a backup');
        self::assertSame(0, Artisan::call('quraba:backup:restore', ['--run' => $source->uuid, '--profile' => 'database', '--json' => true]));
        // … but it does block a live restore, which needs both locks.
        [$exit, $report] = $this->liveRestore($source->uuid, 'database');
        self::assertSame(1, $exit);
        self::assertSame('operation.busy', $report['error']['code']);
        $global->release();

        // Another restore (restore lock) blocks both.
        $restore = $locks->acquire(LockName::Restore, 'another restore');
        self::assertSame(1, Artisan::call('quraba:backup:restore', ['--run' => $source->uuid, '--profile' => 'database', '--json' => true]));
        [$exit, $report] = $this->liveRestore($source->uuid, 'database');
        self::assertSame(1, $exit);
        self::assertSame('operation.busy', $report['error']['code']);
        $restore->release();

        $this->assertDatabaseIsStateB();
        self::assertSame([], $this->app->make(RestoreJournalStore::class)->all()['journals']);
    }

    public function test_an_unresolved_restore_blocks_further_live_restores_but_not_dry_runs(): void
    {
        $source = $this->recoveryPointOfStateA();
        $this->faults->crashAt('db.cleared');
        [$exit, $first] = $this->liveRestore($source->uuid, 'database');
        self::assertSame(3, $exit);
        self::assertSame('indeterminate', $first['status']);

        $this->faults->clear();
        $this->app->forgetInstance(LiveRestoreService::class);
        [$exit, $second] = $this->liveRestore($source->uuid, 'media');
        self::assertSame(1, $exit);
        self::assertSame('restore.unresolved_restore', $second['error']['code']);
        self::assertStringContainsString($first['restore_uuid'], $second['error']['message']);
        self::assertStringContainsString('quraba:backup:restore-reconcile --restore='.$first['restore_uuid'], $second['error']['message']);
        self::assertStringContainsString('AFTER its destructive boundary', $second['error']['message']);
        self::assertNull($second['journal'], 'The refused restore never opened a journal.');
        $this->assertMediaIsStateB();

        // A dry run stays possible: it changes nothing.
        self::assertSame(0, Artisan::call('quraba:backup:restore', ['--run' => $source->uuid, '--profile' => 'media', '--json' => true]), Artisan::output());

        // The journals are visible to operator tooling without any secret.
        self::assertSame(1, Artisan::call('quraba:backup:restore-reconcile', ['--json' => true]));
        $overview = json_decode(Artisan::output(), true);
        self::assertSame(1, $overview['unresolved']);
        self::assertSame($first['restore_uuid'], $overview['journals'][0]['restore_uuid']);
        self::assertSame($source->uuid, $overview['journals'][0]['source_run_uuid']);
        self::assertSame('db_cleared', $overview['journals'][0]['phase']);
        self::assertNotNull($overview['journals'][0]['safety_backup_run_uuid']);
        Sentinels::assertAbsent((string) json_encode($overview), 'journal overview');

        // An unreadable journal blocks as well: it may describe real damage.
        [, $resolved] = $this->reconcileRestore($first['restore_uuid'], ['--abandon' => true, '--confirm' => 'ABANDON_RESTORE']);
        self::assertSame('abandoned', $resolved['outcome']);
        file_put_contents($this->app->make(PackagePaths::class)->journal.'/11111111-1111-4111-8111-111111111111.json', '{not json');
        $this->app->forgetInstance(LiveRestoreService::class);
        [, $third] = $this->liveRestore($source->uuid, 'media');
        self::assertSame('restore.unresolved_restore', $third['error']['code']);
        self::assertStringContainsString('cannot be read', $third['error']['message']);
    }

    public function test_recording_provider_models_nested_quiescence_like_maintenance_mode(): void
    {
        $provider = new RecordingQuiescenceProvider;
        $outer = $provider->enter();
        $inner = $provider->enter();
        self::assertTrue($outer->enteredHere);
        self::assertFalse($inner->enteredHere);
        $inner->leave();
        self::assertTrue($provider->active);
        $outer->leave();
        self::assertFalse($provider->active);
    }
}
