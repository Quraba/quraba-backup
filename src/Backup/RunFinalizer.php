<?php

declare(strict_types=1);

namespace Quraba\Backup\Backup;

use Quraba\Backup\Domain\FailureDetails;
use Quraba\Backup\Enums\ArtifactKind;
use Quraba\Backup\Enums\ArtifactStatus;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Enums\BackupStatus;
use Quraba\Backup\Exceptions\ManifestStoreFailed;
use Quraba\Backup\Identity\ApplicationIdentity;
use Quraba\Backup\Manifest\ManifestBuilder;
use Quraba\Backup\Manifest\ManifestStore;
use Quraba\Backup\Models\BackupArtifact;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Security\SecretRedactor;
use Throwable;

/**
 * Decides a run's terminal state from its component outcomes and publishes
 * the manifest — the single set of rules shared by the BackupManager and the
 * BackupReconciler.
 *
 *  - any uncertain component → indeterminate, no manifest (it could not tell
 *    the truth yet);
 *  - no verified component → failed, no manifest;
 *  - otherwise the manifest is written (immutable, idempotent) FIRST, and
 *    only then does the catalog become completed (all required components
 *    verified) or partial. The catalog never leads physical truth.
 *  - a manifest that cannot be written leaves the run indeterminate for
 *    reconciliation; a manifest collision is never overwritten.
 */
final readonly class RunFinalizer
{
    public function __construct(
        private ManifestStore $manifests,
        private SecretRedactor $redactor,
    ) {}

    /**
     * @param  array<string, ComponentOutcome>  $components  keyed by artifact kind value
     * @param  array<string, mixed>  $evidence  reconciliation evidence (required when resolving an indeterminate run)
     */
    public function finalize(BackupRun $run, ApplicationIdentity $identity, array $components, array $evidence = []): FinalizedRun
    {
        $failures = array_values(array_filter(array_map(static fn (ComponentOutcome $c): ?FailureDetails => $c->failure, $components)));
        $firstFailure = $failures[0] ?? null;

        foreach ($components as $outcome) {
            if ($outcome->state === ComponentOutcome::UNCERTAIN && $outcome->failure !== null) {
                $this->toIndeterminate($run, $outcome->failure);

                return new FinalizedRun(BackupStatus::Indeterminate, null);
            }
        }

        $verified = array_filter($components, static fn (ComponentOutcome $c): bool => $c->isVerified());

        if ($verified === []) {
            $failure = $firstFailure ?? $this->failure('backup.no_component', 'finalize', 'No backup component produced a verified artifact.');
            $this->terminal($run, BackupStatus::Failed, $failure, $evidence);

            return new FinalizedRun(BackupStatus::Failed, null);
        }

        $outcome = count($verified) === count(self::requiredKinds($run->profile)) ? BackupStatus::Completed : BackupStatus::Partial;

        if ($run->status === BackupStatus::Running) {
            $run->markVerifying();
        }

        try {
            $locator = $this->publishManifest($run, $identity, $outcome);
        } catch (Throwable $exception) {
            $this->toIndeterminate($run, FailureDetails::fromThrowable($exception, 'manifest', $this->redactor));

            return new FinalizedRun(BackupStatus::Indeterminate, null);
        }

        $this->terminal($run, $outcome, $outcome === BackupStatus::Partial ? $firstFailure : null, $evidence);

        return new FinalizedRun($outcome, $locator);
    }

    /**
     * @return list<ArtifactKind>
     */
    public static function requiredKinds(BackupProfile $profile): array
    {
        return match ($profile) {
            BackupProfile::Database => [ArtifactKind::ApplicationArchive],
            BackupProfile::Media => [ArtifactKind::ResticSnapshot],
            BackupProfile::Recovery => [ArtifactKind::ApplicationArchive, ArtifactKind::ResticSnapshot],
        };
    }

    private function publishManifest(BackupRun $run, ApplicationIdentity $identity, BackupStatus $outcome): string
    {
        $json = ManifestBuilder::encode(ManifestBuilder::build($run, $identity, $outcome));

        $artifact = $run->artifacts()->where('kind', ArtifactKind::RemoteManifest->value)->first()
            ?? $run->addArtifact(ArtifactKind::RemoteManifest);

        if ($artifact->status === ArtifactStatus::Verified) {
            return (string) $artifact->locator;
        }

        if ($artifact->status === ArtifactStatus::Failed) {
            throw new ManifestStoreFailed('The manifest of this run previously failed permanently (e.g. a collision) and needs operator attention.');
        }

        $this->advanceTo($artifact, ArtifactStatus::Uploading);
        $artifact->assignLocator($this->manifests->locatorFor($run));

        try {
            $stored = $this->manifests->put($run, $json);
        } catch (ManifestStoreFailed $exception) {
            if ($exception->failureCode() === 'manifest.collision') {
                $artifact->markFailed(FailureDetails::fromThrowable($exception, 'manifest', $this->redactor));
            }

            throw $exception;
        }

        $artifact->markVerifying();
        $artifact->markVerifiedObject($stored->locator, $stored->sha256, $stored->bytes, ['adopted' => $stored->adopted, 'schema_version' => ManifestBuilder::SCHEMA_VERSION]);

        return $stored->locator;
    }

    private function advanceTo(BackupArtifact $artifact, ArtifactStatus $target): void
    {
        if ($artifact->status === ArtifactStatus::Pending) {
            $artifact->markCreating();
        }

        if ($target === ArtifactStatus::Uploading && $artifact->status === ArtifactStatus::Creating) {
            $artifact->markUploading();
        }
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function terminal(BackupRun $run, BackupStatus $status, ?FailureDetails $failure, array $evidence): void
    {
        if ($run->status === BackupStatus::Indeterminate) {
            $run->resolveIndeterminate($status, $evidence === [] ? ['finalized_by' => 'backup_manager'] : $evidence, $status === BackupStatus::Completed ? null : $failure);

            return;
        }

        match ($status) {
            BackupStatus::Completed => $run->markCompleted(),
            BackupStatus::Partial => $run->markPartial($failure ?? $this->failure('backup.partial', 'finalize', 'At least one required component is missing.')),
            default => $run->markFailed($failure ?? $this->failure('backup.failed', 'finalize', 'The backup failed.')),
        };
    }

    private function toIndeterminate(BackupRun $run, FailureDetails $failure): void
    {
        if ($run->status !== BackupStatus::Indeterminate) {
            $run->markIndeterminate($failure);
        }
    }

    private function failure(string $code, string $stage, string $message): FailureDetails
    {
        return FailureDetails::make($code, $stage, $message, $this->redactor);
    }
}
