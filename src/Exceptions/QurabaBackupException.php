<?php

declare(strict_types=1);

namespace Quraba\Backup\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base class of every operational exception thrown by the package.
 *
 * Each exception carries a stable machine-readable failure code that is
 * persisted separately from the (already sanitized) human message.
 *
 * Messages passed to these exceptions must never contain secrets. Code that
 * wraps third-party or process output must run it through the redactor first.
 */
abstract class QurabaBackupException extends RuntimeException
{
    /** Default machine-readable code for this exception class. */
    protected const string FAILURE_CODE = 'backup.error';

    private readonly string $failureCode;

    final public function __construct(string $message, ?string $failureCode = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);

        $this->failureCode = $failureCode ?? static::FAILURE_CODE;
    }

    public function failureCode(): string
    {
        return $this->failureCode;
    }
}
