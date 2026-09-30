<?php

declare(strict_types=1);

namespace Quraba\Backup\Enums;

enum RestoreMode: string
{
    case DryRun = 'dry_run';
    case Restore = 'restore';
}
