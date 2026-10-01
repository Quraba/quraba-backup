<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Feature\Restore;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Enums\BackupTrigger;
use Quraba\Backup\Enums\RestoreStatus;
use Quraba\Backup\Health\BackupHealthService;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Models\RestoreRun;
use Quraba\Backup\Restore\Journal\RestoreJournal;
use Quraba\Backup\Restore\Live\LiveRestoreService;
use Quraba\Backup\Retention\RetentionInventory;
use Quraba\Backup\Tests\Support\RunsLiveRestores;
use Quraba\Backup\Tests\Support\Sentinels;
use Quraba\Backup\Tests\TestCase;

/**
 * The restore process is KILLED at each point of its lifecycle (a step
 * throws and the journal can no longer be written). Reconciliation must
 * then decide from the journal and the physical state alone — and health
 * and retention must respect the unresolved restore meanwhile.
 */
final class RestoreReconcileTest extends TestCase
{
    use RunsLiveRestores;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareLiveRestore();
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: bool, 4: string}>
     */
    public static function killPoints(): iterable
    {
        // profile, kill step, expected reconciliation outcome, boundary crossed, expected journal phase left behind
        yield 'maintenance entered' => ['full', 'quiesced', 'failed', false, 'quiesced'];
        yield 'safety backup verified' => ['full', 'safety_backup.settled', 'failed', false, 'safety_backup_verified'];
        yield 'last check passed, boundary not yet recorded' => ['full', 'applying', 'failed', false, 'applying'];
        yield 'database clear started' => ['database', 'db.clear_starting', 'indeterminate', true, 'db_clear_starting'];
        yield 'database clear completed' => ['database', 'db.cleared', 'indeterminate', true, 'db_cleared'];
        yield 'database import started' => ['database', 'db.import_starting', 'indeterminate', true, 'db_import_starting'];
        yield 'database import completed' => ['database', 'db.import_completed', 'indeterminate', true, 'db_import_completed'];
        yield 'database verified, audit not handed back' => ['database', 'db.verified', 'completed', true, 'db_verified'];
        yield 'first media rename (live → parked)' => ['media', 'media.parked', 'indeterminate', true, 'media_applying'];
        yield 'second media rename (staged → live)' => ['media', 'media.activated', 'completed', true, 'media_applying'];
        yield 'post-verification completed, terminal record lost' => ['full', 'verified', 'completed', true, 'verified'];
    }

    #[DataProvider('killPoints')]
    public function test_reconciliation_after_the_process_was_killed(string $profile, string $step, string $outcome, bool $crossed, string $phase): void
    {
        $source = $this->recoveryPointOfStateA();
        $this->killAt($step);

        [$exit, $report] = $this->liveRestore($source->uuid, $profile);
        self::assertNotSame(0, $exit);

        // Exactly what a dead process leaves: no terminal record.
        $journal = $this->journal($report['restore_uuid']);
        self::assertNull($journal->terminalState());
        self::assertTrue($journal->isUnresolved());
        self::assertSame($phase, $journal->phase()->value);
        self::assertSame($crossed, $journal->crossedDestructiveBoundary());

        // Health sees it at once, from the journal alone.
        $health = $this->healthCheck('health.live_restores');
        self::assertSame($crossed ? 'fail' : 'warn', $health['status']);
        self::assertSame($crossed ? 'unknown' : 'degraded', $health['health_impact']);

        // Destructive retention is refused while the restore is unresolved.
        self::assertSame(1, Artisan::call('quraba:backup:retention', ['--execute' => true, '--json' => true]));
        self::assertSame('retention.restore_unresolved', json_decode(Artisan::output(), true)['error']['code']);

        $importsBefore = $this->replacement->imports;
        $clearsBefore = $this->replacement->clears;
        $tablesBefore = Schema::getTableListing();
        $parkedBefore = $this->parkedDirectories();

        [$reconcileExit, $reconciled] = $this->reconcileRestore($report['restore_uuid']);

        self::assertSame($outcome, $reconciled['outcome'], (string) json_encode($reconciled));
        self::assertSame($outcome === 'indeterminate' ? 3 : 0, $reconcileExit);
        Sentinels::assertAbsent((string) json_encode($reconciled), 'reconciliation report');

        // Reconciliation never repeats SQL, never moves media, never rolls back.
        self::assertSame($importsBefore, $this->replacement->imports);
        self::assertSame($clearsBefore, $this->replacement->clears);
        self::assertSame($parkedBefore, $this->parkedDirectories());
        self::assertTrue($this->quiescenceProvider->active || ! $crossed || $outcome !== 'indeterminate');

        $journal = $this->journal($report['restore_uuid']);

        if ($outcome === 'failed') {
            // Only possible because the journal proves no mutation occurred.
            self::assertFalse($journal->crossedDestructiveBoundary());
            self::assertSame('failed', $journal->resolutionOutcome());
            self::assertFalse($journal->isUnresolved());
            $this->assertDatabaseIsStateB();
            $this->assertMediaIsStateB();
            self::assertSame(RestoreStatus::Failed, RestoreRun::query()->where('uuid', $report['restore_uuid'])->firstOrFail()->status);
            self::assertStringContainsString('php artisan up', implode("\n", $reconciled['guidance']));

            return;
        }

        if ($outcome === 'completed') {
            self::assertSame('completed', $journal->resolutionOutcome());
            self::assertFalse($journal->isUnresolved());
            if ($profile !== 'media') {
                $this->assertDatabaseIsStateA();
            }
            if ($profile !== 'database') {
                $this->assertMediaIsStateA();
            }
            // The audit row is rebuilt from the journal, with its linkage.
            $audit = RestoreRun::query()->where('uuid', $report['restore_uuid'])->firstOrFail();
            self::assertSame(RestoreStatus::Completed, $audit->status);
            self::assertSame($source->uuid, $audit->source_run_uuid);
            self::assertSame($journal->safetyRunUuid(), $audit->pre_change_run_uuid);
            self::assertContains($reconciled['audit'], ['recreated', 'repaired', 'consistent']);
            self::assertSame('pass', $this->healthCheck('health.live_restores')['status']);

            return;
        }

        // INDETERMINATE: nothing changed by reconciling, clear guidance, still blocking.
        self::assertSame($tablesBefore, Schema::getTableListing());
        self::assertTrue($journal->isUnresolved());
        self::assertNull($journal->resolution());
        $guidance = implode("\n", $reconciled['guidance']);
        self::assertStringContainsString((string) $journal->safetyRunUuid(), $guidance);
        self::assertStringContainsString('--abandon --confirm=ABANDON_RESTORE', $guidance);
        self::assertStringContainsString('Nothing is rolled back automatically', $guidance);
        self::assertSame('present', $reconciled['evidence']['safety_backup']['state']);
        $this->app->forgetInstance(LiveRestoreService::class);
        [, $blocked] = $this->liveRestore($source->uuid, $profile);
        self::assertSame('restore.unresolved_restore', $blocked['error']['code']);

        // Abandoning needs the exact phrase …
        self::assertSame(1, Artisan::call('quraba:backup:restore-reconcile', ['--restore' => $report['restore_uuid'], '--abandon' => true, '--json' => true]));
        self::assertSame('restore.confirmation_required', json_decode(Artisan::output(), true)['error']['code']);
        self::assertTrue($this->journal($report['restore_uuid'])->isUnresolved());

        // … and is an explicit operator decision, never "failed".
        [$abandonExit, $abandoned] = $this->reconcileRestore($report['restore_uuid'], ['--abandon' => true, '--confirm' => 'ABANDON_RESTORE']);
        self::assertSame(0, $abandonExit);
        self::assertSame('abandoned', $abandoned['outcome']);
        self::assertFalse($this->journal($report['restore_uuid'])->isUnresolved());
        self::assertSame(RestoreJournal::TERMINAL_INDETERMINATE, $this->journal($report['restore_uuid'])->terminalState());

        if (Schema::hasTable('quraba_restore_runs')) {
            self::assertSame(RestoreStatus::Abandoned, RestoreRun::query()->where('uuid', $report['restore_uuid'])->firstOrFail()->status);
        }

        self::assertSame($parkedBefore, $this->parkedDirectories(), 'Abandoning deletes nothing.');
    }

    public function test_full_restore_failing_between_the_database_and_the_media_keeps_the_restored_database(): void
    {
        $source = $this->recoveryPointOfStateA();

        // The database is replaced and verified; the first media root then fails.
        $this->faults->crashAt('media.swap_starting');
        [$exit, $report] = $this->liveRestore($source->uuid, 'full');
        self::assertSame(3, $exit);
        self::assertSame('indeterminate', $report['status']);

        // The chosen order: database first. It is NOT undone because the media failed.
        $this->assertDatabaseIsStateA();
        $this->assertMediaIsStateB();
        self::assertSame(1, $this->replacement->imports);
        $journal = $this->journal($report['restore_uuid']);
        self::assertSame('verified', $journal->databaseState());
        self::assertSame('swap_starting', $journal->rootState('uploads'));
        self::assertSame('synced', $journal->annotations()['audit_handback']);
        self::assertSame(RestoreStatus::Indeterminate, RestoreRun::query()->where('uuid', $report['restore_uuid'])->firstOrFail()->status, 'The audit row survived the import: it was handed back from the journal.');
        self::assertTrue($this->quiescenceProvider->active);

        // Reconciliation tells the two components apart.
        [$reconcileExit, $reconciled] = $this->reconcileRestore($report['restore_uuid']);
        self::assertSame(3, $reconcileExit);
        self::assertTrue($reconciled['evidence']['database']['proven_final']);
        self::assertFalse($reconciled['evidence']['media']['uploads']['proven_final']);
        self::assertSame('original_tree', $reconciled['evidence']['media']['uploads']['live_is']);

        // The operator closes it and finishes with a media-only restore of the same run.
        $this->reconcileRestore($report['restore_uuid'], ['--abandon' => true, '--confirm' => 'ABANDON_RESTORE']);
        $this->app->forgetInstance(LiveRestoreService::class);
        [$exit, $media] = $this->liveRestore($source->uuid, 'media');
        self::assertSame(0, $exit, (string) json_encode($media));
        $this->assertDatabaseIsStateA();
        $this->assertMediaIsStateA();
    }

    public function test_one_root_completed_with_more_roots_pending_stays_indeterminate(): void
    {
        $second = $this->sandbox.'/media-two';
        mkdir($second, 0700);
        file_put_contents($second.'/report.txt', 'report-a');
        $this->config()->set('restic.media.roots', ['uploads' => ['path' => $this->mediaRoot], 'reports' => ['path' => $second]]);
        $this->refreshLiveServices();
        $source = $this->recoveryPointOfStateA();
        file_put_contents($second.'/report.txt', 'report-B-longer');

        $this->killAt('media.before_swap', static fn (array $context): bool => $context['root'] === 'reports');
        [, $report] = $this->liveRestore($source->uuid, 'media');
        $journal = $this->journal($report['restore_uuid']);
        self::assertSame('verified', $journal->rootState('uploads'));
        self::assertSame('staged', $journal->rootState('reports'));

        [$exit, $reconciled] = $this->reconcileRestore($report['restore_uuid']);
        self::assertSame(3, $exit);
        self::assertSame('indeterminate', $reconciled['outcome']);
        self::assertTrue($reconciled['evidence']['media']['uploads']['proven_final']);
        self::assertSame('original_tree', $reconciled['evidence']['media']['reports']['live_is']);
        self::assertSame('report-B-longer', file_get_contents($second.'/report.txt'));
        $this->assertMediaIsStateA();
    }

    public function test_database_restored_but_the_final_catalog_write_was_lost(): void
    {
        $source = $this->recoveryPointOfStateA();
        [$exit, $report] = $this->liveRestore($source->uuid, 'database');
        self::assertSame(0, $exit, (string) json_encode($report));

        // The audit row vanished (or was overwritten by a stale copy).
        RestoreRun::query()->where('uuid', $report['restore_uuid'])->delete();
        [$reconcileExit, $reconciled] = $this->reconcileRestore($report['restore_uuid']);
        self::assertSame(0, $reconcileExit);
        self::assertSame('completed', $reconciled['outcome']);
        self::assertSame('recreated', $reconciled['audit']);
        $audit = RestoreRun::query()->where('uuid', $report['restore_uuid'])->firstOrFail();
        self::assertSame(RestoreStatus::Completed, $audit->status);
        self::assertSame($source->uuid, $audit->source_run_uuid);

        // A stale row claiming something else is corrected from the journal.
        $audit->getConnection()->table('quraba_restore_runs')->where('uuid', $report['restore_uuid'])->update(['status' => 'applying', 'pre_change_run_uuid' => null]);
        [, $again] = $this->reconcileRestore($report['restore_uuid']);
        self::assertSame('repaired', $again['audit']);
        $audit->refresh();
        self::assertSame(RestoreStatus::Completed, $audit->status);
        self::assertSame($this->journal($report['restore_uuid'])->safetyRunUuid(), $audit->pre_change_run_uuid);
    }

    public function test_a_missing_safety_backup_of_a_destructive_restore_is_a_serious_health_failure(): void
    {
        $source = $this->recoveryPointOfStateA();
        [, $report] = $this->liveRestore($source->uuid, 'media');
        self::assertSame('completed', $report['status']);
        self::assertSame('pass', $this->healthCheck('health.live_restores')['status']);

        // The safety snapshot disappears from the repository.
        $journal = $this->journal($report['restore_uuid']);
        $snapshotId = $journal->safetyBackup()['evidence']['snapshot_id'];
        $snapshotsFile = dirname($this->fakeRestic).'/snapshots.json';
        $snapshots = array_values(array_filter(json_decode((string) file_get_contents($snapshotsFile), true), static fn (array $s): bool => $s['id'] !== $snapshotId));
        file_put_contents($snapshotsFile, json_encode($snapshots));

        $health = $this->healthCheck('health.live_restores');
        self::assertSame('fail', $health['status']);
        self::assertSame('failed', $health['health_impact']);
        self::assertStringContainsString((string) $journal->safetyRunUuid(), $health['message']);

        // When remote storage cannot be asked the answer is UNKNOWN, not healthy.
        $this->writeScenario(['repository' => 'unreachable']);
        self::assertSame('unknown', $this->healthCheck('health.live_restores')['health_impact']);
    }

    public function test_retention_protects_the_source_and_the_safety_backup_from_journal_evidence(): void
    {
        $source = $this->recoveryPointOfStateA();
        // Newer ordinary backups would normally push the source out.
        $this->manager()->run(BackupProfile::Recovery);
        foreach (['database', 'media', 'recovery'] as $family) {
            $this->config()->set('quraba-backup.retention.'.$family, ['keep_latest' => 1]);
        }

        $this->killAt('media.parked');
        [, $report] = $this->liveRestore($source->uuid, 'media');
        $journal = $this->journal($report['restore_uuid']);

        // The plan (read-only) shows explicit protections for both runs.
        $exit = Artisan::call('quraba:backup:retention', ['--json' => true]);
        $plan = json_decode(Artisan::output(), true);
        self::assertSame(0, $exit);
        $decisions = array_column($plan['plan']['decisions'], null, 'run_uuid');
        self::assertSame('keep', $decisions[$source->uuid]['decision']);
        self::assertContains('protected:live_restore_source_unresolved', $decisions[$source->uuid]['reasons']);
        self::assertSame('keep', $decisions[$journal->safetyRunUuid()]['decision']);
        self::assertContains('protected:live_restore_safety_backup_unresolved', $decisions[$journal->safetyRunUuid()]['reasons']);

        // A safety backup never counts as "the newest media backup".
        self::assertNotContains('keep_latest', $decisions[$journal->safetyRunUuid()]['reasons']);
        self::assertNotContains('protected:newest_media_snapshot', $decisions[$journal->safetyRunUuid()]['reasons']);

        // Execution is refused outright; nothing was deleted.
        self::assertSame(1, Artisan::call('quraba:backup:retention', ['--execute' => true, '--json' => true]));
        self::assertSame('retention.restore_unresolved', json_decode(Artisan::output(), true)['error']['code']);
        self::assertFileExists($this->bucketPath((string) $source->artifacts()->where('kind', 'application_archive')->value('locator')));

        // A safety backup nobody accounts for is kept, never expired on a guess.
        $orphan = BackupRun::request(BackupProfile::Database, BackupTrigger::PreRestore);
        self::assertSame('pre_restore_safety_unlinked', $this->app->make(RetentionInventory::class)->externalProtections()[$orphan->uuid]);
    }

    /**
     * @return array<string, mixed>
     */
    private function healthCheck(string $id): array
    {
        $this->app->forgetInstance(BackupHealthService::class);
        Artisan::call('quraba:backup:health', ['--json' => true]);
        $checks = json_decode(Artisan::output(), true)['checks'];
        $matches = array_values(array_filter($checks, static fn (array $check): bool => $check['id'] === $id));
        self::assertCount(1, $matches, 'Missing health check '.$id);

        return $matches[0];
    }
}
