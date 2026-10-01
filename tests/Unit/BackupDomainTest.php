<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Unit;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quraba\Backup\Archive\Database\ArgvMySqlDumper;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Exceptions\ConfigurationException;
use Quraba\Backup\Identity\ApplicationIdentity;
use Quraba\Backup\Identity\IdentitySource;
use Quraba\Backup\Manifest\ManifestBuilder;
use Quraba\Backup\Restic\ResticSnapshot;
use Quraba\Backup\Restic\SnapshotIdentity;
use Quraba\Backup\Restic\SnapshotKind;
use Quraba\Backup\Scheduling\ScheduleDefinition;
use Quraba\Backup\Storage\RemoteLayout;
use Quraba\Backup\Support\Process\SymfonyProcessFactory;

final class BackupDomainTest extends TestCase
{
    private const string APP = '6f614a0b-c447-4e36-9758-347858cbb46b';

    private const string RUN = '5ff081a8-503e-44ba-91ae-30cfef9b972f';

    private function identity(string $environment = 'production'): ApplicationIdentity
    {
        return new ApplicationIdentity(self::APP, $environment, IdentitySource::PackageConfiguration);
    }

    public function test_snapshot_identity_tags_are_central_and_complete(): void
    {
        $identity = SnapshotIdentity::for($this->identity(), SnapshotKind::RecoveryMedia, self::RUN);

        self::assertSame([
            'quraba-backup',
            'app:'.self::APP,
            'env:production',
            'kind:recovery_media',
            'run:'.self::RUN,
        ], $identity->tags());
        self::assertSame(['quraba-backup', 'run:'.self::RUN], $identity->runSelector());
        self::assertSame('safety_media', SnapshotKind::SafetyMedia->value, 'reserved for the restore phase');
    }

    /**
     * @return iterable<string, array{list<string>, bool}>
     */
    public static function snapshotTagSets(): iterable
    {
        $base = ['quraba-backup', 'app:'.self::APP, 'env:production', 'kind:media', 'run:'.self::RUN];

        yield 'exact identity' => [$base, true];
        yield 'extra unrelated tag' => [[...$base, 'note:x'], true];
        yield 'missing marker' => [array_slice($base, 1), false];
        yield 'wrong app' => [['quraba-backup', 'app:11111111-1111-4111-8111-111111111111', 'env:production', 'kind:media', 'run:'.self::RUN], false];
        yield 'wrong environment' => [['quraba-backup', 'app:'.self::APP, 'env:staging', 'kind:media', 'run:'.self::RUN], false];
        yield 'wrong kind' => [['quraba-backup', 'app:'.self::APP, 'env:production', 'kind:recovery_media', 'run:'.self::RUN], false];
        yield 'wrong run' => [['quraba-backup', 'app:'.self::APP, 'env:production', 'kind:media', 'run:22222222-2222-4222-8222-222222222222'], false];
        yield 'claims two runs' => [[...$base, 'run:22222222-2222-4222-8222-222222222222'], false];
        yield 'claims two environments' => [[...$base, 'env:staging'], false];
    }

    /**
     * @param  list<string>  $tags
     */
    #[DataProvider('snapshotTagSets')]
    public function test_snapshot_identity_matching_is_exact(array $tags, bool $matches): void
    {
        $snapshot = new ResticSnapshot(str_repeat('a', 64), CarbonImmutable::now('UTC'), $tags, ['/srv/media'], 'h');

        self::assertSame($matches, SnapshotIdentity::for($this->identity(), SnapshotKind::Media, self::RUN)->matches($snapshot));
    }

