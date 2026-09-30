<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Orchestra\Testbench\TestCase as Orchestra;
use Quraba\Backup\Identity\IdentityResolver;
use Quraba\Backup\QurabaBackupServiceProvider;
use Quraba\Backup\Security\SecretRedactor;
use Quraba\Backup\Storage\FlysystemObjectStorage;
use Quraba\Backup\Storage\RemoteLayout;
use Quraba\Backup\Storage\RemoteStorage;
use Quraba\Backup\Tests\Support\CapturingLogger;
use Quraba\Backup\Tests\Support\Sentinels;
use Symfony\Component\Uid\Ulid;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected const string APP_ID = '6f614a0b-c447-4e36-9758-347858cbb46b';

    protected string $sandbox;

    protected CapturingLogger $logs;

    protected function setUp(): void
    {
        $this->sandbox = self::sandboxRoot().'/'.(string) new Ulid;
        mkdir($this->sandbox.'/secrets', 0700, true);
        file_put_contents($this->sandbox.'/secrets/restic-password', Sentinels::RESTIC_PASSWORD."\n");
        @chmod($this->sandbox.'/secrets/restic-password', 0600);

        parent::setUp();

        $this->logs = new CapturingLogger;
        $this->app->instance(QurabaBackupServiceProvider::LOGGER, $this->logs);
        $this->useLocalObjectStorage();

        // The suite never touches the network: B2 answers like an
        // unauthenticated S3 endpoint, and any other request fails the test.
        Http::preventStrayRequests();
        Http::fake(['s3.us-west-004.backblazeb2.com/*' => Http::response('<Error><Code>AccessDenied</Code></Error>', 403)]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        self::removeTree($this->sandbox);
    }

    protected function getPackageProviders($app): array
    {
        return [QurabaBackupServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        /** @var Repository $config */
        $config = $app->make('config');

        $config->set('app.key', Sentinels::APP_KEY);
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
            'password' => Sentinels::DB_PASSWORD,
        ]);

        $config->set('quraba-backup.app_id', self::APP_ID);
        $config->set('quraba-backup.environment', 'testing');
        $config->set('quraba-backup.paths.root', $this->sandbox.'/private');
        $config->set('quraba-backup.storage.b2.endpoint', 'https://s3.us-west-004.backblazeb2.com');
        $config->set('quraba-backup.storage.b2.bucket', 'quraba-test-bucket');
        $config->set('quraba-backup.storage.b2.key_id', Sentinels::B2_KEY_ID);
        $config->set('quraba-backup.storage.b2.application_key', Sentinels::B2_SECRET);
        $config->set('quraba-backup.archive.password', Sentinels::ARCHIVE_PASSWORD);

        $config->set('restic.managed_binary', $this->sandbox.'/private/bin/restic');
        $config->set('restic.password_file', $this->sandbox.'/secrets/restic-password');
        $config->set('restic.media.roots', []);

        // A sandboxed .env holding sentinel secrets; it is what gets archived.
        file_put_contents($this->sandbox.'/.env', implode("\n", [
            'APP_KEY='.Sentinels::APP_KEY,
            'DB_PASSWORD='.Sentinels::DB_PASSWORD,
            'QURABA_BACKUP_B2_APPLICATION_KEY='.Sentinels::B2_SECRET,
        ])."\n");
        $app->useEnvironmentPath($this->sandbox);
    }

    /**
     * Replaces Backblaze B2 with a local Flysystem directory: the suite never
     * needs network access or credentials.
     */
    protected function useLocalObjectStorage(?FilesystemOperator $filesystem = null): void
    {
        $filesystem ??= new Filesystem(new LocalFilesystemAdapter($this->sandbox.'/b2'));
        $config = $this->config();
        $redactor = $this->app->make(SecretRedactor::class);

        $this->app->instance(RemoteStorage::class, new RemoteStorage(
            $config,
            $this->app->make(IdentityResolver::class),
            static fn (RemoteLayout $layout): FlysystemObjectStorage => new FlysystemObjectStorage($filesystem, [$layout->resticRoot()], 'local-test-bucket', $redactor),
        ));
    }

    protected function bucketPath(string $locator): string
    {
        return $this->sandbox.'/b2/'.$locator;
    }

    protected static function sandboxRoot(): string
    {
        $root = str_replace('\\', '/', sys_get_temp_dir()).'/quraba-backup-tests';

        if (! is_dir($root)) {
            mkdir($root, 0700, true);
        }

        return (string) realpath($root) === '' ? $root : str_replace('\\', '/', (string) realpath($root));
    }

    /**
     * Test-only recursive removal restricted to the sandbox root.
     */
    protected static function removeTree(string $path): void
    {
        $root = self::sandboxRoot();

        if (! str_starts_with(str_replace('\\', '/', $path), $root.'/')) {
            return;
        }

        if (is_link($path) || is_file($path)) {
            @chmod($path, 0600);
            @unlink($path) || @rmdir($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path.'/'.$entry);
            }
        }

        @rmdir($path);
    }

    protected function config(): Repository
    {
        return $this->app->make('config');
    }
}
