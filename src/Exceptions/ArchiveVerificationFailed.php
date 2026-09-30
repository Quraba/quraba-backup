<?php

declare(strict_types=1);

namespace Quraba\Backup\Exceptions;

/**
 * The application archive failed verification and must not be uploaded or trusted.
 */
final class ArchiveVerificationFailed extends QurabaBackupException
{
    protected const string FAILURE_CODE = 'archive.verification_failed';
}
