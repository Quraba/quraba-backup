<?php

declare(strict_types=1);

namespace Quraba\Backup\Retention;

/**
 * The planner's verdict for one run: keep (with every reason) or expire.
 */
final readonly class RetentionDecision
{
    /**
     * @param  list<string>  $reasons  why it is kept (empty when expired)
     */
    public function __construct(
        public RetentionCandidate $candidate,
        public bool $expire,
        public array $reasons,
    ) {}

    public function isProtected(): bool
    {
        foreach ($this->reasons as $reason) {
            if (str_starts_with($reason, 'protected:')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $candidate = $this->candidate;

        return [
            'run_uuid' => $candidate->runUuid,
            'family' => $candidate->family,
            'status' => $candidate->status,
            'requested_at' => $candidate->requestedAt->toIso8601ZuluString(),
            'decision' => $this->expire ? 'expire' : 'keep',
            'reasons' => $this->reasons,
            'complete' => $candidate->isComplete(),
            'archive_locator' => $candidate->archiveLocator,
            'snapshot_id' => $candidate->snapshotId,
        ];
    }
}
