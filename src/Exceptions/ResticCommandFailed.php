<?php

declare(strict_types=1);

namespace Quraba\Backup\Exceptions;

/**
 * A Restic operation exited unsuccessfully or produced unusable output.
 */
final class ResticCommandFailed extends QurabaBackupException
{
    protected const string FAILURE_CODE = 'restic.command_failed';
}
