<?php

declare(strict_types=1);

namespace Quraba\Backup\Enums;

use Quraba\Backup\Domain\StatusMachine;

enum BackupStatus: string implements StatusMachine
{
    case Pending = 'pending';
    case Preflighting = 'preflighting';
    case Running = 'running';
    case Verifying = 'verifying';
    case Partial = 'partial';
    case Completed = 'completed';
    case Failed = 'failed';
    case Canceled = 'canceled';
    case Indeterminate = 'indeterminate';

    public static function initial(): static
    {
        return self::Pending;
    }

    /**
     * Legal forward transitions. Nothing leaves a terminal state through a
     * normal transition; an indeterminate run is only resolved through
     * reconciliation (see {@see self::reconciliationOutcomes()}).
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Preflighting, self::Failed, self::Canceled],
            self::Preflighting => [self::Running, self::Failed, self::Canceled],
            // Once work has started artifacts may physically exist, so the run can
            // no longer be "canceled"; it ends partial, failed or indeterminate.
            self::Running => [self::Verifying, self::Partial, self::Failed, self::Indeterminate],
            self::Verifying => [self::Completed, self::Partial, self::Failed, self::Indeterminate],
            self::Partial, self::Completed, self::Failed, self::Canceled, self::Indeterminate => [],
        };
    }

    public function canTransitionTo(StatusMachine $next): bool
    {
        return $next instanceof self && in_array($next, $this->allowedTransitions(), true);
    }

    /**
     * States an indeterminate run may be resolved to once reconciliation has
     * physical evidence.
     *
     * @return list<self>
     */
    public static function reconciliationOutcomes(): array
    {
        return [self::Completed, self::Partial, self::Failed];
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Preflighting, self::Running, self::Verifying], true);
    }
}
