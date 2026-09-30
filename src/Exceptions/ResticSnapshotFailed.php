<?php

declare(strict_types=1);

namespace Quraba\Backup\Exceptions;

/**
 * A Restic media snapshot could not be created or verified.
 */
final class ResticSnapshotFailed extends QurabaBackupException
{
    protected const string FAILURE_CODE = 'restic.snapshot_failed';

    public static function ambiguous(string $detail = ''): self
    {
        return new self('More than one Restic snapshot claims this run identity; refusing to choose'.($detail === '' ? '.' : ': '.$detail), 'restic.snapshot_ambiguous');
    }

    public static function identityMismatch(string $detail = ''): self
    {
        return new self('A Restic snapshot does not carry the exact expected identity'.($detail === '' ? '.' : ': '.$detail), 'restic.snapshot_identity_mismatch');
    }

    public static function incomplete(string $detail = ''): self
    {
        return new self('Restic could not read all source data; the snapshot is incomplete and is not trusted'.($detail === '' ? '.' : ': '.$detail), 'restic.snapshot_incomplete');
    }

    public static function uncertain(string $detail = ''): self
    {
        return new self('The snapshot outcome could not be proven; reconciliation must decide'.($detail === '' ? '.' : ': '.$detail), 'restic.snapshot_uncertain');
    }
}
