<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Unit;

use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;
use Quraba\Backup\Database\DefinerFilter;
use Quraba\Backup\Database\DumpInspector;
use Quraba\Backup\Database\MySqlSchema;
use Quraba\Backup\Database\SchemaInventory;
use Quraba\Backup\Enums\RestoreProfile;
use Quraba\Backup\Exceptions\ResticCommandFailed;
use Quraba\Backup\Exceptions\RestoreFailed;
use Quraba\Backup\Restic\ResticRunner;
use Quraba\Backup\Restore\Live\CleanHostProof;
use Quraba\Backup\Restore\Live\ExactDirectoryReplacement;
use Quraba\Backup\Restore\Live\LiveRestoreAuthorization;
use Quraba\Backup\Restore\Live\MediaStaging;
use Quraba\Backup\Restore\MediaRootMapping;

/**
 * The small, pure building blocks of a live restore.
 */
final class RestorePrimitivesTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = str_replace('\\', '/', sys_get_temp_dir()).'/quraba-backup-primitives-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        self::remove($this->directory);
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path) || @rmdir($path);

            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove($path.'/'.$entry);
            }
        }

        @rmdir($path);
    }

    private function dump(string $sql): string
    {
        $path = $this->directory.'/dump-'.bin2hex(random_bytes(4)).'.sql';
        file_put_contents($path, $sql);

        return $path;
    }

    public function test_live_authorization_needs_force_and_the_exact_configured_phrase(): void
    {
        $config = new Repository(['quraba-backup' => ['restore' => ['confirmation_phrase' => 'RESTORE_APPLICATION']]]);

        self::assertFalse(LiveRestoreAuthorization::confirm($config, true, 'RESTORE_APPLICATION')->cleanHost);
        self::assertTrue(LiveRestoreAuthorization::confirm($config, true, 'RESTORE_APPLICATION', true)->cleanHost);

        foreach ([[false, 'RESTORE_APPLICATION'], [true, null], [true, ''], [true, 'restore_application'], [true, ' RESTORE_APPLICATION'], [true, 'yes'], [true, true], [true, ['RESTORE_APPLICATION']]] as [$force, $phrase]) {
            try {
                LiveRestoreAuthorization::confirm($config, $force, $phrase);
                self::fail('A live restore was authorized without both exact gates.');
            } catch (RestoreFailed $exception) {
                self::assertSame('restore.confirmation_required', $exception->failureCode());
            }
        }

        // A blank or trivial configured phrase can never make every restore "confirmed".
        foreach ([null, '', '   ', 'y', 123] as $weak) {
            $weakConfig = new Repository(['quraba-backup' => ['restore' => ['confirmation_phrase' => $weak]]]);
            self::assertSame('RESTORE_APPLICATION', LiveRestoreAuthorization::phrase($weakConfig));

            try {
                LiveRestoreAuthorization::confirm($weakConfig, true, is_string($weak) ? $weak : '');
                self::fail('A weak phrase was accepted.');
            } catch (RestoreFailed $exception) {
                self::assertSame('restore.confirmation_required', $exception->failureCode());
            }
        }
    }

    public function test_dump_inspection_lists_tables_and_definers_and_refuses_statements_that_leave_the_database(): void
    {
        $dump = $this->dump(implode("\n", [
            '-- MariaDB dump',
            '/*!40101 SET NAMES utf8mb4 */;',
            'DROP TABLE IF EXISTS `orders`;',
            'CREATE TABLE `orders` (',
            '  `id` int(11) NOT NULL',
            ') ENGINE=InnoDB;',
            "INSERT INTO `orders` VALUES (1,'USE other; DROP DATABASE x; DEFINER=`evil`@`host` GRANT ALL');",
            'CREATE TABLE `we``ird` (`id` int);',
            '/*!50001 CREATE TABLE `orders_view` (`id` tinyint NOT NULL) ENGINE=MyISAM */;',
            '/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `t` AFTER INSERT ON `orders` FOR EACH ROW SET @a = 1 */;;',
            'DELIMITER ;;',
            'CREATE DEFINER=`app user`@`%` PROCEDURE `p`()',
            'BEGIN',
            '  DROP DATABASE IF EXISTS inside_a_routine_body;',
            'END ;;',
            'DELIMITER ;',
            '/*!50001 CREATE ALGORITHM=UNDEFINED */',
            "/*!50013 DEFINER='reports'@'10.0.0.%' SQL SECURITY DEFINER */",
            '/*!50001 VIEW `orders_view` AS select 1 AS `id` */;',
        ])."\n");

        $inspection = DumpInspector::inspect($dump);
        self::assertSame(['orders', 'we`ird'], $inspection['tables'], 'Only real base tables; a view placeholder inside a versioned comment is not one.');
        self::assertSame(['app user@%', 'reports@10.0.0.%', 'root@localhost'], $inspection['definers'], 'Row data that merely contains the word DEFINER is not a definer.');

        foreach ([
            'USE `another_database`;' => 'USE',
            '/*!40000 DROP DATABASE IF EXISTS `x`*/;' => 'DROP DATABASE',
            'CREATE DATABASE `x`;' => 'CREATE DATABASE',
            "GRANT ALL ON *.* TO 'x'@'%';" => 'GRANT',
            "CREATE USER 'x'@'%';" => 'CREATE USER',
            "SET PASSWORD FOR 'x' = 'y';" => 'SET PASSWORD',
        ] as $statement => $name) {
            try {
                DumpInspector::inspect($this->dump("CREATE TABLE `a` (`id` int);\n".$statement."\n"));
                self::fail($name.' was accepted.');
            } catch (RestoreFailed $exception) {
                self::assertSame('restore.reconstruction_failed', $exception->failureCode());
                self::assertStringContainsString('['.$name.']', $exception->getMessage());
            }
        }
    }

    public function test_definers_are_removed_from_statements_only_and_never_from_row_data(): void
    {
        self::assertSame('/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER `t` */;;', DefinerFilter::strip('/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `t` */;;'));
        self::assertSame('CREATE PROCEDURE `p`()', DefinerFilter::strip('CREATE DEFINER=`root`@`localhost` PROCEDURE `p`()'));
        self::assertSame('/*!50013 SQL SECURITY DEFINER */', DefinerFilter::strip("/*!50013 DEFINER='a b'@'%' SQL SECURITY DEFINER */"));
        self::assertSame('CREATE FUNCTION `f`() RETURNS int', DefinerFilter::strip('CREATE DEFINER = app@localhost FUNCTION `f`() RETURNS int'));

        $row = "INSERT INTO `t` VALUES ('CREATE DEFINER=`root`@`localhost` PROCEDURE x');";
        self::assertSame($row, DefinerFilter::strip($row), 'Row data is passed through byte for byte.');
        self::assertNull(DefinerFilter::definer($row));

        $path = $this->dump("CREATE DEFINER=`root`@`localhost` PROCEDURE `p`() SELECT 1;\n".$row."\nlast line without newline");
        $kept = implode('', iterator_to_array(DefinerFilter::stream(fopen($path, 'rb'), false), false));
        self::assertSame(file_get_contents($path), $kept, 'Without rewriting the stream is the dump, unchanged.');
        $stripped = implode('', iterator_to_array(DefinerFilter::stream(fopen($path, 'rb'), true), false));
        self::assertSame("CREATE PROCEDURE `p`() SELECT 1;\n".$row."\nlast line without newline", $stripped);
    }

    public function test_schema_inventory_is_deterministic_and_drops_in_a_safe_order(): void
    {
        $objects = [
            ['type' => SchemaInventory::TABLE, 'name' => 'b'],
            ['type' => SchemaInventory::TRIGGER, 'name' => 'trg'],
            ['type' => SchemaInventory::FUNCTION, 'name' => 'f'],
            ['type' => SchemaInventory::VIEW, 'name' => 'v'],
            ['type' => SchemaInventory::EVENT, 'name' => 'e'],
            ['type' => SchemaInventory::TABLE, 'name' => 'a'],
            ['type' => SchemaInventory::SEQUENCE, 'name' => 's'],
            ['type' => SchemaInventory::PROCEDURE, 'name' => 'p'],
        ];
        $inventory = new SchemaInventory($objects);
        self::assertSame($inventory->fingerprint(), (new SchemaInventory(array_reverse($objects)))->fingerprint());
        self::assertSame(['a', 'b'], $inventory->names(SchemaInventory::TABLE));
        self::assertSame(['EVENT', 'VIEW', 'FUNCTION', 'PROCEDURE', 'BASE TABLE', 'BASE TABLE', 'SEQUENCE'], array_column($inventory->droppable(), 'type'), 'Triggers go with their tables; views and routines before tables.');
        self::assertSame(8, $inventory->summary()['objects']);
        self::assertFalse($inventory->isEmpty());
        self::assertTrue((new SchemaInventory([]))->isEmpty());

        // An object type the package does not know is never silently skipped.
        $this->expectException(\InvalidArgumentException::class);
        new SchemaInventory([['type' => 'SYSTEM VIEW', 'name' => 'x']]);
    }

    public function test_identifiers_are_quoted_and_restic_root_paths_are_exact(): void
    {
        self::assertSame('`orders`', MySqlSchema::quote('orders'));
        self::assertSame('`we``ird; DROP TABLE x`', MySqlSchema::quote('we`ird; DROP TABLE x'));
        self::assertContains('mysql', MySqlSchema::SYSTEM_SCHEMAS);
        self::assertContains('information_schema', MySqlSchema::SYSTEM_SCHEMAS);
        self::assertContains('performance_schema', MySqlSchema::SYSTEM_SCHEMAS);
        self::assertContains('sys', MySqlSchema::SYSTEM_SCHEMAS);

        self::assertSame('/var/www/storage/app/public', ResticRunner::snapshotPath('/var/www/storage/app/public/'));
        self::assertSame('/C/Users/app/media', ResticRunner::snapshotPath('C:\\Users\\app\\media'));

        foreach (['relative/path', '/a/../b', '/a//b', '/', "/a\0b", '/a/./b'] as $unsafe) {
            try {
                ResticRunner::snapshotPath($unsafe);
                self::fail('An unsafe snapshot path was accepted: '.$unsafe);
            } catch (ResticCommandFailed) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_exact_directory_replacement_is_two_renames_and_refuses_everything_else(): void
    {
        $swap = new ExactDirectoryReplacement;
        $live = $this->directory.'/media';
        $staged = $this->directory.'/staging/media';
        mkdir($live.'/uploads', 0700, true);
        mkdir($staged.'/uploads', 0700, true);
        file_put_contents($live.'/uploads/old.txt', 'old');
        file_put_contents($staged.'/uploads/new.txt', 'restored');
        $uuid = '0198c0de-0000-7000-8000-000000000001';
        $parked = $swap->parkedPath($live, $uuid);

        self::assertSame($this->directory.'/.media.quraba-parked-'.$uuid, $parked);
        $tree = $swap->fingerprint($staged, $live, false);
        self::assertSame(['files' => 1, 'directories' => 1, 'links' => 0, 'bytes' => 8], array_diff_key($tree, ['sha256' => true]));
        $stagedIdentity = $swap->identity($staged);

        // Never an overlay: the live path must be free before activation.
        try {
            $swap->activate($staged, $live);
            self::fail('The staged tree was activated over an existing live root.');
        } catch (RestoreFailed $exception) {
            self::assertSame('restore.media_apply_failed', $exception->failureCode());
            self::assertStringContainsString('overlay', $exception->getMessage());
        }

        // The parked path must be the sibling named for this restore.
        foreach ([$this->directory.'/elsewhere/.media.quraba-parked-'.$uuid, $this->directory.'/media-backup'] as $wrong) {
            try {
                $swap->park($live, $wrong);
                self::fail('A live root was parked somewhere else.');
            } catch (RestoreFailed) {
                self::assertDirectoryExists($live);
            }
        }

        $swap->park($live, $parked);
        self::assertDirectoryDoesNotExist($live);
        self::assertSame('old', file_get_contents($parked.'/uploads/old.txt'));

        // A second park for the same restore can never overwrite the parked tree.
        mkdir($live);
        try {
            $swap->park($live, $parked);
            self::fail('The parked tree was overwritten.');
        } catch (RestoreFailed) {
            rmdir($live);
        }

        $swap->activate($staged, $live);
        self::assertDirectoryDoesNotExist($staged);
        $verified = $swap->verify($live, $tree, $stagedIdentity, false);
        self::assertSame($live, $verified['path']);
        self::assertSame('restored', file_get_contents($live.'/uploads/new.txt'));
        self::assertFileDoesNotExist($live.'/uploads/old.txt', 'Exact replacement: nothing of the old tree remains in the live root.');

        // Any change after activation fails verification.
        file_put_contents($live.'/uploads/extra.txt', 'x');
        try {
            $swap->verify($live, $tree, $stagedIdentity, false);
            self::fail('A changed tree was verified.');
        } catch (RestoreFailed $exception) {
            self::assertSame('restore.media_verification_failed', $exception->failureCode());
        }

        // Same size, other content, later modification: still not the staged tree.
        unlink($live.'/uploads/extra.txt');
        file_put_contents($live.'/uploads/new.txt', 'RESTORED');
        touch($live.'/uploads/new.txt', time() + 60);
        clearstatcache();
        $this->expectException(RestoreFailed::class);
        $swap->verify($live, $tree, $stagedIdentity, false);
    }

    public function test_symbolic_links_that_escape_a_staged_tree_are_refused(): void
    {
        $swap = new ExactDirectoryReplacement;
        $tree = $this->directory.'/tree';
        mkdir($tree.'/sub', 0700, true);
        file_put_contents($tree.'/file.txt', 'x');

        if (! @symlink('../file.txt', $tree.'/sub/inside')) {
            self::markTestSkipped('This platform/user cannot create symbolic links.');
        }

        // A link that stays inside the tree is restored as a link.
        self::assertSame(1, $swap->fingerprint($tree, '/srv/app/media', false)['links']);

        foreach (['../../outside', '/etc/passwd', '../../../etc'] as $index => $target) {
            symlink($target, $tree.'/sub/escape'.$index);
            try {
                $swap->fingerprint($tree, '/srv/app/media', false);
                self::fail('An escaping link was accepted: '.$target);
            } catch (RestoreFailed $exception) {
                self::assertSame('restore.media_mapping_failed', $exception->failureCode());
                self::assertStringContainsString('points outside its root', $exception->getMessage());
            }
            // Only an explicit per-root opt-in restores it.
            self::assertGreaterThan(1, $swap->fingerprint($tree, '/srv/app/media', true)['links']);
            unlink($tree.'/sub/escape'.$index);
        }

        // An absolute link back into the live destination is not an escape.
        symlink('/srv/app/media/file.txt', $tree.'/sub/absolute');
        self::assertSame(2, $swap->fingerprint($tree, '/srv/app/media', false)['links']);

        // The staged root itself must never be a link.
        symlink($tree, $this->directory.'/linked-tree');
        $this->expectException(RestoreFailed::class);
        $swap->fingerprint($this->directory.'/linked-tree', '/srv/app/media', false);
    }

    public function test_same_filesystem_proof_needs_equal_devices_and_a_real_rename(): void
    {
        mkdir($this->directory.'/a');
        mkdir($this->directory.'/b');
        $staging = new MediaStaging($this->directory.'/public');
        self::assertTrue($staging->sameFilesystem($this->directory.'/a', $this->directory.'/b'));
        self::assertSame(['.', '..'], scandir($this->directory.'/a'), 'The probe leaves nothing behind.');
        self::assertSame(['.', '..'], scandir($this->directory.'/b'));
        self::assertFalse($staging->sameFilesystem($this->directory.'/a', $this->directory.'/missing'));

        // Different devices are never "the same filesystem", whatever rename would do.
        $crossDevice = new MediaStaging($this->directory.'/public', fn (string $path): ?int => str_ends_with($path, '/a') ? 1 : 2);
        self::assertFalse($crossDevice->sameFilesystem($this->directory.'/a', $this->directory.'/b'));
        $unknownDevice = new MediaStaging($this->directory.'/public', static fn (string $path): ?int => 0);
        self::assertFalse($unknownDevice->sameFilesystem($this->directory.'/a', $this->directory.'/b'));
        self::assertSame(['.', '..'], scandir($this->directory.'/a'));
    }

    public function test_clean_host_proof_accepts_only_absent_or_placeholder_only_media(): void
    {
        $proof = new CleanHostProof;
        $root = $this->directory.'/media';
        $mapping = static fn (bool $exists): MediaRootMapping => new MediaRootMapping('uploads', '/srv/media', '/staged', $root, $exists);

        self::assertSame(['uploads' => 'absent'], $proof->prove(RestoreProfile::Media, null, null, [$mapping(false)])['media']);

        mkdir($root);
        file_put_contents($root.'/.gitignore', '*');
        file_put_contents($root.'/.gitkeep', '');
        self::assertSame(['uploads' => 'empty'], $proof->prove(RestoreProfile::Media, null, null, [$mapping(true)])['media']);

        // A directory named like a placeholder, or any real file, is content.
        foreach (['real.jpg' => 'file', 'sub' => 'dir'] as $name => $type) {
            $type === 'dir' ? mkdir($root.'/'.$name) : file_put_contents($root.'/'.$name, 'x');
            try {
                $proof->prove(RestoreProfile::Media, null, null, [$mapping(true)]);
                self::fail('A non-empty media root passed as a clean host.');
            } catch (RestoreFailed $exception) {
                self::assertSame('restore.clean_host_refused', $exception->failureCode());
            }
            $type === 'dir' ? rmdir($root.'/'.$name) : unlink($root.'/'.$name);
        }

        // A database that could not be inspected is never "empty".
        $this->expectException(RestoreFailed::class);
        $proof->prove(RestoreProfile::Database, null, null, []);
    }
}
