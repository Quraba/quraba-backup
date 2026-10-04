<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Integration;

use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use Quraba\Backup\Archive\ArchiveRequest;
use Quraba\Backup\Archive\ArchiveVerifier;
use Quraba\Backup\Archive\Database\MySqlDatabaseDumper;
use Quraba\Backup\Contracts\ArchiveEngine;
use Quraba\Backup\Contracts\DatabaseDumper;
use Quraba\Backup\Database\DumpSchemaFingerprinter;
use Quraba\Backup\Database\ServerFlavor;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Exceptions\RestoreFailed;
use Quraba\Backup\Identity\IdentityResolver;
use Quraba\Backup\Restore\DbValidationLevel;
use Quraba\Backup\Restore\RestoreDatabaseValidator;
use Quraba\Backup\Tests\Support\Sentinels;
use Quraba\Backup\Tests\TestCase;
use Quraba\Backup\Workspace\WorkspaceArea;
use Quraba\Backup\Workspace\WorkspaceManager;
use ZipArchive;

/**
 * Real mariadb-dump/mysqldump through the argument-array executor.
 *
 * Opt-in: QURABA_BACKUP_TEST_MYSQL_HOST (and optionally _PORT, _USERNAME,
 * _PASSWORD). The test creates its own uniquely named database
 * (quraba_backup_it_*) and drops only that database afterwards.
 */
#[Group('database-integration')]
final class RealDatabaseArchiveTest extends TestCase
{
    private string $database;

    private ?string $temporaryUser = null;

    private ?string $scratchDatabase = null;

    private PDO $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('QURABA_BACKUP_TEST_MYSQL_HOST');

        if (! is_string($host) || $host === '') {
            self::markTestSkipped('Set QURABA_BACKUP_TEST_MYSQL_HOST to run the real MySQL/MariaDB dump test.');
        }

        $port = (int) (getenv('QURABA_BACKUP_TEST_MYSQL_PORT') ?: 3306);
        $user = (string) (getenv('QURABA_BACKUP_TEST_MYSQL_USERNAME') ?: 'root');
        $password = (string) (getenv('QURABA_BACKUP_TEST_MYSQL_PASSWORD') ?: '');

        $this->database = 'quraba_backup_it_'.bin2hex(random_bytes(5));
        $this->admin = new PDO(sprintf('mysql:host=%s;port=%d', $host, $port), $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->admin->exec(sprintf('CREATE DATABASE `%s` CHARACTER SET utf8mb4', $this->database));

        $this->config()->set('database.connections.it_mysql', [
            'driver' => 'mysql', 'host' => $host, 'port' => $port, 'database' => $this->database,
            'username' => $user, 'password' => $password, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '',
        ]);
        $this->config()->set('quraba-backup.database.connection', 'it_mysql');

        DB::connection('it_mysql')->statement('CREATE TABLE orders (id INT PRIMARY KEY, note VARCHAR(100)) ENGINE=InnoDB');
        DB::connection('it_mysql')->table('orders')->insert(['id' => 1, 'note' => 'first-order-row']);

        foreach ([DatabaseDumper::class, ArchiveEngine::class] as $service) {
            $this->app->forgetInstance($service);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->admin)) {
            DB::disconnect('it_mysql');
            if ($this->temporaryUser !== null) {
                $this->admin->exec(sprintf("DROP USER IF EXISTS '%s'@'%%'", $this->temporaryUser));
            }
            if ($this->scratchDatabase !== null) {
                DB::disconnect('it_scratch');
                $this->admin->exec(sprintf('DROP DATABASE IF EXISTS `%s`', $this->scratchDatabase));
            }
            $this->admin->exec(sprintf('DROP DATABASE IF EXISTS `%s`', $this->database));
        }

