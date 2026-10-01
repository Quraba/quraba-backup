<?php

declare(strict_types=1);

namespace Quraba\Backup\Exceptions;

/**
 * A restore (in this version: a dry run) could not resolve, reconstruct or
 * validate its source. A failed dry run never implies application damage.
 */
final class RestoreFailed extends QurabaBackupException
{
    protected const string FAILURE_CODE = 'restore.failed';

    public static function sourceUnavailable(string $detail = ''): self
    {
        return new self('The restore source cannot be used'.($detail === '' ? '.' : ': '.$detail), 'restore.source_unavailable');
    }

    public static function sourceConflict(string $detail = ''): self
    {
        return new self('The local catalog and the remote manifest disagree about the restore source'.($detail === '' ? '.' : ': '.$detail), 'restore.source_conflict');
    }

    public static function sourceExpired(string $detail = ''): self
    {
        return new self('The restore source was expired by retention'.($detail === '' ? '.' : ': '.$detail), 'restore.source_expired');
    }

    public static function reconstructionFailed(string $detail = ''): self
    {
        return new self('Reconstructing the backup in the private restore workspace failed'.($detail === '' ? '.' : ': '.$detail), 'restore.reconstruction_failed');
    }

    public static function mappingFailed(string $detail = ''): self
    {
        return new self('The restored media cannot be mapped safely to its destinations'.($detail === '' ? '.' : ': '.$detail), 'restore.media_mapping_failed');
    }

    public static function scratchUnsafe(string $detail = ''): self
    {
        return new self('The scratch validation database is not safe to use'.($detail === '' ? '.' : ': '.$detail), 'restore.scratch_unsafe');
    }

    public static function scratchImportFailed(string $detail = ''): self
    {
        return new self('Importing the dump into the scratch validation database failed'.($detail === '' ? '.' : ': '.$detail), 'restore.scratch_import_failed');
    }

    public static function liveNotImplemented(): self
    {
        return new self('Live restore is intentionally not implemented in this version; only dry runs (which change nothing) are available.', 'restore.live_not_implemented');
    }
}
