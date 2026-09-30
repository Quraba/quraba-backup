<?php

declare(strict_types=1);

namespace Quraba\Backup\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use Quraba\Backup\Domain\FailureDetails;
use Quraba\Backup\Domain\Identifiers;
use Quraba\Backup\Enums\ArtifactKind;
use Quraba\Backup\Enums\ArtifactStatus;
use Quraba\Backup\Enums\ArtifactStorage;
use Quraba\Backup\Exceptions\IllegalStateTransition;
use Quraba\Backup\Models\Casts\UtcDateTime;
use Quraba\Backup\Models\Concerns\HasControlledStatus;
use Quraba\Backup\Security\SecretRedactor;

/**
 * One physical artifact of a backup run.
 *
 * An artifact can only become VERIFIED together with its exact identity:
 * a full Restic snapshot ID for snapshots, or locator + SHA-256 + size for
 * stored objects. The catalog therefore never claims a verified artifact it
 * cannot point at.
 *
 * @property int $id
 * @property int $backup_run_id
 * @property ArtifactKind $kind
 * @property ArtifactStatus $status
 * @property ArtifactStorage $storage
 * @property string|null $locator
 * @property string|null $snapshot_id
 * @property string|null $sha256
 * @property int|null $byte_size
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable|null $expired_at
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read BackupRun $run
 */
final class BackupArtifact extends PackageModel
{
    use HasControlledStatus;

    protected $table = 'quraba_backup_artifacts';

    /** @var list<string> */
    protected $fillable = [];

    /**
     * @param  array<string, mixed>  $metadata
     *
     * @internal use {@see BackupRun::addArtifact()}
     */
    public static function createFor(BackupRun $run, ArtifactKind $kind, array $metadata = []): self
    {
        $artifact = new self;
        $artifact->setAttribute('backup_run_id', $run->getKey());
        $artifact->setAttribute('kind', $kind);
        $artifact->setAttribute('storage', $kind->storage());
        $artifact->setAttribute('metadata', app(SecretRedactor::class)->redactArray($metadata));
        $artifact->initializeStatus(ArtifactStatus::initial());
        $artifact->save();

        return $artifact;
    }

    /**
     * @return BelongsTo<BackupRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(BackupRun::class, 'backup_run_id');
    }

    /**
     * Records the deterministic remote locator before upload so an interrupted
     * upload can be reconciled against the exact path.
     */
    public function assignLocator(string $locator): self
    {
        if ($this->kind === ArtifactKind::ResticSnapshot) {
            throw new InvalidArgumentException('Restic snapshots are identified by snapshot ID, not by locator.');
        }

        if (! in_array($this->currentStatus(), [ArtifactStatus::Pending, ArtifactStatus::Creating, ArtifactStatus::Uploading], true)) {
            throw new IllegalStateTransition('A locator can only be assigned before verification starts.');
        }

        if ($this->locator !== null && $this->locator !== $locator) {
            throw new IllegalStateTransition('An artifact locator is immutable once assigned.');
        }

        $this->setAttribute('locator', Identifiers::assertObjectLocator($locator));
        $this->save();

        return $this;
    }

    public function markCreating(): self
    {
        return $this->transitionTo(ArtifactStatus::Creating);
    }

    public function markUploading(): self
    {
        return $this->transitionTo(ArtifactStatus::Uploading);
    }

    public function markVerifying(): self
    {
        return $this->transitionTo(ArtifactStatus::Verifying);
    }

    /**
     * Verified Restic snapshot, identified by its full canonical ID.
     */
    /**
     * @param  array<string, mixed>  $metadata  non-secret verification facts (kind, roots, repository)
     */
    public function markVerifiedSnapshot(string $snapshotId, array $metadata = []): self
    {
        if ($this->kind !== ArtifactKind::ResticSnapshot) {
            throw new InvalidArgumentException('Only restic_snapshot artifacts are verified by snapshot ID.');
        }

        return $this->transitionTo(ArtifactStatus::Verified, [
            'snapshot_id' => Identifiers::assertFullSnapshotId($snapshotId),
            'verified_at' => CarbonImmutable::now('UTC'),
            'metadata' => [...($this->metadata ?? []), ...app(SecretRedactor::class)->redactArray($metadata)],
        ]);
    }

    /**
     * Verified stored object (archive or manifest), identified by exact
     * locator, SHA-256 and size.
     */
    /**
     * @param  array<string, mixed>  $metadata  non-secret verification facts
     */
    public function markVerifiedObject(string $locator, string $sha256, int $byteSize, array $metadata = []): self
    {
        if ($this->kind === ArtifactKind::ResticSnapshot) {
            throw new InvalidArgumentException('Restic snapshots must be verified by snapshot ID.');
        }

        if ($byteSize <= 0) {
            throw new InvalidArgumentException('A verified object must have a positive byte size.');
        }

        if ($this->locator !== null && $this->locator !== $locator) {
            throw new IllegalStateTransition('The verified locator differs from the locator assigned before upload.');
        }

        return $this->transitionTo(ArtifactStatus::Verified, [
            'locator' => Identifiers::assertObjectLocator($locator),
            'sha256' => Identifiers::assertSha256($sha256),
            'byte_size' => $byteSize,
            'verified_at' => CarbonImmutable::now('UTC'),
            'metadata' => [...($this->metadata ?? []), ...app(SecretRedactor::class)->redactArray($metadata)],
        ]);
    }

    /**
     * Records non-secret facts gathered while the artifact is still in
     * progress (e.g. an incomplete snapshot ID that must never be adopted).
     *
     * @param  array<string, mixed>  $values
     */
    public function mergeMetadata(array $values): self
    {
        $this->setAttribute('metadata', [...($this->metadata ?? []), ...app(SecretRedactor::class)->redactArray($values)]);
        $this->save();

        return $this;
    }

    public function markFailed(FailureDetails $failure): self
    {
        return $this->transitionTo(ArtifactStatus::Failed, [
            'failure_code' => $failure->code,
            'failure_message' => $failure->message,
            'metadata' => [...($this->metadata ?? []), 'failure_stage' => $failure->stage],
        ]);
    }

    /**
     * Only called by retention after the artifact's absence was observed.
     */
    public function markExpired(): self
    {
        return $this->transitionTo(ArtifactStatus::Expired, ['expired_at' => CarbonImmutable::now('UTC')]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'kind' => ArtifactKind::class,
            'status' => ArtifactStatus::class,
            'storage' => ArtifactStorage::class,
            'byte_size' => 'integer',
            'verified_at' => UtcDateTime::class,
            'expired_at' => UtcDateTime::class,
            'metadata' => 'array',
        ];
    }
}
