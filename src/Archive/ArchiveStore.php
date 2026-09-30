<?php

declare(strict_types=1);

namespace Quraba\Backup\Archive;

use Quraba\Backup\Exceptions\ArchiveUploadFailed;
use Quraba\Backup\Exceptions\StorageUnavailable;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Storage\RemoteStorage;
use Quraba\Backup\Storage\StoredObject;

/**
 * Stores verified application archives at their deterministic per-run path.
 *
 * - Nothing is ever overwritten. An existing object is adopted only when its
 *   size AND streamed SHA-256 equal the local verified archive; anything else
 *   at the run's path is a hard collision.
 * - After an upload the object is proven by existence and exact size; the
 *   upload call's return value alone never counts as proof.
 * - Partial uploads cannot be adopted: S3 objects only become visible when
 *   complete, and adoption requires a full content hash match.
 */
final readonly class ArchiveStore
{
    private const int CHUNK = 1048576;

    public function __construct(private RemoteStorage $remote) {}

    /**
     * Validates layout and storage configuration without contacting B2.
     */
    public function assertConfigured(): void
    {
        $this->remote->layout();
        $this->remote->objects();
    }

    public function locatorFor(BackupRun $run): string
    {
        return $this->remote->layout()->archive($run);
    }

    public function store(BackupRun $run, ArchiveVerification $local): StoredObject
    {
        $path = $this->remote->layout()->assertManaged($this->locatorFor($run));
        $objects = $this->remote->objects();

        if ($objects->exists($path)) {
            return $this->adoptExisting($path, $local);
        }

        $stream = @fopen($local->path, 'rb');

        if ($stream === false) {
            throw new ArchiveUploadFailed('The verified local archive could not be opened for upload.');
        }

        try {
            $objects->writeStream($path, $stream);
        } catch (StorageUnavailable $exception) {
            throw new ArchiveUploadFailed($exception->getMessage());
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $this->proveRemote($path, $local->bytes);

        return new StoredObject($path, $local->sha256, $local->bytes, adopted: false);
    }

    /**
     * Existence and exact size of the remote object.
     */
    public function proveRemote(string $path, int $expectedBytes): void
    {
        $objects = $this->remote->objects();

        if (! $objects->exists($path)) {
            throw new ArchiveUploadFailed(sprintf('The uploaded archive [%s] is not present in remote storage.', $path));
        }

        $size = $objects->size($path);

        if ($size !== $expectedBytes) {
            throw new ArchiveUploadFailed(sprintf('The remote archive [%s] is %d bytes; %d were expected.', $path, $size, $expectedBytes));
        }
    }

    /**
     * Streams the remote object to a local file (reconciliation), returning
     * its SHA-256. Memory use does not grow with the archive size.
     */
    public function download(string $path, string $destination): string
    {
        $path = $this->remote->layout()->assertManaged($path);
        $source = $this->remote->objects()->readStream($path);
        $target = @fopen($destination, 'xb');

        if ($target === false) {
            throw new ArchiveUploadFailed('Could not create the local download target.');
        }

        $hash = hash_init('sha256');

        try {
            while (! feof($source)) {
                $chunk = fread($source, self::CHUNK);

                if ($chunk === false) {
                    throw new ArchiveUploadFailed(sprintf('Reading the remote archive [%s] failed.', $path));
                }

                hash_update($hash, $chunk);
                fwrite($target, $chunk);
            }
        } finally {
            fclose($source);
            fclose($target);
        }

        return hash_final($hash);
    }

    public function exists(BackupRun $run): bool
    {
        return $this->remote->objects()->exists($this->remote->layout()->assertManaged($this->locatorFor($run)));
    }

    private function adoptExisting(string $path, ArchiveVerification $local): StoredObject
    {
        $size = $this->remote->objects()->size($path);

        if ($size !== $local->bytes) {
            throw ArchiveUploadFailed::collision(sprintf('[%s] is %d bytes, the verified archive of this run is %d bytes', $path, $size, $local->bytes));
        }

        $remoteHash = $this->remoteHash($path);

        if (! hash_equals($local->sha256, $remoteHash)) {
            throw ArchiveUploadFailed::collision(sprintf('[%s] has SHA-256 %s, the verified archive of this run is %s', $path, $remoteHash, $local->sha256));
        }

        return new StoredObject($path, $local->sha256, $local->bytes, adopted: true);
    }

    private function remoteHash(string $path): string
    {
        $stream = $this->remote->objects()->readStream($path);
        $hash = hash_init('sha256');

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, self::CHUNK);

                if ($chunk === false) {
                    throw new ArchiveUploadFailed(sprintf('Reading the existing remote archive [%s] failed.', $path));
                }

                hash_update($hash, $chunk);
            }
        } finally {
            fclose($stream);
        }

        return hash_final($hash);
    }
}
