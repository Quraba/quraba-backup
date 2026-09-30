<?php

declare(strict_types=1);

namespace Quraba\Backup\Domain;

use BackedEnum;
use Quraba\Backup\Models\Concerns\HasControlledStatus;

/**
 * A persisted status whose legal transitions are declared by the enum itself.
 *
 * Models never assign these statuses directly; they move through
 * {@see HasControlledStatus}, which consults
 * this contract and refuses anything not declared here.
 */
interface StatusMachine extends BackedEnum
{
    public static function initial(): static;

    public function canTransitionTo(self $next): bool;

    public function isTerminal(): bool;
}
