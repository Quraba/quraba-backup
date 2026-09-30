<?php

declare(strict_types=1);

namespace Quraba\Backup\Exceptions;

/**
 * Another write-affecting Quraba Backup operation currently holds the required lock.
 */
final class OperationBusy extends QurabaBackupException
{
    protected const string FAILURE_CODE = 'operation.busy';
}
