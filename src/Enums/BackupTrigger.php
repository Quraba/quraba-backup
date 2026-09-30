<?php

declare(strict_types=1);

namespace Quraba\Backup\Enums;

enum BackupTrigger: string
{
    case Scheduled = 'scheduled';
    case Manual = 'manual';
    case PreRestore = 'pre_restore';
    case Api = 'api';
}
