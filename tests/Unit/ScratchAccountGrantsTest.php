<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Quraba\Backup\Exceptions\RestoreFailed;
use Quraba\Backup\Restore\ScratchAccountGrants;

final class ScratchAccountGrantsTest extends TestCase
{
    public function test_literal_scratch_grant_is_accepted(): void
    {
        ScratchAccountGrants::assertConfined([
            'GRANT USAGE ON *.* TO `scratch`@`%`',
            'GRANT ALL PRIVILEGES ON `scratch\_db`.* TO `scratch`@`%`',
        ], 'scratch_db');
        self::addToAssertionCount(1);
    }

    public function test_wildcard_global_foreign_and_role_grants_are_refused(): void
    {
        foreach ([
            'GRANT ALL PRIVILEGES ON `scratch_db`.* TO `scratch`@`%`',
            'GRANT SELECT ON *.* TO `scratch`@`%`',
            'GRANT SELECT ON `production`.* TO `scratch`@`%`',
            'GRANT `admin_role` TO `scratch`@`%`',
        ] as $unsafe) {
            try {
                ScratchAccountGrants::assertConfined([$unsafe], 'scratch_db');
                self::fail('An unsafe scratch grant was accepted.');
            } catch (RestoreFailed) {
                self::addToAssertionCount(1);
            }
        }
    }
}
