<?php

declare(strict_types=1);

namespace Quraba\Backup\Enums;

enum RestoreProfile: string
{
    case Database = 'database';
    case Media = 'media';
    case Full = 'full';
}
