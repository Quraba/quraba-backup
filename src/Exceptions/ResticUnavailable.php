<?php

declare(strict_types=1);

namespace Quraba\Backup\Exceptions;

/**
 * No usable, verified Restic binary is available.
 */
final class ResticUnavailable extends QurabaBackupException
{
    protected const string FAILURE_CODE = 'restic.unavailable';
}
