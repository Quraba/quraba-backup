<?php

declare(strict_types=1);

namespace Quraba\Backup\Retention;

use Carbon\CarbonImmutable;

/**
 * One terminal backup run that still owns at least one verified,
 * non-expired physical artifact. Plain data: the planner never touches the
 * database or remote storage.
 */
final readonly class RetentionCandidate
{
    public function __construct(
        public string $runUuid,
        public string $family,
        public string $status,
        public string $trigger,
        public string $consistency,
        public CarbonImmutable $requestedAt,
        public ?CarbonImmutable $pinnedUntil,
        public ?string $archiveLocator,
        public ?string $snapshotId,
        public ?string $snapshotKind,
    ) {}

    /**
     * A complete Recovery Point: a completed recovery run whose archive and
     * media snapshot are both verified.
     */
    public function isCompleteRecoveryPoint(): bool
    {
        return $this->family === 'recovery' && $this->status === 'completed' && $this->archiveLocator !== null && $this->snapshotId !== null;
    }

    /**
     * Whether this run is a complete backup of its own family.
     */
    public function isComplete(): bool
    {
        return match ($this->family) {
            'recovery' => $this->isCompleteRecoveryPoint(),
            'database' => $this->status === 'completed' && $this->archiveLocator !== null,
            'media' => $this->status === 'completed' && $this->snapshotId !== null,
            default => false,
        };
    }

    /**
     * @return list<string>
     */
    public function components(): array
    {
        return array_values(array_filter([
            $this->archiveLocator !== null ? 'application_archive' : null,
            $this->snapshotId !== null ? 'media_snapshot' : null,
        ]));
    }

    /**
     * Newest first, deterministic on ties.
     */
    public static function newestFirst(self $a, self $b): int
    {
        return [$b->requestedAt->getTimestamp(), $b->runUuid] <=> [$a->requestedAt->getTimestamp(), $a->runUuid];
    }
}
