<?php

declare(strict_types=1);

namespace Quraba\Backup\Exceptions;

/**
 * The encrypted application archive could not be created.
 */
final class ArchiveCreationFailed extends QurabaBackupException
{
    protected const string FAILURE_CODE = 'archive.creation_failed';

    public static function passwordMissing(string $detail = ''): self
    {
        return new self('QURABA_BACKUP_ARCHIVE_PASSWORD is missing or blank; the archive contains .env and is never created unencrypted'.($detail === '' ? '.' : ': '.$detail), 'archive.password_missing');
    }

    public static function encryptionUnsupported(string $detail = ''): self
    {
        return new self('AES-256 ZIP encryption is not available in this PHP build (libzip); refusing to create an unencrypted archive'.($detail === '' ? '.' : ': '.$detail), 'archive.encryption_unsupported');
    }

    public static function databaseDumpFailed(string $detail = ''): self
    {
        return new self('The database dump failed'.($detail === '' ? '.' : ': '.$detail), 'archive.database_dump_failed');
    }
}
