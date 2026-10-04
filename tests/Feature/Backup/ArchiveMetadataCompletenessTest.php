<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Feature\Backup;

use PHPUnit\Framework\Attributes\DataProvider;
use Quraba\Backup\Archive\ArchiveMetadata;
use Quraba\Backup\Archive\ArchiveRequest;
use Quraba\Backup\Archive\Database\DatabaseDump;
use Quraba\Backup\Database\ServerFlavor;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Identity\IdentityResolver;
use Quraba\Backup\Tests\Support\Sentinels;
use Quraba\Backup\Tests\TestCase;
use Quraba\Backup\Workspace\WorkspaceManager;

final class ArchiveMetadataCompletenessTest extends TestCase
{
    /**
     * @return iterable<string, array{ServerFlavor, string, string, bool, string}>
     */
    public static function dumpTools(): iterable
    {
        yield 'MariaDB named tool' => [ServerFlavor::MariaDb, 'mariadb-dump', 'mariadb-dump from 11.4.2-MariaDB', true, 'dumped_as_tables'];
        yield 'MariaDB Windows alternate name' => [ServerFlavor::MariaDb, 'mysqldump.EXE', 'mysqldump.EXE Ver 10.19 Distrib 10.4.32-MariaDB, for Win64 (AMD64)', true, 'dumped_as_tables'];
        yield 'MySQL tool against MariaDB' => [ServerFlavor::MariaDb, 'mysqldump', 'mysqldump Ver 8.4.2 for Linux on x86_64', false, 'unknown'];
        yield 'MariaDB filename with MySQL provenance' => [ServerFlavor::MariaDb, 'mariadb-dump', 'mysqldump Ver 8.4.2 for Linux on x86_64', false, 'unknown'];
        yield 'MySQL server unchanged' => [ServerFlavor::MySql, 'mysqldump', 'mysqldump Ver 8.4.2 for Linux on x86_64', true, 'not_applicable'];
    }

    #[DataProvider('dumpTools')]
    public function test_exact_completeness_uses_probed_tool_provenance(ServerFlavor $flavor, string $tool, string $toolVersion, bool $exact, string $sequences): void
    {
        $workspace = $this->app->make(WorkspaceManager::class)->create();
        $sql = 'CREATE TABLE `example` (`id` int);';
        $dumpPath = $this->sandbox.'/database.sql';
        file_put_contents($dumpPath, $sql);

        try {
            $request = new ArchiveRequest(
                '5ff081a8-503e-44ba-91ae-30cfef9b972f',
                BackupProfile::Database,
                $this->app->make(IdentityResolver::class)->current(),
                $workspace,
                'testing',
                $this->sandbox.'/.env',
                'test',
                Sentinels::ARCHIVE_PASSWORD,
            );
            $dump = new DatabaseDump(
                path: $dumpPath,
                bytes: strlen($sql),
                connection: 'testing',
                driver: 'mysql',
                database: 'testing',
                flavor: $flavor,
                serverVersion: $flavor === ServerFlavor::MariaDb ? '10.4.32-MariaDB' : '8.4.2',
                tool: $tool,
                toolVersion: $toolVersion,
                eventPolicy: 'auto',
                eventsIncluded: true,
                routinesIncluded: true,
                eventPrivilegeProven: true,
                objectPrivilegesProven: ['tables' => true, 'views' => true, 'triggers' => true, 'routines' => true],
            );

            $metadata = $this->app->make(ArchiveMetadata::class)->build($request, $dump, 'database/database.sql', false);
            self::assertSame($exact, $metadata['database']['exact_object_completeness']);
            self::assertSame($sequences, $metadata['database']['object_classes']['sequences']);
            self::assertSame($tool, $metadata['database']['dump_tool']);
            self::assertSame($toolVersion, $metadata['database']['dump_tool_version']);
        } finally {
            $workspace->cleanup();
        }
    }
}