        parent::tearDown();
    }

    public function test_real_dump_is_archived_encrypted_and_verified(): void
    {
        DB::connection('it_mysql')->statement('CREATE VIEW order_notes AS SELECT id, note FROM orders');
        DB::connection('it_mysql')->statement('CREATE TRIGGER orders_note_before_insert BEFORE INSERT ON orders FOR EACH ROW SET NEW.note = COALESCE(NEW.note, \'missing\')');
        DB::connection('it_mysql')->statement('CREATE PROCEDURE count_orders() SELECT COUNT(*) FROM orders');
        DB::connection('it_mysql')->statement('CREATE EVENT backup_event_probe ON SCHEDULE AT CURRENT_TIMESTAMP + INTERVAL 1 DAY DO INSERT INTO orders (id, note) VALUES (999, \'event\')');
        self::assertTrue($this->app->make(MySqlDatabaseDumper::class)->hasEventPrivilege('it_mysql', $this->database));
        $run = '5ff081a8-503e-44ba-91ae-30cfef9b972f';
        $identity = $this->app->make(IdentityResolver::class)->current();
        $workspace = $this->app->make(WorkspaceManager::class)->create();

        try {
            $created = $this->app->make(ArchiveEngine::class)->create(new ArchiveRequest($run, BackupProfile::Database, $identity, $workspace, 'it_mysql', $this->sandbox.'/.env', 'test', Sentinels::ARCHIVE_PASSWORD));
            $verified = $this->app->make(ArchiveVerifier::class)->verify($created->path, Sentinels::ARCHIVE_PASSWORD, $run, $identity);

            self::assertContains($verified->metadata['database']['flavor'], ['mysql', 'mariadb']);
            self::assertNotSame('', $verified->metadata['database']['server_version']);
            self::assertNotSame('', $verified->metadata['database']['dump_tool_version']);
            $expectedFlavor = getenv('QURABA_BACKUP_TEST_EXPECT_FLAVOR');
            if (is_string($expectedFlavor) && $expectedFlavor !== '') {
                self::assertSame($expectedFlavor, $verified->metadata['database']['flavor']);
            }
            self::assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', (string) $verified->metadata['database']['schema_fingerprint']);
            self::assertTrue($verified->metadata['database']['events_included']);
            self::assertSame(['tables' => true, 'views' => true, 'triggers' => true, 'routines' => true], $verified->metadata['database']['object_privileges_proven']);
            self::assertSame($verified->metadata['database']['flavor'] !== 'mariadb' || ServerFlavor::fromVersionString((string) $verified->metadata['database']['dump_tool_version']) === ServerFlavor::MariaDb, $verified->metadata['database']['exact_object_completeness']);

            $zip = new ZipArchive;
            $zip->open($created->path);
            $zip->setPassword(Sentinels::ARCHIVE_PASSWORD);
            $sql = (string) $zip->getFromName('database/database.sql');
            $zip->close();

            self::assertStringContainsString('CREATE TABLE `orders`', $sql);
            self::assertStringContainsString('first-order-row', $sql);
            foreach (['order_notes', 'orders_note_before_insert', 'count_orders', 'backup_event_probe'] as $object) {
                self::assertStringContainsString($object, $sql);
            }
            self::assertSame([], glob($workspace->root().'/database/*.cnf') ?: [], 'The credentials option file is removed immediately.');
        } finally {
            $workspace->cleanup();
        }
    }

    public function test_special_character_credentials_are_parsed_by_the_real_server_client(): void
    {
        $this->temporaryUser = 'quraba_it_'.bin2hex(random_bytes(5));
        $password = "slash\\ quote\" apostrophe' space\t#;";
        $quotedPassword = $this->admin->quote($password);
        self::assertIsString($quotedPassword);
        $this->admin->exec(sprintf("CREATE USER '%s'@'%%' IDENTIFIED BY %s", $this->temporaryUser, $quotedPassword));
        $this->admin->exec(sprintf("GRANT ALL PRIVILEGES ON `%s`.* TO '%s'@'%%'", $this->database, $this->temporaryUser));

        $connection = $this->config()->get('database.connections.it_mysql');
        self::assertIsArray($connection);
        $connection['username'] = $this->temporaryUser;
        $connection['password'] = $password;
        $this->config()->set('database.connections.it_mysql', $connection);
        DB::purge('it_mysql');
        foreach ([DatabaseDumper::class, ArchiveEngine::class] as $service) {
            $this->app->forgetInstance($service);
        }

        $workspace = $this->app->make(WorkspaceManager::class)->create();
        try {
            $run = '2b2f989d-476f-4dce-baea-348d82f9e45f';
            $identity = $this->app->make(IdentityResolver::class)->current();
            $created = $this->app->make(ArchiveEngine::class)->create(new ArchiveRequest($run, BackupProfile::Database, $identity, $workspace, 'it_mysql', $this->sandbox.'/.env', 'test', Sentinels::ARCHIVE_PASSWORD));
            $verified = $this->app->make(ArchiveVerifier::class)->verify($created->path, Sentinels::ARCHIVE_PASSWORD, $run, $identity);
            self::assertSame('aes256', $verified->encryption);
            self::assertSame([], glob($workspace->root().'/database/*.cnf') ?: []);
        } finally {
            $workspace->cleanup();
        }
    }

    public function test_real_scratch_import_proves_distinct_database_and_schema_then_cleans_it(): void
    {
        $this->scratchDatabase = 'quraba_scratch_it_'.bin2hex(random_bytes(5));
        $this->admin->exec(sprintf('CREATE DATABASE `%s` CHARACTER SET utf8mb4', $this->scratchDatabase));
        $this->temporaryUser = 'quraba_scratch_'.bin2hex(random_bytes(5));
        $scratchPassword = bin2hex(random_bytes(16));
        $this->admin->exec(sprintf("CREATE USER '%s'@'%%' IDENTIFIED BY '%s'", $this->temporaryUser, $scratchPassword));
        $this->admin->exec(sprintf("GRANT ALL PRIVILEGES ON `%s`.* TO '%s'@'%%'", str_replace('_', '\\_', $this->scratchDatabase), $this->temporaryUser));
        $scratchConnection = $this->config()->get('database.connections.it_mysql');
        self::assertIsArray($scratchConnection);
        $scratchConnection['database'] = $this->scratchDatabase;
        $scratchConnection['username'] = $this->temporaryUser;
        $scratchConnection['password'] = $scratchPassword;
        $this->config()->set('database.connections.it_scratch', $scratchConnection);
        $this->config()->set('quraba-backup.restore.scratch_connection', 'it_scratch');

        $workspace = $this->app->make(WorkspaceManager::class)->create();
        try {
            $run = 'e177f90f-992a-4a6c-8e13-b654f38cfcc8';
            $identity = $this->app->make(IdentityResolver::class)->current();
            $created = $this->app->make(ArchiveEngine::class)->create(new ArchiveRequest($run, BackupProfile::Database, $identity, $workspace, 'it_mysql', $this->sandbox.'/.env', 'test', Sentinels::ARCHIVE_PASSWORD));
            $zip = new ZipArchive;
            self::assertTrue($zip->open($created->path));
            $zip->setPassword(Sentinels::ARCHIVE_PASSWORD);
            $dump = $workspace->path(WorkspaceArea::Restore, 'scratch.sql');
            file_put_contents($dump, (string) $zip->getFromName('database/database.sql'));
            $zip->close();

            $validation = $this->app->make(RestoreDatabaseValidator::class)->validate($dump, $created->metadata, DbValidationLevel::ScratchImport, $workspace);
            self::assertSame('scratch_import', $validation['level']);
            self::assertSame($created->metadata['database']['schema_fingerprint'], $validation['scratch_schema_fingerprint']);
            self::assertSame([], $this->admin->query(sprintf('SHOW TABLES FROM `%s`', $this->scratchDatabase))->fetchAll());

            file_put_contents($dump, "\nCREATE PROCEDURE scratch_cleanup_probe() SELECT 1;\n", FILE_APPEND);
            $routineMetadata = $created->metadata;
            $routineMetadata['database']['dump_bytes'] = filesize($dump);
            $routineMetadata['database']['dump_schema_fingerprint'] = DumpSchemaFingerprinter::fingerprint($dump);
            $this->app->make(RestoreDatabaseValidator::class)->validate($dump, $routineMetadata, DbValidationLevel::ScratchImport, $workspace);
            $routines = $this->admin->query(sprintf("SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = '%s'", $this->scratchDatabase))->fetchAll();
            self::assertSame([], $routines, 'Scratch cleanup must remove imported routines.');

            $escapedProduction = str_replace('_', '\\_', $this->database);
            $this->admin->exec(sprintf("GRANT SELECT ON `%s`.* TO '%s'@'%%'", $escapedProduction, $this->temporaryUser));
            DB::purge('it_scratch');
            try {
                $this->app->make(RestoreDatabaseValidator::class)->validate($dump, $routineMetadata, DbValidationLevel::ScratchImport, $workspace);
                self::fail('A scratch account with production grants must be refused.');
            } catch (RestoreFailed $exception) {
                self::assertSame('restore.scratch_unsafe', $exception->failureCode());
            } finally {
                $this->admin->exec(sprintf("REVOKE SELECT ON `%s`.* FROM '%s'@'%%'", $escapedProduction, $this->temporaryUser));
                DB::purge('it_scratch');
            }

            $this->config()->set('quraba-backup.restore.scratch_connection', 'it_mysql');
            $this->expectException(RestoreFailed::class);
            $this->app->make(RestoreDatabaseValidator::class)->validate($dump, $routineMetadata, DbValidationLevel::ScratchImport, $workspace);
        } finally {
            $workspace->cleanup();
        }
    }
}
