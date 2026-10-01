<?php

declare(strict_types=1);

namespace Quraba\Backup\Manifest;

use Quraba\Backup\Exceptions\ConfigurationException;
use Quraba\Backup\Exceptions\ManifestStoreFailed;
use Quraba\Backup\Exceptions\RepositoryIdentityMismatch;
use Quraba\Backup\Exceptions\StorageUnavailable;
use Quraba\Backup\Identity\ApplicationIdentity;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Storage\RemoteStorage;
use Quraba\Backup\Storage\StoredObject;

/**
 * Writes immutable manifests at their deterministic per-run path.
 *
 * Absent: upload, then read back and compare. Present: identical canonical
 * content is adopted; different content is a collision. Never overwritten.
 */
final readonly class ManifestStore
{
    private const int MAX_MANIFEST_BYTES = RemoteManifestCatalog::MAX_MANIFEST_BYTES;

    public function __construct(
        private RemoteStorage $remote,
        private RemoteManifestCatalog $catalog,
    ) {}

    public function locatorFor(BackupRun $run): string
    {
        return $this->remote->layout()->manifest($run);
    }

    public function put(BackupRun $run, string $contents): StoredObject
    {
        $path = $this->remote->layout()->assertManaged($this->locatorFor($run));
        $canonical = ManifestBuilder::canonicalJson($contents) ?? throw new ManifestStoreFailed('Refusing to store a manifest that is not a JSON object.');
        $objects = $this->remote->objects();

        try {
            if ($objects->exists($path)) {
                $existing = ManifestBuilder::canonicalJson($objects->read($path, self::MAX_MANIFEST_BYTES));

                if ($existing === null || ! hash_equals($existing, $canonical)) {
                    throw ManifestStoreFailed::collision($path);
                }

                return new StoredObject($path, hash('sha256', $canonical), strlen($canonical), adopted: true);
            }

            $objects->write($path, $canonical);
            $readBack = ManifestBuilder::canonicalJson($objects->read($path, self::MAX_MANIFEST_BYTES));
        } catch (StorageUnavailable $exception) {
            throw new ManifestStoreFailed($exception->getMessage());
        }

        if ($readBack === null || ! hash_equals($readBack, $canonical)) {
            throw new ManifestStoreFailed(sprintf('The manifest [%s] could not be read back identically after upload.', $path));
        }

        return new StoredObject($path, hash('sha256', $canonical), strlen($canonical), adopted: false);
    }

    public function exists(BackupRun $run): bool
    {
        return $this->remote->objects()->exists($this->remote->layout()->assertManaged($this->locatorFor($run)));
    }

    /**
     * The Restic repository ID all manifests of this application and
     * environment agree on, or null when no manifest records one.
     *
     * Every manifest is scanned (not a recent window), manifests without a
     * repository ID are ignored, malformed documents never contribute, and
     * conflicting IDs are a hard refusal: the operator must investigate which
     * repository is authoritative. "Newest wins" is never applied.
     *
     * @throws RepositoryIdentityMismatch when manifests name different repositories
     * @throws ConfigurationException when object storage is not configured
     */
    public function repositoryIdConsensus(ApplicationIdentity $identity): ?string
    {
        $ids = $this->catalog->scan($identity)->repositoryIds();

        if (count($ids) > 1) {
            throw new RepositoryIdentityMismatch(sprintf(
                'The remote manifests of this application/environment name %d different Restic repositories (%s). Refusing to choose one; investigate which repository is authoritative before any backup or restore.',
                count($ids),
                implode(', ', $ids),
            ), 'restic.repository_identity_conflict');
        }

        return $ids[0] ?? null;
    }
}
