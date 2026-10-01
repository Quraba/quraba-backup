<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Feature\Restore;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Quraba\Backup\Enums\ArtifactStatus;
use Quraba\Backup\Enums\BackupTrigger;
use Quraba\Backup\Enums\RestoreStatus;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Models\RestoreRun;
use Quraba\Backup\Restore\Journal\RestoreJournal;
use Quraba\Backup\Restore\Live\LiveRestoreService;
use Quraba\Backup\Tests\Support\RunsLiveRestores;
use Quraba\Backup\Tests\TestCase;

/**
 * Failure at every database boundary of a live restore. Before the
 * destructive boundary: FAILED and nothing changed. After it: INDETERMINATE,
 * no rollback, no repeated SQL, the safety backup and the journal preserved,
 * the application kept quiesced.
 */
final class LiveRestoreDatabaseFailureTest extends TestCase
{
    use RunsLiveRestores;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareLiveRestore();
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    public static function boundaries(): iterable
    {
        // injection, expected status, expected journal db state, expected physical database, expected failure code
        yield 'before the database is cleared' => ['step:db.before_clear', 'failed', 'pending', 'state_b', 'unexpected.error'];
        yield 'immediately after the destructive marker' => ['step:db.clear_starting', 'indeterminate', 'clear_starting', 'state_b', 'unexpected.error'];
        yield 'after the first object was dropped' => ['first-drop', 'indeterminate', 'clear_starting', 'partially_cleared', 'unexpected.error'];
        yield 'after every object was cleared' => ['step:db.cleared', 'indeterminate', 'cleared', 'empty', 'unexpected.error'];
        yield 'before the client starts' => ['step:db.import_starting', 'indeterminate', 'import_starting', 'empty', 'unexpected.error'];
        yield 'in the middle of the import' => ['mid-import', 'indeterminate', 'import_starting', 'partial_import', 'restore.database_apply_failed'];
        yield 'the client exits non-zero' => ['exit-nonzero', 'indeterminate', 'import_starting', 'empty', 'restore.database_apply_failed'];
        yield 'the client exits zero with an incomplete schema' => ['schema-mismatch', 'indeterminate', 'import_completed', 'incomplete_schema', 'restore.database_verification_failed'];
        yield 'after a correct import, before the audit hand-back' => ['step:db.verified', 'indeterminate', 'verified', 'state_a', 'unexpected.error'];
    }

    #[DataProvider('boundaries')]
    public function test_failure_at_a_database_boundary(string $injection, string $status, string $databaseState, string $physical, string $code): void
    {
        $source = $this->recoveryPointOfStateA();

        match (true) {
            str_starts_with($injection, 'step:') => $this->faults->crashAt(substr($injection, 5)),
            $injection === 'first-drop' => $this->faults->crashAt('db.object_dropped'),
            $injection === 'mid-import' => $this->replacement->failImportAfterStatements = 3,
            $injection === 'exit-nonzero' => $this->replacement->importExitsNonZero = true,
            default => $this->replacement->silentlySkipTable = 'app_notes',
        };

        $tablesBefore = count(Schema::getTableListing());
        [$exit, $report] = $this->liveRestore($source->uuid, 'database');

        self::assertSame($status === 'failed' ? 1 : 3, $exit, (string) json_encode($report));
        self::assertSame($status, $report['status']);
        self::assertFalse($report['ok']);
        self::assertSame($code, $report['error']['code']);
        self::assertSame($status === 'indeterminate', $report['destructive_boundary_crossed']);

        // The journal tells exactly how far the restore got.
        $journal = $this->journal($report['restore_uuid']);
        self::assertSame($databaseState, $journal->databaseState());
        self::assertSame($status === 'failed' ? RestoreJournal::TERMINAL_FAILED : RestoreJournal::TERMINAL_INDETERMINATE, $journal->terminalState());
        self::assertSame($status === 'indeterminate', $journal->crossedDestructiveBoundary());
        self::assertSame($status === 'indeterminate', $journal->isUnresolved());
        self::assertSame($code, $journal->terminal()['failure']['code']);

        // The safety backup of state B was verified BEFORE anything changed
        // and is preserved in every case.
        self::assertTrue($journal->safetyVerified());
        $safetyUuid = (string) $journal->safetyRunUuid();
        self::assertFileExists($this->bucketPath((string) $journal->safetyBackup()['evidence']['archive_locator']));

        // The physical database: never rolled back, never re-imported.
        match ($physical) {
            'state_b' => $this->assertDatabaseIsStateB(),
            'state_a' => $this->assertDatabaseIsStateA(),
            'empty' => self::assertSame([], Schema::getTableListing()),
            'partially_cleared' => self::assertCount($tablesBefore - 1, Schema::getTableListing(), 'Exactly one object was dropped; the rest was neither dropped nor restored.'),
            'partial_import' => self::assertNotSame([], Schema::getTableListing()),
            default => self::assertFalse(Schema::hasTable('app_notes')),
        };
        self::assertLessThanOrEqual(1, $this->replacement->imports, 'SQL is never repeated.');
        self::assertLessThanOrEqual(1, $this->replacement->clears);

        if ($status === 'failed') {
            // Nothing changed: FAILED is provable, quiescence is given back.
            self::assertSame(0, $this->replacement->clears);
            self::assertFalse($this->quiescenceProvider->active);
            self::assertSame(RestoreStatus::Failed, RestoreRun::query()->where('uuid', $report['restore_uuid'])->firstOrFail()->status);
            $safety = BackupRun::query()->where('uuid', $safetyUuid)->firstOrFail();
            self::assertSame(BackupTrigger::PreRestore, $safety->trigger);
            self::assertTrue($safety->isPinned());

            return;
        }

        // INDETERMINATE: maintenance kept, nothing rolled back, guidance given.
        self::assertTrue($this->quiescenceProvider->active, 'The application must stay quiesced.');
        self::assertStringContainsString('INDETERMINATE', $report['notice']);
        self::assertStringContainsString('restore-reconcile --restore='.$report['restore_uuid'], implode("\n", $report['follow_up']));
        self::assertDirectoryExists($report['workspace_kept'], 'Evidence is kept after the boundary.');
        $this->assertMediaIsStateB();

        if (Schema::hasTable('quraba_restore_runs')) {
            $audit = RestoreRun::query()->where('uuid', $report['restore_uuid'])->first();
            self::assertContains($audit?->status, [null, RestoreStatus::Indeterminate], 'An audit row can only say indeterminate, never failed.');
        }

        // A second attempt is refused until the first is resolved.
        $this->faults->clear();
        $this->replacement->failImportAfterStatements = null;
        $this->replacement->importExitsNonZero = false;
        $this->replacement->silentlySkipTable = null;
        $this->app->forgetInstance(LiveRestoreService::class);
        [, $again] = $this->liveRestore($source->uuid, 'database');
        self::assertSame('restore.unresolved_restore', $again['error']['code']);
        self::assertLessThanOrEqual(1, $this->replacement->imports);
    }

