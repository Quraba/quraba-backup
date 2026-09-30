<?php

declare(strict_types=1);

namespace Quraba\Backup\Exceptions;

/**
 * The Restic repository is locked by another process; it is never unlocked automatically.
 */
final class ResticRepositoryLocked extends QurabaBackupException
{
    protected const string FAILURE_CODE = 'restic.repository_locked';
}
