<?php

declare(strict_types=1);

namespace Quraba\Backup\Exceptions;

/**
 * A configured media root is unsafe to back up.
 */
final class MediaPathUnsafe extends QurabaBackupException
{
    protected const string FAILURE_CODE = 'media.path_unsafe';
}
