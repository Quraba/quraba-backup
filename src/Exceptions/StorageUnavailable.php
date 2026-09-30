<?php

declare(strict_types=1);

namespace Quraba\Backup\Exceptions;

/**
 * Remote or local storage could not be reached or written.
 */
final class StorageUnavailable extends QurabaBackupException
{
    protected const string FAILURE_CODE = 'storage.unavailable';
}
