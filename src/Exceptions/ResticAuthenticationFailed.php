<?php

declare(strict_types=1);

namespace Quraba\Backup\Exceptions;

/**
 * Restic rejected the repository password or the storage credentials.
 */
final class ResticAuthenticationFailed extends QurabaBackupException
{
    protected const string FAILURE_CODE = 'restic.authentication_failed';

    public static function wrongPassword(string $detail): self
    {
        return new self(sprintf('The Restic repository password was rejected: %s', $detail), 'restic.wrong_password');
    }

    public static function storageCredentialsRejected(string $detail): self
    {
        return new self(sprintf('The storage provider rejected the configured credentials: %s', $detail), 'restic.storage_credentials_rejected');
    }
}
