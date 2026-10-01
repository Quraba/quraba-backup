<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Feature;

use Quraba\Backup\Database\DatabaseToolKind;
use Quraba\Backup\Database\DatabaseToolLocator;
use Quraba\Backup\Database\ServerFlavor;
use Quraba\Backup\Exceptions\ConfigurationException;
use Quraba\Backup\Support\Process\ProcessFactory;
use Quraba\Backup\Tests\Support\ScriptAwareProcessFactory;
use Quraba\Backup\Tests\TestCase;

final class DatabaseToolLocatorTest extends TestCase
{
    private function locator(): DatabaseToolLocator
    {
        $this->app->instance(ProcessFactory::class, new ScriptAwareProcessFactory);
        $this->app->forgetInstance(DatabaseToolLocator::class);

        return $this->app->make(DatabaseToolLocator::class);
    }

    private function fakeTool(string $directory, string $name, string $version): string
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        $path = $directory.'/'.$name;
        file_put_contents($path, "#!/usr/bin/env php\n<?php\nfile_put_contents(__DIR__.'/{$name}.env.json', json_encode(getenv()));\necho '{$version}', PHP_EOL;\n");
        chmod($path, 0755);

        return $path;
    }

    public function test_explicitly_configured_tool_is_verified(): void
    {
        $path = $this->fakeTool($this->sandbox.'/tools', 'custom-dump', 'mariadb-dump from 11.4.2-MariaDB');
        $this->config()->set('quraba-backup.database.dump_binary', $path);

        $tool = $this->locator()->dumper();

        self::assertNotNull($tool);
        self::assertTrue($tool->configured);
        self::assertSame('mariadb-dump from 11.4.2-MariaDB', $tool->version);

        // Probing never hands database credentials to the tool.
        $environment = (string) file_get_contents($this->sandbox.'/tools/custom-dump.env.json');
        self::assertStringNotContainsString('sentinel-db-password', $environment);
    }

    public function test_broken_configured_tool_is_an_error_not_a_fallback(): void
    {
        $this->config()->set('quraba-backup.database.client_binary', $this->sandbox.'/nope/mysql');

        $this->expectException(ConfigurationException::class);
        $this->locator()->client();
    }

    public function test_discovery_prefers_mariadb_names_unless_the_server_is_mysql(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Executable discovery by bare name relies on POSIX executables.');
        }

        $directory = $this->sandbox.'/bin-tools';
        $this->fakeTool($directory, 'mariadb-dump', 'mariadb-dump Ver 10.11');
        $this->fakeTool($directory, 'mysqldump', 'mysqldump Ver 8.4');
        $this->config()->set('quraba-backup.database.tool_search_paths', [$directory]);
        $this->config()->set('quraba-backup.database.dump_binary', null);
        $this->config()->set('quraba-backup.database.client_binary', null);
        $originalPath = (string) getenv('PATH');
        putenv('PATH='.$directory);

        try {
            self::assertSame('mariadb-dump', $this->locator()->locate(DatabaseToolKind::Dumper, ServerFlavor::Unknown)?->name);
            self::assertSame('mariadb-dump', $this->locator()->locate(DatabaseToolKind::Dumper, ServerFlavor::MariaDb)?->name);
            self::assertSame('mysqldump', $this->locator()->locate(DatabaseToolKind::Dumper, ServerFlavor::MySql)?->name);
            self::assertNull($this->locator()->locate(DatabaseToolKind::Client, ServerFlavor::Unknown));
        } finally {
            putenv('PATH='.$originalPath);
        }
    }

    public function test_server_flavor_detection(): void
    {
        self::assertSame(ServerFlavor::MariaDb, ServerFlavor::fromVersionString('10.11.8-MariaDB-log'));
        self::assertSame(ServerFlavor::MySql, ServerFlavor::fromVersionString('8.4.2'));
        self::assertSame(ServerFlavor::Unknown, ServerFlavor::fromVersionString(''));
        self::assertSame(['mysql', 'mariadb'], DatabaseToolKind::Client->candidates(ServerFlavor::MySql));
    }
}
