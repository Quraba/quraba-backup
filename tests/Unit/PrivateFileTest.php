<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Quraba\Backup\Database\MySqlOptionFile;
use Quraba\Backup\Exceptions\WorkspaceViolation;
use Quraba\Backup\Support\PrivateFile;

final class PrivateFileTest extends TestCase
{
    public function test_linux_option_file_is_exclusive_owned_0600_and_umask_is_restored(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX mode and inode proofs run in Linux CI.');
        }

        $directory = sys_get_temp_dir().'/quraba-private-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $previous = umask();
        try {
            $path = MySqlOptionFile::write($directory, 'special-user', "slash\\ quote\" apostrophe' space\t#;", '127.0.0.1', 3306, null);
            self::assertSame($previous, umask());
            $stat = stat($path);
            self::assertIsArray($stat);
            self::assertSame(0600, $stat['mode'] & 0777);
            if (function_exists('posix_geteuid')) {
                self::assertSame(posix_geteuid(), $stat['uid']);
            }
            self::assertStringContainsString('password="slash\\\\ quote\\" apostrophe\' space\\t#;"', (string) file_get_contents($path));
            self::assertTrue(MySqlOptionFile::destroy($path));
            self::assertFileDoesNotExist($path);
        } finally {
            umask($previous);
            @rmdir($directory);
        }
    }

    public function test_privacy_proof_failure_refuses_before_a_secret_can_be_written(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX permission proof runs in Linux CI.');
        }

        $path = sys_get_temp_dir().'/quraba-private-'.bin2hex(random_bytes(8));
        try {
            PrivateFile::create($path, static fn (string $file, int $mode): bool => false, true);
            self::fail('A failed chmod proof must refuse.');
        } catch (WorkspaceViolation $exception) {
            self::assertSame('security.private_file', $exception->failureCode());
            self::assertFileDoesNotExist($path);
        }
    }
}
