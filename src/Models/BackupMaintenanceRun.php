<?php

declare(strict_types=1);

namespace Quraba\Backup\Models;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Quraba\Backup\Domain\FailureDetails;
use Quraba\Backup\Enums\MaintenanceOperation;
use Quraba\Backup\Enums\MaintenanceStatus;
use Quraba\Backup\Exceptions\IllegalStateTransition;
use Quraba\Backup\Models\Casts\UtcDateTime;
use Quraba\Backup\Models\Concerns\HasControlledStatus;
use Quraba\Backup\Security\SecretRedactor;

/**
 * Audit record for maintenance (reconciliation, retention, check, prune).
 *
 * A dry run can record what it planned but can never record affected items.
 *
 * @property int $id
 * @property string $uuid
 * @property MaintenanceOperation $operation
 * @property MaintenanceStatus $status
 * @property bool $dry_run
 * @property list<mixed>|null $planned_items
 * @property list<mixed>|null $affected_items
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property string|null $failure_stage
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class BackupMaintenanceRun extends PackageModel
{
    use HasControlledStatus;

    protected $table = 'quraba_backup_maintenance_runs';

    /** @var list<string> */
    protected $fillable = [];

    /**
     * @param  list<mixed>  $plannedItems
     * @param  array<string, mixed>  $metadata
     */
    public static function plan(MaintenanceOperation $operation, bool $dryRun, array $plannedItems = [], array $metadata = []): self
    {
        $redactor = app(SecretRedactor::class);

        $run = new self;
        $run->setAttribute('uuid', (string) Str::uuid7());
        $run->setAttribute('operation', $operation);
        $run->setAttribute('dry_run', $dryRun);
        $run->setAttribute('planned_items', array_values($redactor->redactArray($plannedItems)));
        $run->setAttribute('affected_items', []);
        $run->setAttribute('metadata', $redactor->redactArray($metadata));
        $run->initializeStatus(MaintenanceStatus::initial());
        $run->save();

        return $run;
    }

    public function markRunning(): self
    {
        return $this->transitionTo(MaintenanceStatus::Running, ['started_at' => CarbonImmutable::now('UTC')]);
    }

    /**
     * @param  list<mixed>  $affectedItems  items whose change was physically observed
     */
    public function markCompleted(array $affectedItems = []): self
    {
        if ($this->dry_run && $affectedItems !== []) {
            throw new IllegalStateTransition('A dry-run maintenance run cannot report affected items.');
        }

        return $this->transitionTo(MaintenanceStatus::Completed, [
            'finished_at' => CarbonImmutable::now('UTC'),
            'affected_items' => array_values(app(SecretRedactor::class)->redactArray($affectedItems)),
        ]);
    }

    /**
     * @param  list<mixed>  $affectedItems  changes physically proven before the failure
     */
    public function markFailed(FailureDetails $failure, array $affectedItems = []): self
    {
        $this->assertAffectable($affectedItems);

        return $this->transitionTo(MaintenanceStatus::Failed, [
            'finished_at' => CarbonImmutable::now('UTC'),
            'affected_items' => array_values(app(SecretRedactor::class)->redactArray($affectedItems)),
            ...$this->failureAttributes($failure),
        ]);
    }

    public function markCanceled(FailureDetails $reason): self
    {
        return $this->transitionTo(MaintenanceStatus::Canceled, [
            'finished_at' => CarbonImmutable::now('UTC'),
            ...$this->failureAttributes($reason),
        ]);
    }

    /**
     * @param  list<mixed>  $affectedItems  changes physically proven before the uncertainty
     */
    public function markIndeterminate(FailureDetails $reason, array $affectedItems = []): self
    {
        $this->assertAffectable($affectedItems);

        return $this->transitionTo(MaintenanceStatus::Indeterminate, [
            'affected_items' => array_values(app(SecretRedactor::class)->redactArray($affectedItems)),
            ...$this->failureAttributes($reason),
        ]);
    }

    /**
     * Records non-secret facts (inspection results, plan summaries).
     *
     * @param  array<string, mixed>  $values
     */
    public function mergeMetadata(array $values): self
    {
        $this->setAttribute('metadata', [...($this->metadata ?? []), ...app(SecretRedactor::class)->redactArray($values)]);
        $this->save();

        return $this;
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @param  list<mixed>  $affectedItems
     */
    public function resolveIndeterminate(MaintenanceStatus $outcome, array $evidence, array $affectedItems = [], ?FailureDetails $failure = null): self
    {
        if ($this->currentStatus() !== MaintenanceStatus::Indeterminate) {
            throw new IllegalStateTransition(sprintf('Maintenance run [%s] is not indeterminate.', $this->uuid));
        }

        if (! in_array($outcome, MaintenanceStatus::reconciliationOutcomes(), true)) {
            throw new IllegalStateTransition(sprintf('Reconciliation cannot resolve a maintenance run to [%s].', $outcome->value));
        }

        if ($evidence === []) {
            throw new InvalidArgumentException('Reconciliation requires evidence.');
        }

        if ($outcome === MaintenanceStatus::Failed && $failure === null) {
            throw new InvalidArgumentException('A failed reconciliation outcome requires failure details.');
        }

        if ($this->dry_run && $affectedItems !== []) {
            throw new IllegalStateTransition('A dry-run maintenance run cannot report affected items.');
        }

        $redactor = app(SecretRedactor::class);
        $metadata = $this->metadata ?? [];
        $metadata['reconciliation'] = [
            'resolved_at' => CarbonImmutable::now('UTC')->toIso8601ZuluString(),
            'outcome' => $outcome->value,
            'evidence' => $redactor->redactArray($evidence),
        ];

        $attributes = [
            'metadata' => $metadata,
            'finished_at' => CarbonImmutable::now('UTC'),
            'affected_items' => array_values($redactor->redactArray($affectedItems)),
        ];

        if ($failure !== null) {
            $attributes = [...$attributes, ...$this->failureAttributes($failure)];
        }

        return $this->forceReconciledStatus($outcome, $attributes);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'operation' => MaintenanceOperation::class,
            'status' => MaintenanceStatus::class,
            'dry_run' => 'boolean',
            'planned_items' => 'array',
            'affected_items' => 'array',
            'started_at' => UtcDateTime::class,
            'finished_at' => UtcDateTime::class,
            'metadata' => 'array',
        ];
    }

    /**
     * @param  list<mixed>  $affectedItems
     */
    private function assertAffectable(array $affectedItems): void
    {
        if ($this->dry_run && $affectedItems !== []) {
            throw new IllegalStateTransition('A dry-run maintenance run cannot report affected items.');
        }
    }

    /**
     * @return array{failure_stage: string, failure_code: string, failure_message: string}
     */
    private function failureAttributes(FailureDetails $failure): array
    {
        return [
            'failure_stage' => $failure->stage,
            'failure_code' => $failure->code,
            'failure_message' => $failure->message,
        ];
    }
}
