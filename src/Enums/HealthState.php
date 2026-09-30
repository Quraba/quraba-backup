<?php

declare(strict_types=1);

namespace Quraba\Backup\Enums;

enum HealthState: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Failed = 'failed';
    case Unknown = 'unknown';

    /**
     * Unknown must never be reported as healthy, so it outranks degraded;
     * failed outranks everything.
     */
    public function severity(): int
    {
        return match ($this) {
            self::Healthy => 0,
            self::Degraded => 1,
            self::Unknown => 2,
            self::Failed => 3,
        };
    }

    public function worst(self $other): self
    {
        return $other->severity() > $this->severity() ? $other : $this;
    }

    /**
     * Whether the required health is established (usable, possibly with warnings).
     */
    public function isEstablished(): bool
    {
        return $this === self::Healthy || $this === self::Degraded;
    }
}
