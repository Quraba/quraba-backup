<?php

declare(strict_types=1);

namespace Quraba\Backup\Workspace;

enum WorkspaceArea: string
{
    case Archive = 'archive';
    case Database = 'database';
    case Media = 'media';
    case Restore = 'restore';
    case Temp = 'temp';
}
