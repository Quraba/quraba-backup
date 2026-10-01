<?php

declare(strict_types=1);

namespace Quraba\Backup\Contracts;

use Quraba\Backup\Exceptions\StorageUnavailable;

/**
 * Minimal remote object storage used for archives and manifests.
 *
 * Implementations must refuse any path under the Restic repository prefix:
 * only Restic ever touches its repository.
 *
 * @throws StorageUnavailable on any transport or provider error (messages are sanitized)
 */
interface ObjectStorage
{
    public function exists(string $path): bool;

    public function size(string $path): int;

    /**
     * @param  resource  $stream
     */
    public function writeStream(string $path, $stream): void;

    public function write(string $path, string $contents): void;

    /**
     * @return resource
     */
    public function readStream(string $path);

    /**
     * Reads a small object fully (manifests only).
     */
    public function read(string $path, int $maxBytes): string;

    /**
     * Deletes exactly ONE object at an exact path. There is deliberately no
     * prefix, wildcard or recursive deletion. Deleting an absent object is
     * not an error; callers prove absence afterwards with exists().
     *
     * Only retention-controlled code calls this (architecture-tested).
     */
    public function delete(string $path): void;

    /**
     * Recursively lists object paths below a prefix.
     *
     * @return list<string>
     */
    public function listFiles(string $prefix): array;

    /**
     * Human-readable, credential-free description (e.g. endpoint and bucket).
     */
    public function describe(): string;
}
