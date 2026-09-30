<?php

declare(strict_types=1);

namespace Quraba\Backup\Exceptions;

/**
 * The host environment cannot safely run the requested operation.
 */
final class EnvironmentUnsupported extends QurabaBackupException
{
    protected const string FAILURE_CODE = 'environment.unsupported';
}
