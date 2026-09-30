<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Quraba\Backup\Domain\Identifiers;
use Quraba\Backup\Exceptions\WorkspaceViolation;
use Quraba\Backup\Support\PathGuard;

final class PathAndIdentifierTest extends TestCase
{
    public function test_absolute_paths_are_normalized(): void
    {
        self::assertSame('/var/www/app/storage', PathGuard::normalizeAbsolute('/var//www/./app/storage/'));
    }

    public function test_dot_dot_segments_are_refused(): void
    {
        $this->expectException(WorkspaceViolation::class);
        PathGuard::normalizeAbsolute('/var/www/../etc');
    }

    public function test_relative_paths_are_refused_where_absolute_required(): void
    {
        $this->expectException(WorkspaceViolation::class);
        PathGuard::normalizeAbsolute('storage/app');
    }

    public function test_nul_bytes_are_refused(): void
    {
        $this->expectException(WorkspaceViolation::class);
        PathGuard::normalizeAbsolute("/var/www\0/x");
    }

    public function test_safe_relative_rejects_traversal_and_absolute_forms(): void
    {
        self::assertSame('a/b.sql', PathGuard::assertSafeRelative('a/b.sql'));

        foreach (['../x', 'a/../../x', '/etc/passwd', 'C:/x', 'a\\b', 'a//b', './a', '', "a\0b"] as $bad) {
            try {
                PathGuard::assertSafeRelative($bad);
                self::fail(sprintf('[%s] should be refused.', $bad));
            } catch (WorkspaceViolation) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_containment_is_segment_aware(): void
    {
        self::assertTrue(PathGuard::isWithin('/a/b/c', '/a/b'));
        self::assertTrue(PathGuard::isWithin('/a/b', '/a/b'));
        self::assertFalse(PathGuard::isWithin('/a/bc', '/a/b'), 'A shared prefix is not containment.');
        self::assertFalse(PathGuard::isWithin('/a', '/a/b'));
    }

    public function test_full_snapshot_ids_only(): void
    {
        self::assertTrue(Identifiers::isFullSnapshotId(str_repeat('a1', 32)));
        self::assertFalse(Identifiers::isFullSnapshotId('a1b2c3d4'), 'short IDs are prefixes, never identities');
        self::assertFalse(Identifiers::isFullSnapshotId('latest'));
        self::assertFalse(Identifiers::isFullSnapshotId(strtoupper(str_repeat('a1', 32))));

        $this->expectException(InvalidArgumentException::class);
        Identifiers::assertFullSnapshotId('latest');
    }

    public function test_uuid_validation_is_canonical(): void
    {
        self::assertTrue(Identifiers::isUuid('6f614a0b-c447-4e36-9758-347858cbb46b'));
        self::assertFalse(Identifiers::isUuid('6F614A0B-C447-4E36-9758-347858CBB46B'));
        self::assertFalse(Identifiers::isUuid('not-a-uuid'));
        self::assertFalse(Identifiers::isUuid('00000000-0000-0000-0000-000000000000'));
    }

    public function test_object_locators_must_be_clean_relative_keys(): void
    {
        self::assertSame('quraba-backup/app/archives/2026/09/30/run/application.zip', Identifiers::assertObjectLocator('quraba-backup/app/archives/2026/09/30/run/application.zip'));

        foreach (['/abs/key', 'a/../b', 'a//b', "a\nb", 'a\\b', ''] as $bad) {
            try {
                Identifiers::assertObjectLocator($bad);
                self::fail(sprintf('[%s] should be refused.', $bad));
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
