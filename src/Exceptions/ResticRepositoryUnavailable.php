<?php

declare(strict_types=1);

namespace Quraba\Backup\Exceptions;

/**
 * The Restic repository could not be reached or read.
 */
final class ResticRepositoryUnavailable extends QurabaBackupException
{
    protected const string FAILURE_CODE = 'restic.repository_unavailable';
}
