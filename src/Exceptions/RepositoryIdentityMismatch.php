<?php

declare(strict_types=1);

namespace Quraba\Backup\Exceptions;

/**
 * The opened Restic repository is not the repository this application is bound to.
 */
final class RepositoryIdentityMismatch extends QurabaBackupException
{
    protected const string FAILURE_CODE = 'restic.repository_identity_mismatch';
}