    public function test_remote_layout_is_deterministic_and_guards_the_restic_prefix(): void
    {
        $layout = RemoteLayout::of('quraba-backup', self::APP, 'restic');

        self::assertSame('quraba-backup/'.self::APP.'/archives/', $layout->archivesRoot());
        self::assertSame('quraba-backup/'.self::APP.'/manifests/', $layout->manifestsRoot());
        self::assertSame('quraba-backup/'.self::APP.'/restic/', $layout->resticRoot());

        $layout->assertManaged('quraba-backup/'.self::APP.'/archives/2026/10/01/'.self::RUN.'/application.zip');

        foreach (['quraba-backup/'.self::APP.'/restic/config', 'quraba-backup/'.self::APP.'/other/x', 'other-app/archives/x', 'quraba-backup/'.self::APP.'/archives/../restic/config'] as $path) {
            try {
                $layout->assertManaged($path);
                self::fail($path.' must be refused.');
            } catch (ConfigurationException|InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        $this->expectException(ConfigurationException::class);
        RemoteLayout::of('quraba-backup', self::APP, 'archives');
    }

    public function test_manifest_encoding_is_canonical(): void
    {
        $a = ManifestBuilder::encode(['b' => 1, 'a' => ['y' => 2, 'x' => [3, 1]]]);
        $b = ManifestBuilder::encode(['a' => ['x' => [3, 1], 'y' => 2], 'b' => 1]);

        self::assertSame($a, $b);
        self::assertSame($a, ManifestBuilder::canonicalJson($b));
        self::assertNull(ManifestBuilder::canonicalJson('not json'));
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    #[DataProvider('invalidSchedules')]
    public function test_invalid_schedules_are_refused(array $settings): void
    {
        $this->expectException(ConfigurationException::class);
        ScheduleDefinition::fromConfig(BackupProfile::Database, ['enabled' => true, ...$settings]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidSchedules(): iterable
    {
        yield 'not on a 5-minute boundary (shared hosting cron)' => [['time' => '02:03']];
        yield 'bad time' => [['time' => '25:00']];
        yield 'unknown frequency' => [['frequency' => 'hourly']];
        yield 'weekly without day' => [['frequency' => 'weekly', 'time' => '03:00']];
        yield 'monthly day 31' => [['frequency' => 'monthly', 'day' => 31, 'time' => '03:00']];
    }

    public function test_valid_and_disabled_schedules(): void
    {
        self::assertNull(ScheduleDefinition::fromConfig(BackupProfile::Media, ['enabled' => false, 'time' => 'nonsense']));
        self::assertNull(ScheduleDefinition::fromConfig(BackupProfile::Media, null));

        $weekly = ScheduleDefinition::fromConfig(BackupProfile::Recovery, ['enabled' => true, 'frequency' => 'weekly', 'day' => '0', 'time' => '03:30']);
        self::assertSame(0, $weekly?->weekDay());
        self::assertSame('weekly on day 0 at 03:30', $weekly?->describe());
    }

    public function test_dump_arguments_are_an_array_without_credentials(): void
    {
        $dumper = ArgvMySqlDumper::using(new SymfonyProcessFactory, '/usr/bin/mariadb-dump', 'mariadb-dump from 11.4.2-MariaDB', '/tmp', 60)
            ->setDbName('shop')
            ->setUserName('shop_user')
            ->setPassword('p@ss "word" \\ with; $(id) `x`')
            ->includeRoutines();

        $arguments = $dumper->arguments('/work/database.sql', '/work/client.cnf');

        self::assertSame('/usr/bin/mariadb-dump', $arguments[0]);
        self::assertSame('--defaults-extra-file=/work/client.cnf', $arguments[1], 'Credentials come only from the option file, which must be the first option.');
        self::assertContains('--single-transaction', $arguments);
        self::assertContains('--quick', $arguments);
        self::assertContains('--no-tablespaces', $arguments);
        self::assertContains('--routines', $arguments);
        self::assertContains('--result-file=/work/database.sql', $arguments);
        self::assertSame(['--', 'shop'], array_slice($arguments, -2));
        self::assertNotContains('--set-gtid-purged=OFF', $arguments, 'MariaDB tools have no GTID option.');

        foreach ($arguments as $argument) {
            self::assertStringNotContainsString('p@ss', $argument);
            self::assertStringNotContainsString('shop_user', $argument);
        }

        $oracle = ArgvMySqlDumper::using(new SymfonyProcessFactory, '/usr/bin/mysqldump', 'mysqldump  Ver 8.4.2 for Linux on x86_64', '/tmp', 60)->setDbName('shop');
        self::assertContains('--set-gtid-purged=OFF', $oracle->arguments('/d.sql', '/c.cnf'));
        self::assertContains('--column-statistics=0', $oracle->arguments('/d.sql', '/c.cnf'));
    }

    public function test_option_file_quoting(): void
    {
        self::assertSame('"a\\\\b"', ArgvMySqlDumper::quote('a\\b'));
        self::assertSame('"with \\"quotes\\" # and hash"', ArgvMySqlDumper::quote('with "quotes" # and hash'));
        self::assertSame('""', ArgvMySqlDumper::quote(''));
        self::assertSame('"space\\t#;\'\\"\\\\"', ArgvMySqlDumper::quote("space\t#;'\"\\"));

        $this->expectException(InvalidArgumentException::class);
        ArgvMySqlDumper::quote("line\nbreak");
    }

    public function test_dump_timeout_must_be_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ArgvMySqlDumper::using(new SymfonyProcessFactory, '/usr/bin/mysqldump', 'x', '/tmp', 0);
    }
}
