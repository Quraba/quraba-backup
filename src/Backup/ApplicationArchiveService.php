<?php

declare(strict_types=1);

namespace Quraba\Backup\Backup;

use Illuminate\Contracts\Config\Repository;
use Quraba\Backup\Archive\ArchiveRequest;
use Quraba\Backup\Archive\ArchiveStore;
use Quraba\Backup\Archive\ArchiveVerification;
use Quraba\Backup\Archive\ArchiveVerifier;
use Quraba\Backup\Contracts\ArchiveEngine;
use Quraba\Backup\Contracts\DatabaseDumper;
use Quraba\Backup\Domain\FailureDetails;
use Quraba\Backup\Enums\ArtifactKind;
use Quraba\Backup\Enums\ArtifactStatus;
use Quraba\Backup\Exceptions\ArchiveCreationFailed;
use Quraba\Backup\Exceptions\ArchiveUploadFailed;
use Quraba\Backup\Exceptions\IllegalStateTransition;
use Quraba\Backup\Exceptions\StorageUnavailable;
use Quraba\Backup\Identity\ApplicationIdentity;
use Quraba\Backup\Models\BackupArtifact;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Security\SecretRedactor;
use Quraba\Backup\Support\PackageVersion;
use Quraba\Backup\Workspace\OperationWorkspace;
use Quraba\Backup\Workspace\WorkspaceArea;
use Throwable;

/**
 * The application archive component: create (engine) → verify locally →
 * upload or adopt at the deterministic path → prove remotely → mark the
 * artifact verified with its exact locator, SHA-256 and size.
 */
