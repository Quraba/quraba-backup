<?php

declare(strict_types=1);

namespace Quraba\Backup\Exceptions;

/**
 * The configured Restic repository location contains no repository.
 */
final class ResticRepositoryUninitialized extends QurabaBackupException
{
    protected const string FAILURE_CODE = 'restic.repository_uninitialized';
}
