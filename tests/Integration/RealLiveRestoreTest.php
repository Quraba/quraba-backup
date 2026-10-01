<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Integration;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use Quraba\Backup\Backup\BackupManager;
use Quraba\Backup\Contracts\ArchiveEngine;
use Quraba\Backup\Contracts\DatabaseDumper;
use Quraba\Backup\Contracts\DatabaseReplacement;
use Quraba\Backup\Contracts\QuiescenceProvider;
use Quraba\Backup\Contracts\RestoreStepObserver;
use Quraba\Backup\Database\SchemaInventory;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Enums\BackupStatus;
use Quraba\Backup\Enums\BackupTrigger;
use Quraba\Backup\Enums\RestoreStatus;
use Quraba\Backup\Exceptions\RestoreFailed;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Models\RestoreRun;
use Quraba\Backup\Restic\RepositoryIdentityGuard;
use Quraba\Backup\Restore\Journal\RestoreJournal;
use Quraba\Backup\Restore\Journal\RestoreJournalStore;
use Quraba\Backup\Restore\Live\ExactDatabaseReplacement;
use Quraba\Backup\Restore\Live\LiveRestoreService;
use Quraba\Backup\Support\LocalCatalog;
use Quraba\Backup\Support\PackagePaths;
use Quraba\Backup\Support\Process\ProcessFactory;
use Quraba\Backup\Support\Process\SymfonyProcessFactory;
use Quraba\Backup\Tests\Support\Sentinels;
use Quraba\Backup\Tests\Support\StepFaults;
use Quraba\Backup\Tests\Support\UsesFakeRestic;
use Quraba\Backup\Tests\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use ZipArchive;

/**
 * END-TO-END live restore against a REAL MySQL/MariaDB server with the real
 * dump and client tools (mysqldump/mariadb-dump, mysql/mariadb) and — when
 * QURABA_BACKUP_TEST_RESTIC_BINARY is set — the real pinned Restic.
 *
 * Opt-in: QURABA_BACKUP_TEST_MYSQL_HOST (and optionally _PORT, _USERNAME,
 * _PASSWORD). Every database it touches is created by the test with the
 * prefix quraba_backup_it_ and dropped afterwards. The package catalog lives
 * in the application database, exactly as in production, so the catalog
 * self-restore problem is exercised for real.
 */
#[Group('database-integration')]
final class RealLiveRestoreTest extends TestCase
{
    use UsesFakeRestic;

    private PDO $admin;

    private string $database;

    /** @var list<string> */
    private array $databases = [];

    /** @var list<string> */
    private array $users = [];

    private string $mediaRoot;

    private StepFaults $faults;

    private bool $realRestic = false;

    /** Set when this test had to allow trigger creation by non-SUPER accounts on a throwaway server. */
    private bool $restoreBinlogTrust = false;

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('QURABA_BACKUP_TEST_MYSQL_HOST');

        if (! is_string($host) || $host === '') {
            self::markTestSkipped('Set QURABA_BACKUP_TEST_MYSQL_HOST to run the real MySQL/MariaDB live restore tests.');
        }

        $port = (int) (getenv('QURABA_BACKUP_TEST_MYSQL_PORT') ?: 3306);
        $user = (string) (getenv('QURABA_BACKUP_TEST_MYSQL_USERNAME') ?: 'root');
        $password = (string) (getenv('QURABA_BACKUP_TEST_MYSQL_PASSWORD') ?: '');
        $this->admin = new PDO(sprintf('mysql:host=%s;port=%d', $host, $port), $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        // With binary logging on (the MySQL 8 default), accounts without SUPER
        // cannot create triggers at all unless the server trusts function
        // creators. Only then, and only for the duration of the test, is the
        // setting changed; a server without binary logging is left untouched.
        [$binlog, $trusted] = $this->admin->query('SELECT @@log_bin, @@log_bin_trust_function_creators')->fetch(PDO::FETCH_NUM);

        if ((int) $binlog === 1 && (int) $trusted === 0) {
            $this->admin->exec('SET GLOBAL log_bin_trust_function_creators = 1');
            $this->restoreBinlogTrust = true;
        }

        $this->database = $this->createDatabase('live');
        $this->config()->set('database.connections.it_mysql', [
            'driver' => 'mysql', 'host' => $host, 'port' => $port, 'database' => $this->database,
            'username' => $user, 'password' => $password, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '',
        ]);
        // Application data AND the package catalog in one database, as in production.
        $this->config()->set('quraba-backup.database.connection', 'it_mysql');
        $this->config()->set('quraba-backup.database.catalog_connection', 'it_mysql');
        $this->migrateCatalog();

        // Real Restic when available, the fake otherwise.
        $binary = getenv('QURABA_BACKUP_TEST_RESTIC_BINARY');
        $this->realRestic = is_string($binary) && $binary !== '' && is_file($binary);

        if ($this->realRestic) {
            $this->config()->set('restic.binary', $binary);
            $this->config()->set('restic.repository.url', $this->sandbox.'/local-repository');
            $this->refreshPackageServices();
            $this->app->instance(ProcessFactory::class, new SymfonyProcessFactory);
        } else {
            $this->useFakeRestic(['repository' => 'ready']);
        }

        $this->mediaRoot = $this->sandbox.'/media';
        mkdir($this->mediaRoot.'/uploads', 0700, true);
        $this->config()->set('restic.media.roots', ['uploads' => ['path' => $this->mediaRoot]]);

        // Real maintenance mode, declared free of background writers.
        $this->config()->set('quraba-backup.consistency.provider', 'laravel_maintenance');
        $this->config()->set('quraba-backup.consistency.no_background_writers', true);

        $this->faults = new StepFaults;
        $this->app->instance(RestoreStepObserver::class, $this->faults);

        foreach ([DatabaseDumper::class, ArchiveEngine::class, QuiescenceProvider::class, BackupManager::class] as $service) {
            $this->app->forgetInstance($service);
        }

        if ($this->realRestic) {
            $this->app->make(RepositoryIdentityGuard::class)->initialize();
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->admin)) {
            if ($this->app->maintenanceMode()->active()) {
                Artisan::call('up');
            }

            foreach (['it_mysql', 'it_scratch'] as $connection) {
                DB::disconnect($connection);
            }

            foreach ($this->users as $user) {
                $this->admin->exec(sprintf("DROP USER IF EXISTS '%s'@'%%'", $user));
            }

            foreach ($this->databases as $database) {
                $this->admin->exec(sprintf('DROP DATABASE IF EXISTS `%s`', $database));
            }

            if ($this->restoreBinlogTrust) {
                $this->admin->exec('SET GLOBAL log_bin_trust_function_creators = 0');
            }
        }

