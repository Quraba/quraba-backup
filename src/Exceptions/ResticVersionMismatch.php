<?php

declare(strict_types=1);

namespace Quraba\Backup\Exceptions;

/**
 * The Restic binary does not report the exact package-pinned version.
 */
final class ResticVersionMismatch extends QurabaBackupException
{
    protected const string FAILURE_CODE = 'restic.version_mismatch';
}
