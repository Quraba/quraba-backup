<?php

declare(strict_types=1);

namespace Quraba\Backup\Exceptions;

/**
 * A process-safe lock could not be established; the operation fails closed.
 */
final class LockUnavailable extends QurabaBackupException
{
    protected const string FAILURE_CODE = 'lock.unavailable';
}
