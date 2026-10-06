<?php

declare(strict_types=1);

namespace Quraba\Backup\Restore;

use Quraba\Backup\Domain\Identifiers;
use Quraba\Backup\Enums\ArtifactKind;
use Quraba\Backup\Enums\ArtifactStatus;
use Quraba\Backup\Enums\BackupStatus;
use Quraba\Backup\Enums\RestoreProfile;
use Quraba\Backup\Exceptions\RestoreFailed;
use Quraba\Backup\Identity\IdentityResolver;
use Quraba\Backup\Manifest\RemoteManifest;
use Quraba\Backup\Manifest\RemoteManifestCatalog;
use Quraba\Backup\Models\BackupArtifact;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Retention\RetentionTombstoneStore;
use Quraba\Backup\Storage\RemoteStorage;
use Quraba\Backup\Support\LocalCatalog;

/**
 * Resolves only an exact UUID, cross-checking local and remote immutable
 * facts. Never "latest", never a short ID.
 *
 * Works without a local catalog (clean host): the immutable remote manifest
 * and the retention expiry records are then the only source. A local run
 * that is not terminal although an immutable manifest exists for it is a
 * stale catalog copy (a restored database contains the row of the very
 * backup that produced it, frozen mid-run) and is not trusted.
 */
final readonly class RestoreSourceResolver
{
    public function __construct(
        private IdentityResolver $identities,
        private RemoteManifestCatalog $manifests,
        private RetentionTombstoneStore $tombstones,
        private RemoteStorage $storage,
        private LocalCatalog $catalog,
    ) {}

    public function resolve(string $runUuid, RestoreProfile $profile): RestoreSource
    {
        $runUuid = Identifiers::assertUuid($runUuid, 'The restore source run UUID');
        $identity = $this->identities->current();
        $local = $this->catalog->available() ? BackupRun::query()->where('uuid', $runUuid)->with('artifacts')->first() : null;
        $remote = $this->manifests->find($identity, $runUuid);

        if ($local !== null && $remote !== null && ! in_array($local->status, [BackupStatus::Completed, BackupStatus::Partial], true)) {
            // A manifest is only written when a run is finalized: the local
            // row is a stale copy and the immutable manifest decides.
            $local = null;
        }

        if ($local === null && $remote === null) {
            throw RestoreFailed::sourceUnavailable('no local run or remote manifest has this exact UUID');
        }

        $tombstone = $this->tombstones->find($runUuid);
        if ($tombstone !== null) {
            if ($tombstone->appId !== $identity->appId || $tombstone->environment !== $identity->environment) {
                throw RestoreFailed::sourceConflict('retention tombstone belongs to another application/environment');
            }
            if (($profile !== RestoreProfile::Media && $tombstone->covers('application_archive'))
                || ($profile !== RestoreProfile::Database && $tombstone->covers('media_snapshot'))) {
                throw RestoreFailed::sourceExpired();
            }
        }

        $localSource = $local === null ? null : $this->fromLocal($local, $identity->appId, $identity->environment, $remote !== null);
        $remoteSource = $remote === null ? null : $this->fromRemote($remote);

        if ($profile === RestoreProfile::Full && $remote !== null && ! $remote->recoveryPoint) {
            throw RestoreFailed::sourceUnavailable('the immutable manifest does not claim a complete Recovery Point');
        }

        if ($localSource !== null && $remoteSource !== null) {
            foreach ($localSource->identities() as $field => $value) {
                if ($value !== $remoteSource->identities()[$field]) {
                    throw RestoreFailed::sourceConflict($field.' differs');
                }
            }
        }

        $source = $localSource !== null && $remoteSource !== null ? $localSource->withOrigin('local+remote') : ($localSource ?? $remoteSource);
        if ($source === null) {
            throw RestoreFailed::sourceUnavailable();
        }

        if (! $source->supports($profile)) {
            throw RestoreFailed::sourceUnavailable('this run does not contain every verified component required by the requested profile');
        }

        if ($source->archiveLocator !== null && $this->storage->layout()->archiveRunUuid($source->archiveLocator) !== $runUuid) {
            throw RestoreFailed::sourceConflict('archive locator is outside the exact managed run path');
        }

        return $source;
    }

    private function fromLocal(BackupRun $run, string $appId, string $environment, bool $hasRemoteManifest): RestoreSource
    {
        $metadata = $run->metadata ?? [];
        $identityMissing = ! array_key_exists('app_id', $metadata) && ! array_key_exists('environment', $metadata);
        if (! ($identityMissing && $hasRemoteManifest)
            && (($metadata['app_id'] ?? null) !== $appId || ($metadata['environment'] ?? null) !== $environment)) {
            throw RestoreFailed::sourceConflict('local run application/environment differs');
        }

        if (! in_array($run->status, [BackupStatus::Completed, BackupStatus::Partial], true)) {
            throw RestoreFailed::sourceUnavailable('local run is not complete or partial');
        }

        $archive = $run->artifacts->first(static fn (BackupArtifact $a): bool => $a->kind === ArtifactKind::ApplicationArchive);
        $snapshot = $run->artifacts->first(static fn (BackupArtifact $a): bool => $a->kind === ArtifactKind::ResticSnapshot);
        $manifest = $run->artifacts->first(static fn (BackupArtifact $a): bool => $a->kind === ArtifactKind::RemoteManifest);
        $archive = $archive?->status === ArtifactStatus::Verified ? $archive : null;
        $snapshot = $snapshot?->status === ArtifactStatus::Verified ? $snapshot : null;
        $snapshotMetadata = $snapshot === null ? [] : ($snapshot->metadata ?? []);
        $roots = [];
        foreach (is_array($snapshotMetadata['roots'] ?? null) ? $snapshotMetadata['roots'] : [] as $root) {
            if (! is_array($root) || ! is_string($root['name'] ?? null) || ! is_string($root['path'] ?? null)) {
                throw RestoreFailed::sourceConflict('invalid local snapshot roots');
            }
            $roots[] = ['name' => $root['name'], 'path' => $root['path']];
        }
        $kind = $snapshotMetadata['kind'] ?? null;

        return new RestoreSource(
            $run->uuid, $appId, $environment, $run->profile->value, $run->status->value,
            $run->consistency->value, $archive?->locator, $archive?->sha256, $archive?->byte_size,
            is_string($metadata['restic_repository_id'] ?? null) ? $metadata['restic_repository_id'] : null,
            $snapshot?->snapshot_id, is_string($kind) ? $kind : null,
            $roots, $manifest?->status === ArtifactStatus::Verified ? 1 : null, 'local',
        );
    }

    private function fromRemote(RemoteManifest $manifest): RestoreSource
    {
        return new RestoreSource(
            $manifest->runUuid, $manifest->appId, $manifest->environment, $manifest->profile->value,
            $manifest->status->value, $manifest->consistency->value, $manifest->archiveLocator,
            $manifest->archiveSha256, $manifest->archiveBytes, $manifest->repositoryId, $manifest->snapshotId,
            $manifest->snapshotKind, $manifest->snapshotRoots, $manifest->schemaVersion, 'remote',
        );
    }
}
