<?php

declare(strict_types=1);

namespace Quraba\Backup\Notifications;

/** A stable, secret-free package event for operational failures. */
final readonly class OperationalNotice
{
    /** @param array<string, scalar|null> $details */
    public function __construct(
        public string $condition,
        public string $identity,
        public array $details = [],
    ) {}
}
