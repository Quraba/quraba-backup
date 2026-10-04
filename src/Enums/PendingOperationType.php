<?php

declare(strict_types=1);

namespace Quraba\Backup\Enums;

enum PendingOperationType: string
{
    case DryRestore = 'dry_restore';
    case LiveRestore = 'live_restore';
    case Doctor = 'doctor';
    case HealthRefresh = 'health_refresh';
    case ResticCheck = 'restic_check';
    case RetentionPlan = 'retention_plan';

    public function ability(): string
    {
        return match ($this) {
            self::DryRestore => 'dry-restore',
            self::LiveRestore => 'live-restore',
            self::Doctor, self::HealthRefresh => 'run-health-check',
            self::ResticCheck => 'run-restic-check',
            self::RetentionPlan => 'plan-retention',
        };
    }
}
