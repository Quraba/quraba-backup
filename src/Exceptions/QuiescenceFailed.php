<?php

declare(strict_types=1);

namespace Quraba\Backup\Exceptions;

/**
 * The application could not be quiesced or released as required.
 */
final class QuiescenceFailed extends QurabaBackupException
{
    protected const string FAILURE_CODE = 'quiescence.failed';
}
