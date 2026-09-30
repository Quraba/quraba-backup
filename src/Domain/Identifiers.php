<?php

declare(strict_types=1);

namespace Quraba\Backup\Domain;

use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

/**
 * Validation of the exact identities the package persists.
 *
 * Short Restic IDs, `latest`, and anything else that is merely a selector are
 * rejected: persisted identities must be canonical and unambiguous.
 */
final class Identifiers
{
    private const string HEX_64 = '/^[0-9a-f]{64}$/';

    public static function isFullSnapshotId(string $value): bool
    {
        return preg_match(self::HEX_64, $value) === 1;
    }

    public static function assertFullSnapshotId(string $value): string
    {
        if (! self::isFullSnapshotId($value)) {
            throw new InvalidArgumentException('A full 64-character lowercase hexadecimal Restic snapshot ID is required; short IDs and selectors are refused.');
        }

        return $value;
    }

    public static function isSha256(string $value): bool
    {
        return preg_match(self::HEX_64, $value) === 1;
    }

    public static function assertSha256(string $value): string
    {
        if (! self::isSha256($value)) {
            throw new InvalidArgumentException('A 64-character lowercase hexadecimal SHA-256 digest is required.');
        }

        return $value;
    }

    /**
     * Canonical lowercase hyphenated UUID (any RFC 9562 version except nil/max).
     */
    public static function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value) === 1
            && Uuid::isValid($value);
    }

    public static function assertUuid(string $value, string $label = 'UUID'): string
    {
        if (! self::isUuid($value)) {
            throw new InvalidArgumentException(sprintf('%s must be a canonical lowercase UUID.', $label));
        }

        return $value;
    }

    /**
     * A remote object key relative to the bucket: no leading slash, no dot
     * segments, no backslashes, no control characters.
     */
    public static function assertObjectLocator(string $value): string
    {
        $valid = $value !== ''
            && strlen($value) <= 1024
            && ! str_starts_with($value, '/')
            && ! str_contains($value, '\\')
            && ! str_contains($value, '//')
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1
            && array_filter(explode('/', $value), static fn (string $segment): bool => $segment === '.' || $segment === '..') === [];

        if (! $valid) {
            throw new InvalidArgumentException('Artifact locators must be clean relative object keys.');
        }

        return $value;
    }
}
