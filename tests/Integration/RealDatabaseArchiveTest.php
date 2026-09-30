<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Integration;

use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use Quraba\Backup\Archive\ArchiveRequest;
use Quraba\Backup\Archive\ArchiveVerifier;
use Quraba\Backup\Contracts\ArchiveEngine;
use Quraba\Backup\Contracts\DatabaseDumper;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Identity\IdentityResolver;
use Quraba\Backup\Tests\Support\Sentinels;
use Quraba\Backup\Tests\TestCase;
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
            $this->admin->exec(sprintf('DROP DATABASE IF EXISTS `%s`', $this->database));
        }

        parent::tearDown();
    }

    public function test_real_dump_is_archived_encrypted_and_verified(): void
    {
        $run = '5ff081a8-503e-44ba-91ae-30cfef9b972f';
        $identity = $this->app->make(IdentityResolver::class)->current();
        $workspace = $this->app->make(WorkspaceManager::class)->create();

        try {
            $created = $this->app->make(ArchiveEngine::class)->create(new ArchiveRequest($run, BackupProfile::Database, $identity, $workspace, 'it_mysql', $this->sandbox.'/.env', 'test', Sentinels::ARCHIVE_PASSWORD));
            $verified = $this->app->make(ArchiveVerifier::class)->verify($created->path, Sentinels::ARCHIVE_PASSWORD, $run, $identity);

            self::assertContains($verified->metadata['database']['flavor'], ['mysql', 'mariadb']);
            self::assertNotSame('', $verified->metadata['database']['server_version']);
            self::assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', (string) $verified->metadata['database']['schema_fingerprint']);

            $zip = new ZipArchive;
            $zip->open($created->path);
            $zip->setPassword(Sentinels::ARCHIVE_PASSWORD);
            $sql = (string) $zip->getFromName('database/database.sql');
            $zip->close();

            self::assertStringContainsString('CREATE TABLE `orders`', $sql);
            self::assertStringContainsString('first-order-row', $sql);
            self::assertSame([], glob($workspace->root().'/database/*.cnf') ?: [], 'The credentials option file is removed immediately.');
        } finally {
            $workspace->cleanup();
        }
    }
}
