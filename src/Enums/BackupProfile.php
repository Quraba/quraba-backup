<?php

declare(strict_types=1);

namespace Quraba\Backup\Enums;

enum BackupProfile: string
{
    case Database = 'database';
    case Media = 'media';
    case Recovery = 'recovery';
}
