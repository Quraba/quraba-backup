<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quraba\Backup\Health\Doctor\Checks\RuntimeChecks;

final class RuntimeChecksTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function phpVersions(): iterable
    {
        yield 'before minimum' => ['8.3.99', false];
        yield 'minimum' => ['8.4.0', true];
        yield 'PHP 8.4' => ['8.4.26', true];
        yield 'PHP 8.5' => ['8.5.0', true];
    }

    #[DataProvider('phpVersions')]
    public function test_php_version_boundary(string $version, bool $supported): void
    {
        self::assertSame($supported, RuntimeChecks::supportsPhpVersion($version));
    }
}
