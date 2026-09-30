<?php

declare(strict_types=1);

namespace Quraba\Backup\Exceptions;

/**
 * An external process could not be launched, timed out, or failed.
 */
final class ProcessExecutionFailed extends QurabaBackupException
{
    protected const string FAILURE_CODE = 'process.failed';

    public static function launchFailed(string $subject, string $detail): self
    {
        return new self(sprintf('Could not launch %s: %s', $subject, $detail), 'process.launch_failed');
    }

    public static function timedOut(string $subject, string $detail): self
    {
        return new self(sprintf('%s exceeded its timeout of %s seconds', $subject, $detail), 'process.timeout');
    }
}
