<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Support;

use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Quraba\Backup\Backup\BackupManager;
use Quraba\Backup\Contracts\DatabaseReplacement;
use Quraba\Backup\Contracts\QuiescenceProvider;
use Quraba\Backup\Contracts\RestoreStepObserver;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Enums\ConsistencyLevel;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Restore\Journal\RestoreJournal;
use Quraba\Backup\Restore\Journal\RestoreJournalStore;
use Quraba\Backup\Restore\Live\LiveRestoreService;
use Quraba\Backup\Restore\Live\RestoreReconciler;
use Quraba\Backup\Restore\Live\SafetyBackupService;
use Quraba\Backup\Restore\ResticReconstructor;
use Quraba\Backup\Restore\RestorePreflight;
use Quraba\Backup\Restore\RestorePreparation;
use Quraba\Backup\Support\PackagePaths;
use Quraba\Backup\Tests\TestCase;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Wiring for LIVE restore tests on the SQLite test database with the fake
 * Restic: a state A / state B application (rows + a B-only table, files +
 * a B-only file), proven quiescence, the step observer for failure
 * injection and a journal writer that can "die" like a killed process.
 *
 * @mixin TestCase
 */
trait RunsLiveRestores
{
    use BuildsBackups;
    use UsesFakeRestic;

    protected StepFaults $faults;

    protected SqliteDatabaseReplacement $replacement;

    protected RecordingQuiescenceProvider $quiescenceProvider;

    /** When true the journal can no longer be written: the process "died". */
    protected bool $journalDead = false;

    /** @var (Closure(string): bool)|null fail journal writes whose content matches */
    protected ?Closure $journalFault = null;

    protected function prepareLiveRestore(): void
    {
        $this->useFakeRestic(['repository' => 'ready']);
        $this->prepareBackupPipeline();

        $this->faults = new StepFaults;
        $this->app->instance(RestoreStepObserver::class, $this->faults);

        $this->replacement = new SqliteDatabaseReplacement($this->app->make('db'));
        $this->app->instance(DatabaseReplacement::class, $this->replacement);

        $this->useQuiescence(new RecordingQuiescenceProvider(ConsistencyLevel::Quiesced));

        $this->app->instance(RestoreJournalStore::class, new RestoreJournalStore($this->app->make(PackagePaths::class), function (string $path, string $contents): void {
            if ($this->journalDead || ($this->journalFault !== null && ($this->journalFault)($contents))) {
                throw new RuntimeException('simulated journal write failure');
            }

            RestoreJournalStore::atomicWrite($path, $contents);
        }));
    }

    protected function useQuiescence(QuiescenceProvider $provider): void
    {
        if ($provider instanceof RecordingQuiescenceProvider) {
            $this->quiescenceProvider = $provider;
        }

        $this->app->instance(QuiescenceProvider::class, $provider);
        $this->app->forgetInstance(BackupManager::class);
    }

    /**
     * After a configuration change: fresh services, same test doubles.
     */
    protected function refreshLiveServices(): void
    {
        $this->refreshBackupServices();
        $this->app->instance(QuiescenceProvider::class, $this->quiescenceProvider);

        foreach ([LiveRestoreService::class, RestoreReconciler::class, RestorePreparation::class, ResticReconstructor::class, RestorePreflight::class, SafetyBackupService::class] as $service) {
            $this->app->forgetInstance($service);
        }
    }

    /**
     * State A: two rows and two media files.
     */
    protected function seedStateA(): void
    {
        Schema::create('app_notes', static function (Blueprint $table): void {
            $table->id();
            $table->string('body');
        });
        DB::table('app_notes')->insert([['body' => 'note-a1'], ['body' => 'note-a2']]);

        file_put_contents($this->mediaRoot.'/uploads/a.jpg', 'image-a');
        @mkdir($this->mediaRoot.'/docs', 0700, true);
        file_put_contents($this->mediaRoot.'/docs/readme.txt', 'doc-a');
    }

    /**
     * State B: a changed row, a new table, a changed file, a new file and a
     * deleted file.
     */
    protected function mutateToStateB(): void
    {
        DB::table('app_notes')->where('body', 'note-a1')->update(['body' => 'note-b1']);
        DB::table('app_notes')->insert(['body' => 'note-b3']);
        Schema::create('b_only', static function (Blueprint $table): void {
            $table->id();
            $table->string('value');
        });
        DB::table('b_only')->insert(['value' => 'only-in-b']);

        file_put_contents($this->mediaRoot.'/uploads/a.jpg', 'image-B-changed');
        file_put_contents($this->mediaRoot.'/uploads/new-in-b.txt', 'b-only-file');
        unlink($this->mediaRoot.'/docs/readme.txt');
    }

