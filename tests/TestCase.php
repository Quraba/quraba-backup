<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;
use Quraba\Backup\QurabaBackupServiceProvider;
use Quraba\Backup\Tests\Support\CapturingLogger;
use Quraba\Backup\Tests\Support\Sentinels;
use Symfony\Component\Uid\Ulid;

abstract class TestCase extends Orchestra
{
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
