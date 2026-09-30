<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Feature\Backup;

use Illuminate\Support\Facades\DB;
use Quraba\Backup\Archive\ArchiveMetadata;
use Quraba\Backup\Archive\ArchiveRequest;
use Quraba\Backup\Archive\ArchiveVerifier;
use Quraba\Backup\Archive\SpatieArchiveEngine;
use Quraba\Backup\Contracts\ArchiveEngine;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Exceptions\ArchiveCreationFailed;
use Quraba\Backup\Exceptions\ArchiveVerificationFailed;
use Quraba\Backup\Identity\IdentityResolver;
use Quraba\Backup\Tests\Support\BuildsBackups;
use Quraba\Backup\Tests\Support\Sentinels;
use Quraba\Backup\Tests\TestCase;
use Quraba\Backup\Workspace\OperationWorkspace;
use Quraba\Backup\Workspace\WorkspaceArea;
use Quraba\Backup\Workspace\WorkspaceManager;
use Spatie\Backup\Config\Config as SpatieConfig;
use ZipArchive;

final class ArchiveTest extends TestCase
{
    use BuildsBackups;

    private const string RUN = '5ff081a8-503e-44ba-91ae-30cfef9b972f';

    private OperationWorkspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareBackupPipeline();
        $this->workspace = $this->app->make(WorkspaceManager::class)->create();

        DB::table('quraba_backup_settings')->insert(['key' => 'demo.row', 'value' => json_encode(['v' => 'hello'])]);
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();

