<?php

declare(strict_types=1);

namespace Quraba\Backup\Exceptions;

/**
 * The remote manifest could not be written or proven.
 */
final class ManifestStoreFailed extends QurabaBackupException
{
    protected const string FAILURE_CODE = 'manifest.upload_failed';

    public static function collision(string $detail = ''): self
    {
        return new self('A different manifest already exists for this run; it is never overwritten'.($detail === '' ? '.' : ': '.$detail), 'manifest.collision');
    }
}
