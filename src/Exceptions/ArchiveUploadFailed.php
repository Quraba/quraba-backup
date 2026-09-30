<?php

declare(strict_types=1);

namespace Quraba\Backup\Exceptions;

/**
 * The application archive could not be uploaded or proven in remote storage.
 */
final class ArchiveUploadFailed extends QurabaBackupException
{
    protected const string FAILURE_CODE = 'archive.upload_failed';

    public static function collision(string $detail = ''): self
    {
        return new self('A different object already exists at the archive path of this run'.($detail === '' ? '.' : ': '.$detail), 'archive.collision');
    }
}
