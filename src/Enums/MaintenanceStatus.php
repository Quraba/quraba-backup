<?php

declare(strict_types=1);

namespace Quraba\Backup\Enums;

use Quraba\Backup\Domain\StatusMachine;

enum MaintenanceStatus: string implements StatusMachine
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Canceled = 'canceled';
    case Indeterminate = 'indeterminate';

    public static function initial(): static
    {
        return self::Pending;
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Running, self::Failed, self::Canceled],
            self::Running => [self::Completed, self::Failed, self::Indeterminate],
            self::Completed, self::Failed, self::Canceled, self::Indeterminate => [],
        };
    }

    public function canTransitionTo(StatusMachine $next): bool
    {
        return $next instanceof self && in_array($next, $this->allowedTransitions(), true);
    }

    /**
     * @return list<self>
     */
    public static function reconciliationOutcomes(): array
    {
        return [self::Completed, self::Failed];
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }
}
