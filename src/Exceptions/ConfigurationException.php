<?php

declare(strict_types=1);

namespace Quraba\Backup\Exceptions;

/**
 * The package configuration is missing, malformed or unsafe.
 */
final class ConfigurationException extends QurabaBackupException
{
    protected const string FAILURE_CODE = 'config.invalid';
}
