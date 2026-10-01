<?php

declare(strict_types=1);

namespace Quraba\Backup\Maintenance;

use Quraba\Backup\Domain\FailureDetails;
use Quraba\Backup\Enums\MaintenanceOperation;
use Quraba\Backup\Enums\MaintenanceStatus;

/**
 * The audited result of a Restic maintenance command (check, prune).
 */
final readonly class MaintenanceOutcome
{
    /**
     * @param  list<string>  $summary  sanitized last lines of Restic's output
     */
    public function __construct(
        public string $maintenanceRunUuid,
        public MaintenanceOperation $operation,
        public bool $dryRun,
        public string $mode,
        public MaintenanceStatus $status,
        public array $summary,
        public ?FailureDetails $failure,
    ) {}

    public function succeeded(): bool
    {
        return $this->status === MaintenanceStatus::Completed;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'maintenance_run_uuid' => $this->maintenanceRunUuid,
            'operation' => $this->operation->value,
            'mode' => $this->mode,
            'dry_run' => $this->dryRun,
            'status' => $this->status->value,
            'summary' => $this->summary,
            'failure' => $this->failure?->toArray(),
        ];
    }
}
