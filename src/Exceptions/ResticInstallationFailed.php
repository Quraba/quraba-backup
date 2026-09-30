<?php

declare(strict_types=1);

namespace Quraba\Backup\Exceptions;

/**
 * The managed Restic binary could not be installed safely.
 */
final class ResticInstallationFailed extends QurabaBackupException
{
    protected const string FAILURE_CODE = 'restic.installation_failed';

    public static function checksumMismatch(string $subject, string $detail): self
    {
        return new self(sprintf('SHA-256 verification failed for %s: %s', $subject, $detail), 'restic.checksum_mismatch');
    }
}
