<?php

declare(strict_types=1);

namespace Quraba\Backup\Enums;

enum MaintenanceOperation: string
{
    case Reconciliation = 'reconciliation';
    case Retention = 'retention';
    case ResticCheck = 'restic_check';
    case ResticPrune = 'restic_prune';
}
