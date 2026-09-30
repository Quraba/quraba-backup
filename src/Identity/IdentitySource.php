<?php

declare(strict_types=1);

namespace Quraba\Backup\Identity;

enum IdentitySource: string
{
    /** QURABA_BACKUP_ENVIRONMENT / quraba-backup.environment */
    case PackageConfiguration = 'package_configuration';

    /** Fallback to Laravel's APP_ENV. */
    case ApplicationEnvironment = 'application_environment';
}
