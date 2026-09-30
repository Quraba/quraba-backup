<?php

declare(strict_types=1);

namespace Quraba\Backup\Manifest;

use Quraba\Backup\Domain\Identifiers;
use Quraba\Backup\Exceptions\ManifestStoreFailed;
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
    private const int MAX_MANIFEST_BYTES = 1048576;

    /** How many recent manifests are consulted to learn the repository identity. */
    private const int IDENTITY_LOOKBACK = 25;

    public function __construct(private RemoteStorage $remote) {}

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
     * The Restic repository ID recorded by the most recent manifests of this
     * application and environment, if any. Used to learn the expected
     * repository identity when the local catalog has none (e.g. after a
     * catalog loss), so a replacement repository is never silently adopted.
     */
    public function latestRepositoryId(ApplicationIdentity $identity): ?string
    {
        $objects = $this->remote->objects();
        $paths = array_reverse($objects->listFiles($this->remote->layout()->manifestsRoot()));

        foreach (array_slice($paths, 0, self::IDENTITY_LOOKBACK) as $path) {
            if (! str_ends_with($path, '.json')) {
                continue;
            }

            $manifest = json_decode($objects->read($path, self::MAX_MANIFEST_BYTES), true);

            if (! is_array($manifest) || ($manifest['app_id'] ?? null) !== $identity->appId || ($manifest['environment'] ?? null) !== $identity->environment) {
                continue;
            }

            $repositoryId = is_array($manifest['restic'] ?? null) ? ($manifest['restic']['repository_id'] ?? null) : null;

            if (is_string($repositoryId) && Identifiers::isRepositoryId($repositoryId)) {
                return $repositoryId;
            }
        }

        return null;
    }
}