    protected function recoveryPointOfStateA(): BackupRun
    {
        $this->seedStateA();
        $run = $this->manager()->run(BackupProfile::Recovery)->run;
        self::assertSame('completed', $run->status->value, (string) $run->failure_message);
        $this->mutateToStateB();

        return $run;
    }

    protected function assertDatabaseIsStateA(): void
    {
        self::assertSame(['note-a1', 'note-a2'], DB::table('app_notes')->orderBy('id')->pluck('body')->all(), 'The rows must be exactly state A.');
        self::assertFalse(Schema::hasTable('b_only'), 'The B-only table must be gone.');
    }

    protected function assertDatabaseIsStateB(): void
    {
        self::assertSame(['note-b1', 'note-a2', 'note-b3'], DB::table('app_notes')->orderBy('id')->pluck('body')->all(), 'The database must still be state B.');
        self::assertTrue(Schema::hasTable('b_only'));
    }

    protected function assertMediaIsStateA(): void
    {
        self::assertSame('image-a', file_get_contents($this->mediaRoot.'/uploads/a.jpg'));
        self::assertSame('doc-a', file_get_contents($this->mediaRoot.'/docs/readme.txt'), 'A file deleted in B must be back.');
        self::assertFileDoesNotExist($this->mediaRoot.'/uploads/new-in-b.txt', 'The B-only file must be gone.');
    }

    protected function assertMediaIsStateB(): void
    {
        self::assertSame('image-B-changed', file_get_contents($this->mediaRoot.'/uploads/a.jpg'), 'The media must still be state B.');
        self::assertFileExists($this->mediaRoot.'/uploads/new-in-b.txt');
        self::assertFileDoesNotExist($this->mediaRoot.'/docs/readme.txt');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{0: int, 1: array<string, mixed>}
     */
    protected function liveRestore(string $runUuid, string $profile = 'full', array $options = []): array
    {
        // An explicit buffer: commands the restore itself calls (artisan
        // down, …) would otherwise replace Artisan::output().
        $output = new BufferedOutput;
        $exit = Artisan::call('quraba:backup:restore', ['--run' => $runUuid, '--profile' => $profile, '--force' => true, '--confirm' => 'RESTORE_APPLICATION', '--json' => true, ...$options], $output);
        $text = $output->fetch();
        $report = json_decode($text, true);
        self::assertIsArray($report, $text);
        Sentinels::assertAbsent((string) json_encode($report), 'live restore report');

        return [$exit, $report];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{0: int, 1: array<string, mixed>}
     */
    protected function reconcileRestore(string $restoreUuid, array $options = []): array
    {
        $this->journalDead = false;
        $this->journalFault = null;
        $this->faults->clear();
        $output = new BufferedOutput;
        $exit = Artisan::call('quraba:backup:restore-reconcile', ['--restore' => $restoreUuid, '--json' => true, ...$options], $output);
        $text = $output->fetch();
        $report = json_decode($text, true);
        self::assertIsArray($report, $text);

        return [$exit, $report];
    }

    protected function journal(string $restoreUuid): RestoreJournal
    {
        return (new RestoreJournalStore($this->app->make(PackagePaths::class)))->find($restoreUuid) ?? throw new RuntimeException('no journal');
    }

    /**
     * The journal of the only restore that left one.
     */
    protected function onlyJournal(): RestoreJournal
    {
        $journals = (new RestoreJournalStore($this->app->make(PackagePaths::class)))->all()['journals'];
        self::assertCount(1, $journals);

        return $journals[0];
    }

    /**
     * Simulates the restore process being KILLED at a step: the step throws
     * and from then on nothing can be written to the journal, so the journal
     * stays exactly as a dead process would have left it.
     */
    protected function killAt(string $step, ?Closure $when = null): void
    {
        $this->faults->at($step, function (array $context) use ($step, $when): void {
            if ($when !== null && ! $when($context)) {
                return;
            }

            $this->journalDead = true;

            throw new SimulatedCrash('process killed at '.$step);
        });
    }

    /**
     * @return list<string> parked sibling directories of the media root
     */
    protected function parkedDirectories(): array
    {
        return array_values(glob(dirname($this->mediaRoot).'/.*quraba-parked-*') ?: []);
    }
}
