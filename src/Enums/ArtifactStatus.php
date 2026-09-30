<?php

declare(strict_types=1);

namespace Quraba\Backup\Enums;

use Quraba\Backup\Domain\StatusMachine;

enum ArtifactStatus: string implements StatusMachine
{
    case Pending = 'pending';
    case Creating = 'creating';
    case Uploading = 'uploading';
    case Verifying = 'verifying';
    case Verified = 'verified';
    case Failed = 'failed';
    case Expired = 'expired';

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
            // Pending -> Verifying covers adoption of an artifact that already
            // exists physically (reconciliation after a lost catalog write).
            self::Pending => [self::Creating, self::Verifying, self::Failed],
            // Restic snapshots are created remotely and go straight to verification.
            self::Creating => [self::Uploading, self::Verifying, self::Failed],
            self::Uploading => [self::Verifying, self::Failed],
            self::Verifying => [self::Verified, self::Failed],
            // Only a verified artifact can expire, and only once retention has
            // observed its physical absence.
            self::Verified => [self::Expired],
            self::Failed, self::Expired => [],
        };
    }

    public function canTransitionTo(StatusMachine $next): bool
    {
        return $next instanceof self && in_array($next, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }
}