        parent::tearDown();
    }

    private function request(string $password = Sentinels::ARCHIVE_PASSWORD): ArchiveRequest
    {
        return new ArchiveRequest(self::RUN, BackupProfile::Database, $this->app->make(IdentityResolver::class)->current(), $this->workspace, 'testing', $this->sandbox.'/.env', 'test', $password);
    }

    private function engine(): ArchiveEngine
    {
        return $this->app->make(ArchiveEngine::class);
    }

    public function test_creates_an_aes256_archive_with_exactly_the_expected_contents(): void
    {
        $created = $this->engine()->create($this->request());

        $zip = new ZipArchive;
        self::assertTrue($zip->open($created->path, ZipArchive::RDONLY));

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            self::assertIsArray($stat);
            self::assertSame(ZipArchive::EM_AES_256, $stat['encryption_method'], $stat['name'].' must be AES-256 encrypted');
            $names[] = $stat['name'];
        }

        sort($names);
        self::assertSame(['.env', 'database/database.sql', 'quraba-backup.json'], $names);

        $zip->setPassword(Sentinels::ARCHIVE_PASSWORD);
        self::assertStringContainsString('demo.row', (string) $zip->getFromName('database/database.sql'));
        self::assertStringContainsString(Sentinels::DB_PASSWORD, (string) $zip->getFromName('.env'), 'The real .env is archived.');
        $zip->close();

        // Encrypted at rest: nothing from .env or the dump is visible in the archive bytes.
        $raw = (string) file_get_contents($created->path);
        self::assertStringNotContainsString(Sentinels::DB_PASSWORD, $raw);
        self::assertStringNotContainsString('demo.row', $raw);
        self::assertStringNotContainsString(Sentinels::ARCHIVE_PASSWORD, $raw);

        // Plaintext intermediates never outlive the build; .env was never copied.
        self::assertFileDoesNotExist($this->workspace->path(WorkspaceArea::Database, 'database.sql'));
        self::assertFileDoesNotExist($this->workspace->path(WorkspaceArea::Archive, 'quraba-backup.json'));
        self::assertFileDoesNotExist($this->workspace->path(WorkspaceArea::Archive, '.env'));
    }

    public function test_metadata_is_safe_and_identifies_the_run(): void
    {
        $created = $this->engine()->create($this->request());
        $metadata = $created->metadata;

        self::assertSame(ArchiveMetadata::SCHEMA_VERSION, $metadata['schema_version']);
        self::assertSame(self::RUN, $metadata['run_uuid']);
        self::assertSame(self::APP_ID, $metadata['app_id']);
        self::assertSame('testing', $metadata['environment']);
        self::assertSame('database', $metadata['profile']);
        self::assertSame(PHP_VERSION, $metadata['php_version']);
        self::assertSame('sha256:'.hash('sha256', Sentinels::APP_KEY), $metadata['app_key_fingerprint']);
        self::assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', (string) $metadata['database']['migration_fingerprint']);
        self::assertMatchesRegularExpression('/Z$/', (string) $metadata['created_at']);

        Sentinels::assertAbsent((string) json_encode($metadata), 'archive metadata');
    }

    public function test_verifier_proves_the_archive_and_computes_its_sha256(): void
    {
        $created = $this->engine()->create($this->request());
        $identity = $this->app->make(IdentityResolver::class)->current();

        $verification = $this->app->make(ArchiveVerifier::class)->verify($created->path, Sentinels::ARCHIVE_PASSWORD, self::RUN, $identity);

        self::assertSame(hash_file('sha256', $created->path), $verification->sha256);
        self::assertSame(filesize($created->path), $verification->bytes);
        self::assertSame('aes256', $verification->encryption);
        self::assertSame(self::RUN, $verification->metadata['run_uuid']);
    }

    public function test_wrong_password_is_rejected(): void
    {
        $created = $this->engine()->create($this->request());

        $this->expectException(ArchiveVerificationFailed::class);
        $this->app->make(ArchiveVerifier::class)->verify($created->path, 'not-the-password', self::RUN, $this->app->make(IdentityResolver::class)->current());
    }

    public function test_archive_of_another_run_is_rejected(): void
    {
        $created = $this->engine()->create($this->request());

        $this->expectException(ArchiveVerificationFailed::class);
        $this->expectExceptionMessage('run_uuid');
        $this->app->make(ArchiveVerifier::class)->verify($created->path, Sentinels::ARCHIVE_PASSWORD, '11111111-1111-4111-8111-111111111111', $this->app->make(IdentityResolver::class)->current());
    }

    public function test_corruption_is_rejected(): void
    {
        $created = $this->engine()->create($this->request());
        $bytes = (string) file_get_contents($created->path);
        $middle = intdiv(strlen($bytes), 2);
        $bytes[$middle] = chr(ord($bytes[$middle]) ^ 0xFF);
        $bytes[$middle + 1] = chr(ord($bytes[$middle + 1]) ^ 0xFF);
        file_put_contents($created->path, $bytes);

        $this->expectException(ArchiveVerificationFailed::class);
        $this->app->make(ArchiveVerifier::class)->verify($created->path, Sentinels::ARCHIVE_PASSWORD, self::RUN, $this->app->make(IdentityResolver::class)->current());
    }

    public function test_unencrypted_archives_are_rejected(): void
    {
        $path = $this->workspace->path(WorkspaceArea::Archive, 'plain.zip');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('database/database.sql', 'x');
        $zip->addFromString('.env', 'x');
        $zip->addFromString('quraba-backup.json', '{}');
        $zip->close();

        $this->expectException(ArchiveVerificationFailed::class);
        $this->expectExceptionMessage('not AES-256');
        $this->app->make(ArchiveVerifier::class)->verify($path, Sentinels::ARCHIVE_PASSWORD, self::RUN, $this->app->make(IdentityResolver::class)->current());
    }

    public function test_missing_password_is_refused_before_anything_is_created(): void
    {
        foreach (['', '   '] as $password) {
            try {
                $this->engine()->create($this->request($password));
                self::fail('A blank password must be refused.');
            } catch (ArchiveCreationFailed $exception) {
                self::assertSame('archive.password_missing', $exception->failureCode());
            }
        }

        self::assertSame(0, $this->dumper->dumps, 'Nothing may be dumped without an archive password.');
        self::assertSame(['.', '..'], scandir($this->workspace->area(WorkspaceArea::Archive)));
    }

    public function test_spatie_configuration_never_leaks_the_password(): void
    {
        $this->config()->set('backup', ['backup' => ['name' => 'host-app', 'password' => null, 'source' => ['files' => ['include' => [], 'exclude' => []], 'databases' => []], 'destination' => ['disks' => ['local']]]]);

        $this->engine()->create($this->request());

        self::assertNull($this->config()->get('backup.backup.password'), 'The host\'s own Spatie config is never modified.');
        self::assertFalse($this->app->resolved(SpatieConfig::class) && ($this->app->make(SpatieConfig::class)->backup->password === Sentinels::ARCHIVE_PASSWORD));
    }

    public function test_engine_reports_strong_encryption_support(): void
    {
        self::assertTrue($this->engine()->supportsStrongEncryption());
        self::assertTrue(SpatieArchiveEngine::isInstalled());
    }
}
