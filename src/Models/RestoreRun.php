<?php

declare(strict_types=1);

namespace Quraba\Backup\Models;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Quraba\Backup\Domain\FailureDetails;
use Quraba\Backup\Domain\Identifiers;
use Quraba\Backup\Enums\RestoreMode;
use Quraba\Backup\Enums\RestoreProfile;
use Quraba\Backup\Enums\RestoreStatus;
use Quraba\Backup\Exceptions\IllegalStateTransition;
use Quraba\Backup\Models\Casts\UtcDateTime;
use Quraba\Backup\Models\Concerns\HasControlledStatus;
use Quraba\Backup\Security\SecretRedactor;

/**
 * Catalog record of a restore (dry run or live).
 *
 * Restore execution arrives in a later phase; this batch provides the frozen
 * schema and the state rules it must obey:
 *  - a dry run can never enter a live-only state;
 *  - source identities are frozen once recorded;
 *  - after the destructive boundary, failure can only be recorded as
 *    INDETERMINATE until reconciliation proves otherwise.
 *
 * @property int $id
 * @property string $uuid
 * @property RestoreMode $mode
 * @property RestoreProfile $profile
 * @property RestoreStatus $status
 * @property string|null $source_run_uuid
 * @property string|null $source_archive_locator
 * @property string|null $source_archive_sha256
 * @property string|null $source_snapshot_id
 * @property string|null $pre_change_run_uuid
 * @property string|null $requested_by_type
 * @property string|null $requested_by_id
 * @property CarbonImmutable|null $destructive_started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $failed_at
 * @property string|null $failure_stage
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class RestoreRun extends PackageModel
{
    use HasControlledStatus;

    protected $table = 'quraba_restore_runs';

    /** @var list<string> */
    protected $fillable = [];

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function request(
        RestoreMode $mode,
        RestoreProfile $profile,
        string $sourceRunUuid,
        ?string $requestedByType = null,
        string|int|null $requestedById = null,
        array $metadata = [],
    ): self {
        $run = new self;
        $run->setAttribute('uuid', (string) Str::uuid7());
        $run->setAttribute('mode', $mode);
        $run->setAttribute('profile', $profile);
        $run->setAttribute('source_run_uuid', Identifiers::assertUuid($sourceRunUuid, 'The source run UUID'));
        $run->setAttribute('requested_by_type', $requestedByType === null ? null : mb_substr($requestedByType, 0, 255));
        $run->setAttribute('requested_by_id', $requestedById === null ? null : mb_substr((string) $requestedById, 0, 255));
        $run->setAttribute('metadata', app(SecretRedactor::class)->redactArray($metadata));
        $run->initializeStatus(RestoreStatus::initial());
        $run->save();

        return $run;
    }

    public function isDryRun(): bool
    {
        return $this->mode === RestoreMode::DryRun;
    }

    /** @param array<string, mixed> $values */
    public function mergeMetadata(array $values): self
    {
        $this->setAttribute('metadata', [...($this->metadata ?? []), ...app(SecretRedactor::class)->redactArray($values)]);
        $this->save();

        return $this;
    }

    public function markResolving(): self
    {
        return $this->transitionTo(RestoreStatus::Resolving);
    }

    /**
     * Freezes the exact source identities. They can be recorded once, while
     * resolving, and never changed afterwards.
     */
    public function freezeSource(?string $archiveLocator, ?string $archiveSha256, ?string $snapshotId): self
    {
        if ($this->currentStatus() !== RestoreStatus::Resolving) {
            throw new IllegalStateTransition('Restore sources can only be frozen while resolving.');
        }

        if ($this->source_archive_locator !== null || $this->source_archive_sha256 !== null || $this->source_snapshot_id !== null) {
            throw new IllegalStateTransition('Restore source identities are already frozen.');
        }

        if (($archiveLocator === null) !== ($archiveSha256 === null)) {
            throw new InvalidArgumentException('An archive source requires both locator and SHA-256.');
        }

        if ($archiveLocator === null && $snapshotId === null) {
            throw new InvalidArgumentException('A restore source needs an archive, a snapshot, or both.');
        }

        $this->setAttribute('source_archive_locator', $archiveLocator === null ? null : Identifiers::assertObjectLocator($archiveLocator));
        $this->setAttribute('source_archive_sha256', $archiveSha256 === null ? null : Identifiers::assertSha256($archiveSha256));
        $this->setAttribute('source_snapshot_id', $snapshotId === null ? null : Identifiers::assertFullSnapshotId($snapshotId));
        $this->save();

        return $this;
    }

    public function markReconstructing(): self
    {
        return $this->transitionTo(RestoreStatus::Reconstructing);
    }

    public function markValidating(): self
    {
        return $this->transitionTo(RestoreStatus::Validating);
    }

    public function markSafetyBackup(string $preChangeRunUuid): self
    {
        $this->assertLive(RestoreStatus::SafetyBackup);

        if ($this->pre_change_run_uuid !== null && $this->pre_change_run_uuid !== $preChangeRunUuid) {
            throw new IllegalStateTransition('The pre-change safety backup of a restore cannot be replaced.');
        }

        return $this->transitionTo(RestoreStatus::SafetyBackup, [
            'pre_change_run_uuid' => Identifiers::assertUuid($preChangeRunUuid, 'The pre-change run UUID'),
        ]);
    }

    public function markQuiescing(): self
    {
        $this->assertLive(RestoreStatus::Quiescing);

        return $this->transitionTo(RestoreStatus::Quiescing);
    }

    public function markApplying(): self
    {
        $this->assertLive(RestoreStatus::Applying);

        if ($this->pre_change_run_uuid === null) {
            throw new IllegalStateTransition('A live restore cannot apply changes before a pre-change safety backup is recorded.');
        }

        return $this->transitionTo(RestoreStatus::Applying);
    }

    /**
     * Records that the first destructive mutation is about to happen. From
     * here on a failure can only be recorded as indeterminate.
     */
    public function markDestructiveStarted(): self
    {
        if ($this->currentStatus() !== RestoreStatus::Applying) {
            throw new IllegalStateTransition('The destructive boundary can only be crossed while applying.');
        }

        if ($this->destructive_started_at !== null) {
            return $this;
        }

        $this->setAttribute('destructive_started_at', CarbonImmutable::now('UTC'));
        $this->save();

        return $this;
    }

    public function hasCrossedDestructiveBoundary(): bool
    {
        return $this->destructive_started_at !== null;
    }

    public function markVerifying(): self
    {
        return $this->transitionTo(RestoreStatus::Verifying);
    }

    public function markCompleted(): self
    {
        return $this->transitionTo(RestoreStatus::Completed, ['completed_at' => CarbonImmutable::now('UTC')]);
    }

    public function markFailed(FailureDetails $failure): self
    {
        if ($this->hasCrossedDestructiveBoundary()) {
            throw new IllegalStateTransition('A restore that crossed its destructive boundary must be marked indeterminate, not failed; reconciliation decides the outcome.');
        }

        return $this->transitionTo(RestoreStatus::Failed, [
            'failed_at' => CarbonImmutable::now('UTC'),
            ...$this->failureAttributes($failure),
        ]);
    }

    public function markIndeterminate(FailureDetails $reason): self
    {
        return $this->transitionTo(RestoreStatus::Indeterminate, $this->failureAttributes($reason));
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    public function resolveIndeterminate(RestoreStatus $outcome, array $evidence, ?FailureDetails $failure = null): self
    {
        if ($this->currentStatus() !== RestoreStatus::Indeterminate) {
            throw new IllegalStateTransition(sprintf('Restore [%s] is not indeterminate.', $this->uuid));
        }

        if (! in_array($outcome, RestoreStatus::reconciliationOutcomes(), true)) {
            throw new IllegalStateTransition(sprintf('Reconciliation cannot resolve a restore to [%s].', $outcome->value));
        }

        if ($evidence === []) {
            throw new InvalidArgumentException('Reconciliation requires evidence.');
        }

        if ($outcome === RestoreStatus::Failed && $failure === null) {
            throw new InvalidArgumentException('A failed reconciliation outcome requires failure details.');
        }

        $now = CarbonImmutable::now('UTC');
        $metadata = $this->metadata ?? [];
        $metadata['reconciliation'] = [
            'resolved_at' => $now->toIso8601ZuluString(),
            'outcome' => $outcome->value,
            'evidence' => app(SecretRedactor::class)->redactArray($evidence),
        ];

        $attributes = ['metadata' => $metadata];
        $attributes[$outcome === RestoreStatus::Failed ? 'failed_at' : 'completed_at'] = $now;

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
            'mode' => RestoreMode::class,
            'profile' => RestoreProfile::class,
            'status' => RestoreStatus::class,
            'destructive_started_at' => UtcDateTime::class,
            'completed_at' => UtcDateTime::class,
            'failed_at' => UtcDateTime::class,
            'metadata' => 'array',
        ];
    }

    private function assertLive(RestoreStatus $target): void
    {
        if ($this->isDryRun()) {
            throw new IllegalStateTransition(sprintf('A dry-run restore can never enter the live-only state [%s].', $target->value));
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