        parent::tearDown();
    }

    private function createDatabase(string $purpose): string
    {
        $name = 'quraba_backup_it_'.$purpose.'_'.bin2hex(random_bytes(5));
        $this->admin->exec(sprintf('CREATE DATABASE `%s` CHARACTER SET utf8mb4', $name));
        $this->databases[] = $name;

        return $name;
    }

    private function migrateCatalog(): void
    {
        self::assertSame(0, Artisan::call('migrate', ['--database' => 'it_mysql', '--path' => dirname(__DIR__, 2).'/database/migrations', '--realpath' => true, '--force' => true]), Artisan::output());
    }

    private function db(): Connection
    {
        return DB::connection('it_mysql');
    }

    /**
     * State A: rows, a second table, a view, a trigger, a procedure and
     * media files — every object type the exact replacement must handle.
     */
    private function seedStateA(): void
    {
        $db = $this->db();
        $db->statement('CREATE TABLE app_notes (id INT PRIMARY KEY AUTO_INCREMENT, body VARCHAR(100) NOT NULL) ENGINE=InnoDB');
        $db->statement('CREATE TABLE app_audit (id INT PRIMARY KEY AUTO_INCREMENT, note_id INT NOT NULL, CONSTRAINT fk_audit_note FOREIGN KEY (note_id) REFERENCES app_notes (id)) ENGINE=InnoDB');
        $db->unprepared('CREATE TRIGGER app_notes_audit AFTER INSERT ON app_notes FOR EACH ROW INSERT INTO app_audit (note_id) VALUES (NEW.id)');
        $db->statement('CREATE VIEW app_notes_view AS SELECT id, body FROM app_notes');
        $db->unprepared('CREATE PROCEDURE app_note_count() SELECT COUNT(*) FROM app_notes');
        $db->table('app_notes')->insert([['body' => 'note-a1'], ['body' => "note-a2 with 'quotes', a \\ backslash and a ; semicolon"]]);

        file_put_contents($this->mediaRoot.'/uploads/a.jpg', 'image-a');
        mkdir($this->mediaRoot.'/docs', 0700);
        file_put_contents($this->mediaRoot.'/docs/readme.txt', 'doc-a');
    }

    private function mutateToStateB(): void
    {
        $db = $this->db();
        $db->table('app_notes')->where('id', 1)->update(['body' => 'note-b1']);
        $db->table('app_notes')->insert(['body' => 'note-b3']);
        $db->statement('CREATE TABLE b_only (id INT PRIMARY KEY, value VARCHAR(50)) ENGINE=InnoDB');
        $db->table('b_only')->insert(['id' => 1, 'value' => 'only-in-b']);
        $db->unprepared('CREATE PROCEDURE b_only_procedure() SELECT 1');
        $db->statement('DROP VIEW app_notes_view');

        file_put_contents($this->mediaRoot.'/uploads/a.jpg', 'image-B-changed');
        file_put_contents($this->mediaRoot.'/uploads/new-in-b.txt', 'b-only-file');
        unlink($this->mediaRoot.'/docs/readme.txt');
    }

    private function recoveryPointOfStateA(): BackupRun
    {
        $this->seedStateA();
        $result = $this->app->make(BackupManager::class)->run(BackupProfile::Recovery);
        self::assertSame(BackupStatus::Completed, $result->status, (string) $result->run->failure_message);
        self::assertSame('quiesced', $result->run->consistency->value);
        self::assertFalse($this->app->maintenanceMode()->active(), 'A backup leaves maintenance mode again.');
        $this->mutateToStateB();

        return $result->run;
    }

    /**
     * @return array<string, list<string>>
     */
    private function objects(string $database): array
    {
        $objects = [];
        foreach ([
            'tables' => "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '%s' AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME NOT LIKE 'quraba_%%' AND TABLE_NAME <> 'migrations' ORDER BY 1",
            'views' => "SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA = '%s' ORDER BY 1",
            'routines' => "SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = '%s' ORDER BY 1",
            'triggers' => "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = '%s' ORDER BY 1",
        ] as $type => $sql) {
            $objects[$type] = array_map('strtolower', $this->admin->query(sprintf($sql, $database))->fetchAll(PDO::FETCH_COLUMN));
        }

        return $objects;
    }

    private function assertDatabaseIsStateA(?string $database = null): void
    {
        $database ??= $this->database;
        self::assertSame(
            ['tables' => ['app_audit', 'app_notes'], 'views' => ['app_notes_view'], 'routines' => ['app_note_count'], 'triggers' => ['app_notes_audit']],
            $this->objects($database),
            'Exactly the schema objects of state A: the B-only table and procedure are gone, the view dropped in B is back.',
        );
        self::assertSame(
            ['note-a1', "note-a2 with 'quotes', a \\ backslash and a ; semicolon"],
            $this->admin->query(sprintf('SELECT body FROM `%s`.app_notes ORDER BY id', $database))->fetchAll(PDO::FETCH_COLUMN),
        );
        self::assertSame(['1', '2'], array_map('strval', $this->admin->query(sprintf('SELECT note_id FROM `%s`.app_audit ORDER BY id', $database))->fetchAll(PDO::FETCH_COLUMN)), 'Trigger-written rows are restored once, not re-fired.');
    }

    private function assertDatabaseIsStateB(): void
    {
        $objects = $this->objects($this->database);
        self::assertContains('b_only', $objects['tables']);
        self::assertContains('b_only_procedure', $objects['routines']);
        self::assertSame([], $objects['views']);
        self::assertSame(['note-b1', "note-a2 with 'quotes', a \\ backslash and a ; semicolon", 'note-b3'], $this->admin->query(sprintf('SELECT body FROM `%s`.app_notes ORDER BY id', $this->database))->fetchAll(PDO::FETCH_COLUMN));
    }

    private function assertMediaIsStateA(): void
    {
        self::assertSame('image-a', file_get_contents($this->mediaRoot.'/uploads/a.jpg'));
        self::assertSame('doc-a', file_get_contents($this->mediaRoot.'/docs/readme.txt'));
        self::assertFileDoesNotExist($this->mediaRoot.'/uploads/new-in-b.txt');
    }

    private function assertMediaIsStateB(): void
    {
        self::assertSame('image-B-changed', file_get_contents($this->mediaRoot.'/uploads/a.jpg'));
        self::assertSame('b-only-file', file_get_contents($this->mediaRoot.'/uploads/new-in-b.txt'));
        self::assertFileDoesNotExist($this->mediaRoot.'/docs/readme.txt');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function restore(string $runUuid, string $profile, array $options = [], bool $live = true): array
    {
        $this->app->forgetInstance(LiveRestoreService::class);
        $output = new BufferedOutput;
        $exit = Artisan::call('quraba:backup:restore', ['--run' => $runUuid, '--profile' => $profile, '--json' => true, ...($live ? ['--force' => true, '--confirm' => 'RESTORE_APPLICATION'] : []), ...$options], $output);
        $text = $output->fetch();
        $report = json_decode($text, true);
        self::assertIsArray($report, $text);
        Sentinels::assertAbsent($text, 'restore report');

        return [$exit, $report];
    }

    private function journal(string $restoreUuid): RestoreJournal
    {
        return (new RestoreJournalStore($this->app->make(PackagePaths::class)))->find($restoreUuid) ?? throw new \RuntimeException('no journal');
    }

    public function test_state_a_is_restored_exactly_and_the_safety_backup_can_bring_state_b_back(): void
    {
        $source = $this->recoveryPointOfStateA();
        $this->assertDatabaseIsStateB();

        [$exit, $report] = $this->restore($source->uuid, 'full');
        self::assertSame(0, $exit, (string) json_encode($report));
        self::assertSame('completed', $report['status']);
        self::assertSame('Restore completed. The application remains in maintenance mode for operator verification.', $report['notice']);

        // DB exactly A (rows, table, view, trigger, procedure), media exactly A.
        $this->assertDatabaseIsStateA();
        $this->assertMediaIsStateA();
        self::assertTrue($this->app->maintenanceMode()->active(), 'The application remains in maintenance mode.');

        // Journal terminal, with real verification evidence.
        $journal = $this->journal($report['restore_uuid']);
        self::assertSame(RestoreJournal::TERMINAL_COMPLETED, $journal->terminalState());
        self::assertSame('verified', $journal->databaseState());
        self::assertSame('verified', $journal->rootState('uploads'));
        self::assertSame($this->database, $journal->database()['name']);
        self::assertSame('it_mysql', $journal->database()['connection']);
        $verification = $journal->database()['verification'];
        self::assertSame('backup_metadata', $verification['schema_comparison'], 'Same server: the restored schema equals the fingerprint recorded in the backup.');
        self::assertSame('match', $verification['migration_comparison']);
        self::assertGreaterThanOrEqual(8, $verification['base_tables']);
        self::assertGreaterThan(0, $journal->database()['objects_dropped']);
        // The inventory that was cleared is state B's: two procedures, one trigger, no view.
        $inventoried = $journal->database()['inventory_before']['by_type'];
        self::assertSame(2, $inventoried[SchemaInventory::PROCEDURE]);
        self::assertSame(1, $inventoried[SchemaInventory::TRIGGER]);
        self::assertArrayNotHasKey(SchemaInventory::VIEW, $inventoried);
        Sentinels::assertAbsent((string) json_encode($journal->toArray()), 'journal');

        // Catalog self-restore: the imported catalog predates this restore,
        // yet its audit row is there — rebuilt from the journal.
        $audit = RestoreRun::query()->where('uuid', $report['restore_uuid'])->firstOrFail();
        self::assertSame(RestoreStatus::Completed, $audit->status);
        self::assertSame($source->uuid, $audit->source_run_uuid);
        self::assertSame($journal->safetyRunUuid(), $audit->pre_change_run_uuid);
        self::assertSame('synced', $journal->annotations()['audit_handback']);
        self::assertNull(BackupRun::query()->where('uuid', $journal->safetyRunUuid())->first(), 'The restored catalog is older than the safety backup.');
        self::assertSame('running', BackupRun::query()->where('uuid', $source->uuid)->firstOrFail()->status->value, 'The source run row is the stale copy frozen inside its own dump.');

        // The safety backup really contains state B.
        $safetyArchive = (string) $journal->safetyBackup()['evidence']['archive_locator'];
        $zip = new ZipArchive;
        self::assertTrue($zip->open($this->bucketPath($safetyArchive)));
        $zip->setPassword(Sentinels::ARCHIVE_PASSWORD);
        $sql = (string) $zip->getFromName('database/database.sql');
        $zip->close();
        self::assertStringContainsString('note-b1', $sql);
        self::assertStringContainsString('CREATE TABLE `b_only`', $sql);

        // Coherent again: reconcile adopts the stale source row, the catalog
        // rebuild re-adopts the safety backup from physical evidence.
        self::assertSame(0, Artisan::call('quraba:backup:reconcile', ['--json' => true]), Artisan::output());
        self::assertSame(BackupStatus::Completed, BackupRun::query()->where('uuid', $source->uuid)->firstOrFail()->status);
        self::assertSame(0, Artisan::call('quraba:backup:catalog:rebuild', ['--apply' => true, '--json' => true]), Artisan::output());
        $safety = BackupRun::query()->where('uuid', $journal->safetyRunUuid())->firstOrFail();
        self::assertSame(BackupTrigger::PreRestore, $safety->trigger);
        self::assertSame(BackupStatus::Completed, $safety->status);

        // And the way back works: restoring the safety backup yields state B
        // again — in the database and in the media — with real tools.
        [$exit, $back] = $this->restore((string) $journal->safetyRunUuid(), 'full');
        self::assertSame(0, $exit, (string) json_encode($back));
        $this->assertDatabaseIsStateB();
        $this->assertMediaIsStateB();
        self::assertFalse($this->journal($back['restore_uuid'])->quiescence()['entered_by_package'], 'It was already in maintenance mode and stays there.');
        self::assertTrue($this->app->maintenanceMode()->active());
        self::assertCount(2, glob(dirname($this->mediaRoot).'/.media.quraba-parked-*') ?: [], 'Both old trees are still parked.');
    }

    public function test_clean_host_restore_into_a_new_empty_database_without_catalog_or_media(): void
    {
        $source = $this->recoveryPointOfStateA();

        // A new server: an EMPTY database, no package migrations, no media,
        // no local catalog — only remote storage and the repository.
        $clean = $this->createDatabase('clean');
        $connection = $this->config()->get('database.connections.it_mysql');
        $this->config()->set('database.connections.it_mysql', [...$connection, 'database' => $clean]);
        DB::purge('it_mysql');
        self::removeTree($this->mediaRoot);
        self::assertFalse($this->app->make(LocalCatalog::class)->available());
        foreach ([LiveRestoreService::class, DatabaseReplacement::class] as $service) {
            $this->app->forgetInstance($service);
        }

        // Remote discovery and the dry run work with nothing local.
        $exit = Artisan::call('quraba:backup:discover', ['--remote' => true, '--json' => true]);
        $discovered = json_decode(Artisan::output(), true);
        self::assertSame(0, $exit);
        self::assertTrue(array_column($discovered['runs'], null, 'run_uuid')[$source->uuid]['complete_recovery_point']);
        [$exit, $dryRun] = $this->restore($source->uuid, 'full', live: false);
        self::assertSame(0, $exit, (string) json_encode($dryRun));
        self::assertSame('remote', $dryRun['source']);
        self::assertSame([], $this->admin->query(sprintf('SHOW TABLES FROM `%s`', $clean))->fetchAll(), 'The dry run did not migrate or create anything.');

        // Not inferred: without the explicit flag the restore is refused.
        [$exit, $refused] = $this->restore($source->uuid, 'full');
        self::assertSame(1, $exit);
        self::assertSame('restore.catalog_unavailable', $refused['error']['code']);

        [$exit, $report] = $this->restore($source->uuid, 'full', ['--clean-host' => true]);
        self::assertSame(0, $exit, (string) json_encode($report));
        self::assertSame('completed', $report['status']);

        $this->assertDatabaseIsStateA($clean);
        $this->assertMediaIsStateA();
        $journal = $this->journal($report['restore_uuid']);
        self::assertSame('not_required_target_proven_empty', $journal->safetyBackup()['requirement']);
        self::assertNull($journal->safetyRunUuid());
        self::assertSame($clean, $journal->database()['name']);
        self::assertSame([], glob(dirname($this->mediaRoot).'/.media.quraba-parked-*') ?: []);

        // The imported backup brought its catalog; the audit was handed back into it.
        self::assertTrue($this->app->make(LocalCatalog::class)->available());
        $audit = RestoreRun::query()->where('uuid', $report['restore_uuid'])->firstOrFail();
        self::assertSame(RestoreStatus::Completed, $audit->status);
        self::assertSame($source->uuid, $audit->source_run_uuid);
        self::assertTrue($this->app->maintenanceMode()->active());

        // The original database was never touched by the clean-host restore.
        self::assertContains('b_only', $this->objects($this->database)['tables']);
    }

    public function test_a_new_host_with_another_database_account_is_refused_before_destruction_unless_definers_are_rewritten(): void
    {
        // State A's view, trigger and procedure were defined by the admin account.
        $source = $this->recoveryPointOfStateA();

        // The new host: an empty database and a database-scoped application
        // account, as on shared hosting — it cannot create objects for others.
        $clean = $this->createDatabase('definer');
        $user = 'quraba_it_'.bin2hex(random_bytes(5));
        $password = bin2hex(random_bytes(16));
        $this->users[] = $user;
        $this->admin->exec(sprintf("CREATE USER '%s'@'%%' IDENTIFIED BY '%s'", $user, $password));
        $this->admin->exec(sprintf("GRANT ALL PRIVILEGES ON `%s`.* TO '%s'@'%%'", str_replace('_', '\\_', $clean), $user));
        $connection = $this->config()->get('database.connections.it_mysql');
        $this->config()->set('database.connections.it_mysql', [...$connection, 'database' => $clean, 'username' => $user, 'password' => $password]);
        DB::purge('it_mysql');
        self::removeTree($this->mediaRoot);
        foreach ([DatabaseReplacement::class, DatabaseDumper::class, ArchiveEngine::class, BackupManager::class] as $service) {
            $this->app->forgetInstance($service);
        }

        // Refused BEFORE anything is changed: the import would fail halfway.
        [$exit, $refused] = $this->restore($source->uuid, 'full', ['--clean-host' => true]);
        self::assertSame(1, $exit, (string) json_encode($refused));
        self::assertSame('failed', $refused['status']);
        self::assertFalse($refused['destructive_boundary_crossed']);
        self::assertSame('restore.database_target_unsafe', $refused['error']['code']);
        self::assertStringContainsString('rewrite_definers', $refused['error']['message']);
        self::assertStringContainsString($user.'@%', $refused['error']['message']);
        self::assertSame([], $this->admin->query(sprintf('SHOW TABLES FROM `%s`', $clean))->fetchAll());
        self::assertDirectoryDoesNotExist($this->mediaRoot);

        // Explicitly allowed: the objects are created as the restoring account.
        $this->config()->set('quraba-backup.restore.rewrite_definers', true);
        $this->app->forgetInstance(DatabaseReplacement::class);
        [$exit, $report] = $this->restore($source->uuid, 'full', ['--clean-host' => true]);
        self::assertSame(0, $exit, (string) json_encode($report));
        self::assertTrue($report['database_import']['definers_rewritten']);
        $this->assertDatabaseIsStateA($clean);
        $this->assertMediaIsStateA();

        foreach ([
            "SELECT DEFINER FROM information_schema.VIEWS WHERE TABLE_SCHEMA = '%s'",
            "SELECT DEFINER FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = '%s'",
            "SELECT DEFINER FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = '%s'",
        ] as $sql) {
            self::assertSame([$user.'@%'], $this->admin->query(sprintf($sql, $clean))->fetchAll(PDO::FETCH_COLUMN), 'Defined by the restoring account, not by an account that does not exist here.');
        }
    }

    public function test_real_database_failures_after_the_boundary_are_indeterminate_and_never_repeated(): void
    {
        $source = $this->recoveryPointOfStateA();

        // (1) After the first real DROP.
        $this->faults->crashAt('db.object_dropped');
        [$exit, $first] = $this->restore($source->uuid, 'database');
        self::assertSame(3, $exit, (string) json_encode($first));
        self::assertSame('indeterminate', $first['status']);
        self::assertSame('clear_starting', $this->journal($first['restore_uuid'])->databaseState());
        self::assertTrue($this->app->maintenanceMode()->active());
        $remaining = (int) $this->admin->query(sprintf("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '%s'", $this->database))->fetchColumn();
        self::assertGreaterThan(0, $remaining, 'One object was dropped, the rest is untouched: no rollback, no continuation.');
        $this->assertMediaIsStateB();

        // Reconciliation refuses to call it failed or completed; the operator abandons it.
        $this->faults->clear();
        $output = new BufferedOutput;
        self::assertSame(3, Artisan::call('quraba:backup:restore-reconcile', ['--restore' => $first['restore_uuid'], '--json' => true], $output));
        self::assertSame('indeterminate', json_decode($output->fetch(), true)['outcome']);
        self::assertSame(0, Artisan::call('quraba:backup:restore-reconcile', ['--restore' => $first['restore_uuid'], '--abandon' => true, '--confirm' => 'ABANDON_RESTORE', '--json' => true], new BufferedOutput));

        // (2) The dump is damaged between validation and import: the real
        //     client exits non-zero in the middle of the import.
        $this->faults->at('db.import_starting', function (): void {
            // The newest workspace: earlier indeterminate restores keep theirs as evidence.
            $dump = glob($this->app->make(PackagePaths::class)->workspaces.'/op-*/restore/database.sql');
            file_put_contents(end($dump), "\nTHIS IS NOT SQL AND STOPS THE CLIENT;\n", FILE_APPEND);
        });
        [$exit, $second] = $this->restore($source->uuid, 'database');
        self::assertSame(3, $exit, (string) json_encode($second));
        self::assertSame('restore.database_apply_failed', $second['error']['code']);
        self::assertStringContainsString('exited with code', $second['error']['message']);
        self::assertStringNotContainsString('THIS IS NOT SQL', (string) json_encode($second), 'Client errors can quote data; only the position is kept.');
        self::assertSame('import_starting', $this->journal($second['restore_uuid'])->databaseState());
        self::assertMatchesRegularExpression('/ERROR \d+ \([0-9A-Z]+\) at line \d+/', $second['error']['message'], 'Only the error position is reported.');
        $this->faults->clear();
        Artisan::call('quraba:backup:restore-reconcile', ['--restore' => $second['restore_uuid'], '--abandon' => true, '--confirm' => 'ABANDON_RESTORE', '--json' => true], new BufferedOutput);

        // (3) The client exits ZERO but imported an incomplete schema.
        $this->faults->at('db.import_starting', function (): void {
            $dump = glob($this->app->make(PackagePaths::class)->workspaces.'/op-*/restore/database.sql');
            file_put_contents(end($dump), "CREATE TABLE only_this_table (id INT PRIMARY KEY);\n");
        });
        [$exit, $third] = $this->restore($source->uuid, 'database');
        self::assertSame(3, $exit, (string) json_encode($third));
        self::assertSame('restore.database_verification_failed', $third['error']['code']);
        self::assertSame('import_completed', $this->journal($third['restore_uuid'])->databaseState(), 'Exit 0 was recorded; it was not accepted as success.');
        $this->faults->clear();
        $output = new BufferedOutput;
        Artisan::call('quraba:backup:restore-reconcile', ['--restore' => $third['restore_uuid'], '--json' => true], $output);
        $reconciled = json_decode($output->fetch(), true);
        self::assertSame('indeterminate', $reconciled['outcome'], 'A finished import whose schema is not the backup\'s is not "completed".');
        self::assertFalse($reconciled['evidence']['database']['proven_final']);
        Artisan::call('quraba:backup:restore-reconcile', ['--restore' => $third['restore_uuid'], '--abandon' => true, '--confirm' => 'ABANDON_RESTORE', '--json' => true], new BufferedOutput);

        // The failed import left a database without the package tables. It is
        // neither a clean host nor restorable as it is: the documented way on
        // is to run the package migrations, then restore again.
        [$exit, $stuck] = $this->restore($source->uuid, 'database');
        self::assertSame(1, $exit);
        self::assertSame('restore.catalog_unavailable', $stuck['error']['code']);
        [, $notClean] = $this->restore($source->uuid, 'database', ['--clean-host' => true]);
        self::assertSame('restore.clean_host_refused', $notClean['error']['code']);
        $this->migrateCatalog();

        // (4) Killed right after a correct import: reconciliation PROVES completion
        //     from the journal and the real schema fingerprint.
        $this->faults->crashAt('db.import_completed');
        [$exit, $fourth] = $this->restore($source->uuid, 'database');
        self::assertSame(3, $exit, (string) json_encode($fourth));
        $this->faults->clear();
        $output = new BufferedOutput;
        self::assertSame(0, Artisan::call('quraba:backup:restore-reconcile', ['--restore' => $fourth['restore_uuid'], '--json' => true], $output));
        $proven = json_decode($output->fetch(), true);
        self::assertSame('completed', $proven['outcome'], (string) json_encode($proven));
        self::assertTrue($proven['evidence']['database']['proven_final']);
        self::assertContains($proven['audit'], ['recreated', 'repaired'], 'The audit row is rebuilt from the journal.');
        $this->assertDatabaseIsStateA();
        self::assertSame(RestoreStatus::Completed, RestoreRun::query()->where('uuid', $fourth['restore_uuid'])->firstOrFail()->status);
    }

    public function test_the_real_target_proof_refuses_system_schemas_wrong_databases_and_the_scratch_database(): void
    {
        $this->seedStateA();
        $replacement = $this->app->make(DatabaseReplacement::class);
        self::assertInstanceOf(ExactDatabaseReplacement::class, $replacement);

        $target = $replacement->target();
        self::assertSame('it_mysql', $target->connection);
        self::assertSame(strtolower($this->database), strtolower($target->database));
        $inventory = $replacement->inventory($target);
        self::assertSame(['app_audit', 'app_notes'], array_values(array_filter(array_map('strtolower', $inventory->names(SchemaInventory::TABLE)), static fn (string $t): bool => str_starts_with($t, 'app_'))));
        self::assertSame(['app_notes_view'], array_map('strtolower', $inventory->names(SchemaInventory::VIEW)));
        self::assertSame(['app_note_count'], array_map('strtolower', $inventory->names(SchemaInventory::PROCEDURE)));
        self::assertSame(['app_notes_audit'], array_map('strtolower', $inventory->names(SchemaInventory::TRIGGER)));
        self::assertSame($inventory->fingerprint(), $replacement->inventory($target)->fingerprint(), 'The inventory is deterministic.');

        $connection = $this->config()->get('database.connections.it_mysql');
        $expect = function (array $overrides, string $fragment) use ($connection, $replacement): void {
            $this->config()->set('database.connections.it_mysql', [...$connection, ...$overrides]);
            DB::purge('it_mysql');
            try {
                $replacement->target();
                self::fail('An unsafe target was accepted: '.$fragment);
            } catch (RestoreFailed $exception) {
                self::assertSame('restore.database_target_unsafe', $exception->failureCode());
                self::assertStringContainsString($fragment, $exception->getMessage());
            } finally {
                $this->config()->set('database.connections.it_mysql', $connection);
                DB::purge('it_mysql');
            }
        };

        // System schemas are never a target.
        foreach (['mysql', 'information_schema', 'performance_schema'] as $system) {
            $expect(['database' => $system], 'system schema');
        }

        // The scratch validation database is never the target.
        $this->config()->set('database.connections.it_scratch', $connection);
        $this->config()->set('quraba-backup.restore.scratch_connection', 'it_scratch');
        $expect([], 'scratch connection');
        $this->config()->set('quraba-backup.restore.scratch_connection', 'it_mysql');
        $expect([], 'scratch connection');
        $this->config()->set('quraba-backup.restore.scratch_connection', null);

        // The target proven at preflight must still be the target later.
        $other = $this->createDatabase('other');
        $this->config()->set('database.connections.it_mysql', [...$connection, 'database' => $other]);
        DB::purge('it_mysql');
        foreach ([fn () => $replacement->inventory($target), fn () => $replacement->clear($target, $inventory, static function (): void {}), fn () => $replacement->fingerprint($target)] as $operation) {
            try {
                $operation();
                self::fail('A changed target was accepted.');
            } catch (RestoreFailed $exception) {
                self::assertSame('restore.database_target_unsafe', $exception->failureCode());
                self::assertStringContainsString('changed since it was proven', $exception->getMessage());
            }
        }
        $this->config()->set('database.connections.it_mysql', $connection);
        DB::purge('it_mysql');
        self::assertContains('app_notes', $this->objects($this->database)['tables'], 'Nothing was dropped anywhere.');
    }

    /**
     * @return array{0: string, 1: string} scratch database and its confined user
     */
    private function scratchDatabase(string $privileges): array
    {
        $scratch = $this->createDatabase('scratch');
        $user = 'quraba_it_'.bin2hex(random_bytes(5));
        $password = bin2hex(random_bytes(16));
        $this->users[] = $user;
        $this->admin->exec(sprintf("CREATE USER '%s'@'%%' IDENTIFIED BY '%s'", $user, $password));
        $this->admin->exec(sprintf("GRANT %s ON `%s`.* TO '%s'@'%%'", $privileges, str_replace('_', '\\_', $scratch), $user));
        $connection = $this->config()->get('database.connections.it_mysql');
        $this->config()->set('database.connections.it_scratch', [...$connection, 'database' => $scratch, 'username' => $user, 'password' => $password]);
        $this->config()->set('quraba-backup.restore.scratch_connection', 'it_scratch');
        $this->config()->set('quraba-backup.restore.db_validation_level', 'scratch_import');

        return [$scratch, $user];
    }

    public function test_scratch_cleanup_failure_after_a_successful_import_fails_the_dry_run_and_poisons_the_scratch_database(): void
    {
        $source = $this->recoveryPointOfStateA();
        [$scratch] = $this->scratchDatabase('ALL PRIVILEGES');
        $liveTables = $this->objects($this->database);

        // A clean scratch validation first: it imports, compares and empties.
        [$exit, $clean] = $this->restore($source->uuid, 'database', live: false);
        self::assertSame(0, $exit, (string) json_encode($clean));
        self::assertFalse($clean['scratch_contaminated']);
        self::assertSame([], $this->admin->query(sprintf('SHOW TABLES FROM `%s`', $scratch))->fetchAll());

        // Now the cleanup fails right after a successful import.
        $this->faults->crashAt('scratch.cleanup_starting');
        [$exit, $report] = $this->restore($source->uuid, 'database', live: false);
        self::assertSame(1, $exit, 'A dry run can never report success when the scratch database was left dirty.');
        self::assertFalse($report['ok']);
        self::assertSame('restore.scratch_cleanup_failed', $report['error']['code'], 'The failure code identifies the scratch cleanup.');
        self::assertTrue($report['scratch_contaminated']);
        self::assertStringContainsString('after a successful import', $report['error']['message']);
        self::assertStringContainsString('still holds', $report['error']['message']);
        self::assertStringContainsString('live application database was not touched', $report['error']['message']);
        self::assertSame('Nothing was changed in the live application.', $report['notice']);
        self::assertNotSame([], $this->admin->query(sprintf('SHOW TABLES FROM `%s`', $scratch))->fetchAll(), 'The scratch database really is contaminated.');
        $audit = RestoreRun::query()->where('uuid', $report['restore_run_uuid'])->firstOrFail();
        self::assertSame(RestoreStatus::Failed, $audit->status);
        self::assertSame('restore.scratch_cleanup_failed', $audit->failure_code);
        self::assertTrue($audit->metadata['scratch_contaminated']);

        // The live application is untouched.
        self::assertSame($liveTables, $this->objects($this->database));
        $this->assertDatabaseIsStateB();

        // The next validation refuses the dirty scratch database; nothing
        // broader is attempted to "fix" it automatically.
        $this->faults->clear();
        [$exit, $next] = $this->restore($source->uuid, 'database', live: false);
        self::assertSame(1, $exit);
        self::assertSame('restore.scratch_unsafe', $next['error']['code']);
        self::assertStringContainsString('not empty', $next['error']['message']);
        self::assertNotSame([], $this->admin->query(sprintf('SHOW TABLES FROM `%s`', $scratch))->fetchAll(), 'It is not cleaned behind the operator\'s back.');

        // A live restore with scratch validation is refused for the same reason — before any change.
        [$exit, $live] = $this->restore($source->uuid, 'database');
        self::assertSame(1, $exit);
        self::assertSame('failed', $live['status']);
        self::assertSame('restore.scratch_unsafe', $live['error']['code']);
        $this->assertDatabaseIsStateB();
    }

    public function test_scratch_cleanup_failure_after_a_failed_import_is_reported_together_with_the_import_failure(): void
    {
        $source = $this->recoveryPointOfStateA();
        // The scratch account may create tables but not triggers, so the
        // import fails after it has already created objects.
        [$scratch] = $this->scratchDatabase('SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES, LOCK TABLES, CREATE VIEW, SHOW VIEW, CREATE ROUTINE, ALTER ROUTINE, EXECUTE');
        $this->faults->crashAt('scratch.cleanup_starting');

        [$exit, $report] = $this->restore($source->uuid, 'database', live: false);
        self::assertSame(1, $exit);
        self::assertSame('restore.scratch_cleanup_failed', $report['error']['code'], 'The cleanup failure is not hidden behind the import failure.');
        self::assertStringContainsString('after a failed import', $report['error']['message']);
        self::assertStringContainsString('The import itself also failed', $report['error']['message']);
        self::assertTrue($report['scratch_contaminated']);
        self::assertNotSame([], $this->admin->query(sprintf('SHOW TABLES FROM `%s`', $scratch))->fetchAll());
        $this->assertDatabaseIsStateB();

        // Without the cleanup fault a failed import is reported as itself
        // and the scratch database is emptied again … once it is empty.
        $this->faults->clear();
        [, $dirty] = $this->restore($source->uuid, 'database', live: false);
        self::assertSame('restore.scratch_unsafe', $dirty['error']['code']);
        foreach ($this->admin->query(sprintf('SHOW FULL TABLES FROM `%s`', $scratch))->fetchAll(PDO::FETCH_NUM) as [$name, $type]) {
            $this->admin->exec(sprintf('SET FOREIGN_KEY_CHECKS=0; DROP %s `%s`.`%s`', $type === 'VIEW' ? 'VIEW' : 'TABLE', $scratch, $name));
        }
        foreach ($this->admin->query(sprintf("SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = '%s'", $scratch))->fetchAll(PDO::FETCH_COLUMN) as $routine) {
            $this->admin->exec(sprintf('DROP PROCEDURE `%s`.`%s`', $scratch, $routine));
        }
        DB::purge('it_scratch');
        [$exit, $failed] = $this->restore($source->uuid, 'database', live: false);
        self::assertSame(1, $exit);
        self::assertSame('restore.scratch_import_failed', $failed['error']['code']);
        self::assertNotTrue($failed['scratch_contaminated']);
        self::assertSame([], $this->admin->query(sprintf('SHOW TABLES FROM `%s`', $scratch))->fetchAll(), 'A failed import is still cleaned up.');
    }
}