    public function test_the_live_target_is_reproven_and_a_changed_target_is_refused_before_destruction(): void
    {
        $source = $this->recoveryPointOfStateA();

        // The connection suddenly resolves to another database after preflight.
        $this->faults->at('safety_backup.settled', function (): void {
            $this->replacement->pretendDatabase = 'another_database';
        });

        [$exit, $report] = $this->liveRestore($source->uuid, 'database');
        self::assertSame(1, $exit);
        self::assertSame('failed', $report['status']);
        self::assertSame('restore.database_target_unsafe', $report['error']['code']);
        self::assertSame(0, $this->replacement->clears);
        $this->replacement->pretendDatabase = null;
        $this->assertDatabaseIsStateB();
    }

    public function test_a_schema_that_changes_after_quiescence_stops_the_restore_before_destruction(): void
    {
        $source = $this->recoveryPointOfStateA();
        $this->faults->at('safety_backup.settled', static function (): void {
            DB::statement('CREATE TABLE written_by_a_live_writer (id integer)');
        });

        [$exit, $report] = $this->liveRestore($source->uuid, 'database');
        self::assertSame(1, $exit);
        self::assertSame('restore.database_target_unsafe', $report['error']['code']);
        self::assertStringContainsString('still writing', $report['error']['message']);
        self::assertSame(0, $this->replacement->clears);
        self::assertTrue(Schema::hasTable('written_by_a_live_writer'));
    }

    public function test_an_app_key_mismatch_and_an_expired_source_block_a_live_restore(): void
    {
        $source = $this->recoveryPointOfStateA();
        $original = $this->config()->get('app.key');
        $this->config()->set('app.key', 'base64:another-app-key-entirely-different');
        [$exit, $report] = $this->liveRestore($source->uuid, 'database');
        self::assertSame(1, $exit);
        self::assertSame('mismatch', $report['app_key_compatibility']);
        self::assertSame('failed', $report['status']);
        $this->assertDatabaseIsStateB();
        $this->config()->set('app.key', $original);

        // Source removed by retention between the request and the boundary.
        $this->app->forgetInstance(LiveRestoreService::class);
        $this->faults->at('safety_backup.settled', function () use ($source): void {
            $archive = $source->artifacts()->where('kind', 'application_archive')->firstOrFail();
            unlink($this->bucketPath((string) $archive->locator));
        });
        [$exit, $report] = $this->liveRestore($source->uuid, 'database');
        self::assertSame(1, $exit);
        self::assertSame('restore.source_changed', $report['error']['code']);
        self::assertSame(0, $this->replacement->clears);
        self::assertSame(ArtifactStatus::Verified, $source->artifacts()->where('kind', 'application_archive')->firstOrFail()->status);
    }
}