final readonly class ApplicationArchiveService
{
    public function __construct(
        private ArchiveEngine $engine,
        private ArchiveVerifier $verifier,
        private ArchiveStore $store,
        private DatabaseDumper $dumper,
        private Repository $config,
        private SecretRedactor $redactor,
        private string $envPath,
    ) {}

    public function includesEnv(): bool
    {
        return (bool) $this->config->get('quraba-backup.archive.include_env', true);
    }

    /**
     * Cheap readiness checks that must pass before any data is produced.
     */
    public function assertReady(): void
    {
        if ($this->password() === '') {
            throw ArchiveCreationFailed::passwordMissing();
        }

        if (! $this->engine->supportsStrongEncryption()) {
            throw ArchiveCreationFailed::encryptionUnsupported();
        }

        if ($this->includesEnv() && (! is_file($this->envPath) || ! is_readable($this->envPath))) {
            throw new ArchiveCreationFailed(sprintf('The .env file [%s] is missing or unreadable.', $this->envPath));
        }

        $this->dumper->connectionName();
        $this->store->assertConfigured();
    }

    public function create(BackupRun $run, BackupArtifact $artifact, ApplicationIdentity $identity, OperationWorkspace $workspace): ComponentOutcome
    {
        $this->assertArtifact($run, $artifact);

        try {
            $artifact->markCreating();

            $created = $this->engine->create(new ArchiveRequest(
                runUuid: $run->uuid,
                profile: $run->profile,
                identity: $identity,
                workspace: $workspace,
                databaseConnection: $this->dumper->connectionName(),
                envPath: $this->envPath,
                packageVersion: PackageVersion::current(),
                password: $this->password(),
            ));

            $local = $this->verifier->verify($created->path, $this->password(), $run->uuid, $identity, $this->includesEnv());

            $artifact->assignLocator($this->store->locatorFor($run));
            $artifact->markUploading();
        } catch (Throwable $exception) {
            // Nothing has left the workspace: a definite failure.
            return $this->failed($artifact, $exception, 'archive.create');
        }

        try {
            $stored = $this->store->store($run, $local);
        } catch (ArchiveUploadFailed $exception) {
            if ($exception->failureCode() === 'archive.collision') {
                return $this->failed($artifact, $exception, 'archive.upload');
            }

            return $this->afterUploadError($run, $artifact, $local, $exception);
        } catch (StorageUnavailable $exception) {
            return $this->afterUploadError($run, $artifact, $local, $exception);
        }

        return $this->markVerified($artifact, $stored->locator, $stored->sha256, $stored->bytes, $stored->adopted, $local);
    }

    /**
     * Reconciliation: adopts the object at this run's deterministic path when
     * it downloads, decrypts and proves to be this run's archive. Returns
     * null when no object exists.
     */
    public function adoptRemote(BackupRun $run, BackupArtifact $artifact, ApplicationIdentity $identity, OperationWorkspace $workspace): ?ComponentOutcome
    {
        $this->assertArtifact($run, $artifact);

        if (! $this->store->exists($run)) {
            return null;
        }

        $locator = $this->store->locatorFor($run);
        $downloaded = $workspace->path(WorkspaceArea::Archive, 'remote-application.zip');

        try {
            $sha256 = $this->store->download($locator, $downloaded);
            $verified = $this->verifier->verify($downloaded, $this->password(), $run->uuid, $identity, $this->includesEnv());

            if (! hash_equals($sha256, $verified->sha256)) {
                throw new ArchiveUploadFailed('The downloaded archive changed while it was being verified.');
            }

            if ($artifact->sha256 !== null && ! hash_equals($artifact->sha256, $sha256)) {
                throw ArchiveUploadFailed::collision('the remote archive does not match the SHA-256 recorded for this run');
            }

            $this->store->proveRemote($locator, $verified->bytes);
        } catch (Throwable $exception) {
            return $this->failed($artifact, $exception, 'reconcile.archive');
        } finally {
            if (is_file($downloaded)) {
                @unlink($downloaded);
            }
        }

        if ($artifact->locator === null && in_array($artifact->status, [ArtifactStatus::Pending, ArtifactStatus::Creating, ArtifactStatus::Uploading], true)) {
            $artifact->assignLocator($locator);
        }

        return $this->markVerified($artifact, $locator, $verified->sha256, $verified->bytes, true, $verified);
    }

    public function remoteExists(BackupRun $run): bool
    {
        return $this->store->exists($run);
    }

    private function afterUploadError(BackupRun $run, BackupArtifact $artifact, ArchiveVerification $local, Throwable $exception): ComponentOutcome
    {
        // The upload may or may not have landed. One more idempotent attempt:
        // it adopts an identical object or uploads again; a mismatch is a collision.
        try {
            $stored = $this->store->store($run, $local);

            return $this->markVerified($artifact, $stored->locator, $stored->sha256, $stored->bytes, $stored->adopted, $local);
        } catch (ArchiveUploadFailed $retry) {
            if ($retry->failureCode() === 'archive.collision') {
                return $this->failed($artifact, $retry, 'archive.upload');
            }
        } catch (Throwable) {
            // Fall through: state unknown.
        }

        try {
            if (! $this->store->exists($run)) {
                return $this->failed($artifact, $exception, 'archive.upload');
            }
        } catch (Throwable) {
            // Remote state cannot be observed.
        }

        return ComponentOutcome::uncertain(FailureDetails::fromThrowable($exception, 'archive.upload', $this->redactor));
    }

    private function markVerified(BackupArtifact $artifact, string $locator, string $sha256, int $bytes, bool $adopted, ArchiveVerification $verification): ComponentOutcome
    {
        if ($artifact->status !== ArtifactStatus::Verifying) {
            $artifact->markVerifying();
        }

        $artifact->markVerifiedObject($locator, $sha256, $bytes, [
            'adopted' => $adopted,
            'entries' => $verification->entries,
            'encryption' => $verification->encryption,
            'metadata_schema_version' => $verification->metadata['schema_version'] ?? null,
            'app_key_fingerprint' => $verification->metadata['app_key_fingerprint'] ?? null,
            'database' => array_intersect_key(
                is_array($verification->metadata['database'] ?? null) ? $verification->metadata['database'] : [],
                array_flip(['driver', 'flavor', 'server_version', 'migration_fingerprint', 'schema_fingerprint']),
            ),
        ]);

        return ComponentOutcome::verified($locator);
    }

    private function failed(BackupArtifact $artifact, Throwable $exception, string $stage): ComponentOutcome
    {
        $failure = FailureDetails::fromThrowable($exception, $stage, $this->redactor);

        if (! $artifact->status->isTerminal()) {
            $artifact->markFailed($failure);
        }

        return ComponentOutcome::failed($failure);
    }

    private function assertArtifact(BackupRun $run, BackupArtifact $artifact): void
    {
        if ($artifact->kind !== ArtifactKind::ApplicationArchive || $artifact->backup_run_id !== $run->id) {
            throw new IllegalStateTransition('The artifact is not this run\'s application archive.');
        }
    }

    private function password(): string
    {
        $password = $this->config->get('quraba-backup.archive.password');

        return is_string($password) ? trim($password) === '' ? '' : $password : '';
    }
}
