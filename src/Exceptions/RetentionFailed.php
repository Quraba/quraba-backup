<?php

declare(strict_types=1);

namespace Quraba\Backup\Exceptions;

/**
 * Retention could not safely plan, delete, prove or record an expiry.
 */
final class RetentionFailed extends QurabaBackupException
{
    protected const string FAILURE_CODE = 'retention.failed';

    public static function tombstoneCollision(string $detail = ''): self
    {
        return new self('A different retention tombstone already exists for this run; it is never overwritten'.($detail === '' ? '.' : ': '.$detail), 'retention.tombstone_collision');
    }

    public static function deletionUnproven(string $detail = ''): self
    {
        return new self('The deletion could not be proven; nothing was marked expired'.($detail === '' ? '.' : ': '.$detail), 'retention.deletion_unproven');
    }

    public static function protectionChanged(string $detail = ''): self
    {
        return new self('The retention plan changed during revalidation; the run is protected now'.($detail === '' ? '.' : ': '.$detail), 'retention.protected');
    }

    public static function invalidPolicy(string $detail = ''): self
    {
        return new self('The retention policy is invalid'.($detail === '' ? '.' : ': '.$detail), 'retention.invalid_policy');
    }
}
