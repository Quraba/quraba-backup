<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quraba\Backup\Archive\Database\MySqlDatabaseDumper;

final class DatabaseEventPolicyTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function grants(): iterable
    {
        yield 'MySQL global all' => ['GRANT ALL PRIVILEGES ON *.* TO `backup`@`%`', 'app', true];
        yield 'MariaDB database event' => ["GRANT SELECT, SHOW VIEW, TRIGGER, EVENT ON `app`.* TO 'backup'@'localhost'", 'app', true];
        yield 'unrelated database' => ["GRANT EVENT ON `other`.* TO 'backup'@'localhost'", 'app', false];
        yield 'no event privilege' => ["GRANT SELECT, SHOW VIEW ON `app`.* TO 'backup'@'localhost'", 'app', false];
        yield 'role grant is not direct proof' => ['GRANT `backup_role`@`%` TO `backup`@`%`', 'app', false];
    }

    #[DataProvider('grants')]
    public function test_event_privilege_requires_a_matching_direct_grant(string $grant, string $database, bool $expected): void
    {
        self::assertSame($expected, MySqlDatabaseDumper::grantIncludesEvents($grant, $database));
    }

    public function test_dump_object_privileges_are_scoped_and_routine_proof_is_global(): void
    {
        $dbGrant = 'GRANT SELECT, SHOW VIEW, TRIGGER ON `app`.* TO `backup`@`%`';
        self::assertTrue(MySqlDatabaseDumper::grantIncludesPrivilege($dbGrant, 'app', 'SELECT'));
        self::assertTrue(MySqlDatabaseDumper::grantIncludesPrivilege($dbGrant, 'app', 'SHOW VIEW'));
        self::assertTrue(MySqlDatabaseDumper::grantIncludesPrivilege($dbGrant, 'app', 'TRIGGER'));
        self::assertFalse(MySqlDatabaseDumper::grantIncludesPrivilege($dbGrant, 'other', 'TRIGGER'));
        self::assertFalse(MySqlDatabaseDumper::grantIncludesPrivilege('GRANT ALL PRIVILEGES ON `app`.* TO `backup`@`%`', 'app', 'SHOW ROUTINE', true));
        self::assertTrue(MySqlDatabaseDumper::grantIncludesPrivilege('GRANT ALL PRIVILEGES ON *.* TO `backup`@`%`', 'app', 'SHOW ROUTINE', true));
    }
}
