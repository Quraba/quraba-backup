<?php

declare(strict_types=1);

namespace Quraba\Backup\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Quraba\Backup\Domain\FailureDetails;
use Quraba\Backup\Enums\ArtifactKind;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Enums\BackupStatus;
use Quraba\Backup\Enums\BackupTrigger;
use Quraba\Backup\Enums\ConsistencyLevel;
use Quraba\Backup\Exceptions\IllegalStateTransition;
use Quraba\Backup\Models\Casts\UtcDateTime;
use Quraba\Backup\Models\Concerns\HasControlledStatus;
use Quraba\Backup\Security\SecretRedactor;

/**
 * One logical backup operation, identified by an immutable run UUID.
 *
 * @property int $id
 * @property string $uuid
 * @property BackupProfile $profile
 * @property BackupTrigger $trigger
 * @property BackupStatus $status
 * @property ConsistencyLevel $consistency
 * @property CarbonImmutable|null $requested_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $failed_at
 * @property string|null $failure_stage
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property CarbonImmutable|null $pinned_until
 * @property string|null $pin_reason
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class BackupRun extends PackageModel
{
    use HasControlledStatus;

    protected $table = 'quraba_backup_runs';

    /** @var list<string> */
    protected $fillable = [];

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function request(
        BackupProfile $profile,
        BackupTrigger $trigger,
        ConsistencyLevel $consistency = ConsistencyLevel::None,
        array $metadata = [],
    ): self {
        $run = new self;
        $run->setAttribute('uuid', (string) Str::uuid7());
        $run->setAttribute('profile', $profile);
        $run->setAttribute('trigger', $trigger);
        $run->setAttribute('consistency', $consistency);
        $run->setAttribute('requested_at', CarbonImmutable::now('UTC'));
        $run->setAttribute('metadata', app(SecretRedactor::class)->redactArray($metadata));
        $run->initializeStatus(BackupStatus::initial());
        $run->save();

        return $run;
    }

    /**
     * @return HasMany<BackupArtifact, $this>
     */
    public function artifacts(): HasMany
    {
        return $this->hasMany(BackupArtifact::class, 'backup_run_id');
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function addArtifact(ArtifactKind $kind, array $metadata = []): BackupArtifact
    {
        // Indeterminate runs may still gain artifacts (e.g. a manifest written
        // by reconciliation); every other terminal run is closed.
        if ($this->status->isTerminal() && $this->status !== BackupStatus::Indeterminate) {
            throw new IllegalStateTransition(sprintf('Cannot add artifacts to terminal backup run [%s].', $this->uuid));
        }

        return BackupArtifact::createFor($this, $kind, $metadata);
    }

    /**
     * Records the consistency actually achieved, which is only known once the
     * quiescence provider has been entered. Allowed only while the run is
     * active, so a finished run's claim can never be upgraded afterwards.
     */
    public function recordConsistency(ConsistencyLevel $consistency, string $explanation): self
    {
        if (! $this->status->isActive()) {
            throw new IllegalStateTransition('Consistency can only be recorded while a backup run is active.');
        }

        $this->setAttribute('consistency', $consistency);
        $this->setAttribute('metadata', array_replace_recursive($this->metadata ?? [], [
            'consistency_explanation' => app(SecretRedactor::class)->redact(mb_substr($explanation, 0, 500)),
        ]));
        $this->save();

        return $this;
    }

    public function markPreflighting(): self
    {
        return $this->transitionTo(BackupStatus::Preflighting, ['started_at' => CarbonImmutable::now('UTC')]);
    }

    public function markRunning(): self
    {
        return $this->transitionTo(BackupStatus::Running);
    }

    public function markVerifying(): self
    {
        return $this->transitionTo(BackupStatus::Verifying);
    }

    public function markCompleted(): self
    {
        return $this->transitionTo(BackupStatus::Completed, ['completed_at' => CarbonImmutable::now('UTC')]);
    }

    /**
     * Some components verified, at least one did not. The failure describes
     * the component that failed; valid components stay visible.
     */
    public function markPartial(FailureDetails $componentFailure): self
    {
        return $this->transitionTo(BackupStatus::Partial, [
            'completed_at' => CarbonImmutable::now('UTC'),
            ...$this->failureAttributes($componentFailure),
        ]);
    }

    public function markFailed(FailureDetails $failure): self
    {
        return $this->transitionTo(BackupStatus::Failed, [
            'failed_at' => CarbonImmutable::now('UTC'),
            ...$this->failureAttributes($failure),
        ]);
    }

    /**
     * Only possible before any work started; afterwards artifacts may exist.
     */
    public function markCanceled(FailureDetails $reason): self
    {
        return $this->transitionTo(BackupStatus::Canceled, [
            'completed_at' => CarbonImmutable::now('UTC'),
            ...$this->failureAttributes($reason),
        ]);
    }

    /**
     * The package cannot prove the physical outcome (e.g. the process died
     * after Restic may have written a snapshot). Reconciliation decides later.
     */
    public function markIndeterminate(FailureDetails $reason): self
    {
        return $this->transitionTo(BackupStatus::Indeterminate, $this->failureAttributes($reason));
    }

    /**
     * Resolves an indeterminate run once reconciliation has physical evidence.
     *
     * @param  array<string, mixed>  $evidence  non-secret facts that justify the outcome
     */
    public function resolveIndeterminate(BackupStatus $outcome, array $evidence, ?FailureDetails $failure = null): self
    {
        if ($this->currentStatus() !== BackupStatus::Indeterminate) {
            throw new IllegalStateTransition(sprintf('Backup run [%s] is not indeterminate.', $this->uuid));
        }

        if (! in_array($outcome, BackupStatus::reconciliationOutcomes(), true)) {
            throw new IllegalStateTransition(sprintf('Reconciliation cannot resolve a backup run to [%s].', $outcome->value));
        }

        if ($outcome !== BackupStatus::Completed && $failure === null) {
            throw new InvalidArgumentException('A partial or failed reconciliation outcome requires failure details.');
        }

        if ($evidence === []) {
            throw new InvalidArgumentException('Reconciliation requires evidence.');
        }

        $now = CarbonImmutable::now('UTC');
        $metadata = $this->metadata ?? [];
        $metadata['reconciliation'] = [
            'resolved_at' => $now->toIso8601ZuluString(),
            'outcome' => $outcome->value,
            'evidence' => app(SecretRedactor::class)->redactArray($evidence),
        ];

        $attributes = ['metadata' => $metadata];

        if ($outcome === BackupStatus::Failed) {
            $attributes['failed_at'] = $now;
        } else {
            $attributes['completed_at'] = $now;
        }

        if ($failure !== null) {
            $attributes = [...$attributes, ...$this->failureAttributes($failure)];
        } else {
            $attributes = [...$attributes, 'failure_stage' => null, 'failure_code' => null, 'failure_message' => null];
        }

        return $this->forceReconciledStatus($outcome, $attributes);
    }

    /**
     * Merges non-secret metadata; values pass through the redactor.
     *
     * @param  array<string, mixed>  $values
     */
    public function mergeMetadata(array $values): self
    {
        $this->setAttribute('metadata', array_replace_recursive(
            $this->metadata ?? [],
            app(SecretRedactor::class)->redactArray($values),
        ));
        $this->save();

        return $this;
    }

    public function pin(DateTimeInterface $until, string $reason): self
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('A pin reason of 1 to 255 characters is required.');
        }

        $this->setAttribute('pinned_until', $until);
        $this->setAttribute('pin_reason', $reason);
        $this->save();

        return $this;
    }

    public function unpin(): self
    {
        $this->setAttribute('pinned_until', null);
        $this->setAttribute('pin_reason', null);
        $this->save();

        return $this;
    }

    public function isPinned(?DateTimeInterface $at = null): bool
    {
        $at = CarbonImmutable::instance($at ?? CarbonImmutable::now('UTC'));

        return $this->pinned_until !== null && $this->pinned_until->greaterThan($at);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'profile' => BackupProfile::class,
            'trigger' => BackupTrigger::class,
            'status' => BackupStatus::class,
            'consistency' => ConsistencyLevel::class,
            'requested_at' => UtcDateTime::class,
            'started_at' => UtcDateTime::class,
            'completed_at' => UtcDateTime::class,
            'failed_at' => UtcDateTime::class,
            'pinned_until' => UtcDateTime::class,
            'metadata' => 'array',
        ];
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
