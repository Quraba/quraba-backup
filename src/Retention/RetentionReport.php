<?php

declare(strict_types=1);

namespace Quraba\Backup\Retention;

use Quraba\Backup\Domain\FailureDetails;
use Quraba\Backup\Enums\MaintenanceStatus;

/**
 * What a retention pass planned and — when executed — physically proved.
 */
final readonly class RetentionReport
{
    /**
     * @param  list<array<string, mixed>>  $expired  runs whose deletion was proven
     * @param  list<array<string, mixed>>  $lingering  catalog-expired artifacts still physically present
     * @param  list<array<string, mixed>>  $unknown  managed remote objects/snapshots the catalog does not know (never deleted)
     */
    public function __construct(
        public string $maintenanceRunUuid,
        public bool $executed,
        public RetentionPlan $plan,
        public array $expired,
        public array $lingering,
        public array $unknown,
        public ?string $inspectionError,
        public MaintenanceStatus $status,
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
            'mode' => $this->executed ? 'execute' : 'plan',
            'status' => $this->status->value,
            'plan' => $this->plan->toArray(),
            'expired' => $this->expired,
            'lingering' => $this->lingering,
            'unknown' => $this->unknown,
            'inspection_error' => $this->inspectionError,
            'failure' => $this->failure?->toArray(),
        ];
    }
}
