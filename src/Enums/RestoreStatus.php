<?php

declare(strict_types=1);

namespace Quraba\Backup\Enums;

use Quraba\Backup\Domain\StatusMachine;

enum RestoreStatus: string implements StatusMachine
{
    case Pending = 'pending';
    case Resolving = 'resolving';
    case Reconstructing = 'reconstructing';
    case Validating = 'validating';
    case SafetyBackup = 'safety_backup';
    case Quiescing = 'quiescing';
    case Applying = 'applying';
    case Verifying = 'verifying';
    case Completed = 'completed';
    case Failed = 'failed';
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
            self::Pending => [self::Resolving, self::Failed],
            self::Resolving => [self::Reconstructing, self::Failed],
            self::Reconstructing => [self::Validating, self::Failed],
            // A dry run finishes after validation; a live restore continues into
            // the safety backup and quiescence (in either order).
            self::Validating => [self::SafetyBackup, self::Quiescing, self::Completed, self::Failed],
            self::SafetyBackup => [self::Quiescing, self::Applying, self::Failed],
            self::Quiescing => [self::SafetyBackup, self::Applying, self::Failed],
            self::Applying => [self::Verifying, self::Failed, self::Indeterminate],
            self::Verifying => [self::Completed, self::Failed, self::Indeterminate],
            self::Completed, self::Failed, self::Indeterminate => [],
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

    /**
     * States that only a live (non dry-run) restore may enter.
     */
    public function isLiveOnly(): bool
    {
        return in_array($this, [self::SafetyBackup, self::Quiescing, self::Applying], true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }
}
