<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Support;

use Quraba\Backup\Contracts\ObjectStorage;
use Quraba\Backup\Exceptions\StorageUnavailable;

/**
 * Decorator that fails writes below chosen prefixes, to inject remote
 * storage failures (e.g. the manifest upload).
 */
final class FlakyObjectStorage implements ObjectStorage
{
    /** @var list<string> */
    public array $failWritesUnder = [];

    public function __construct(private readonly ObjectStorage $inner) {}

    public function exists(string $path): bool
    {
        return $this->inner->exists($path);
    }

    public function size(string $path): int
    {
        return $this->inner->size($path);
    }

    public function writeStream(string $path, $stream): void
    {
        $this->guard($path);
        $this->inner->writeStream($path, $stream);
    }

    public function write(string $path, string $contents): void
    {
        $this->guard($path);
        $this->inner->write($path, $contents);
    }

    public function readStream(string $path)
    {
        return $this->inner->readStream($path);
    }

    public function read(string $path, int $maxBytes): string
    {
        return $this->inner->read($path, $maxBytes);
    }

    public function listFiles(string $prefix): array
    {
        return $this->inner->listFiles($prefix);
    }

    public function describe(): string
    {
        return $this->inner->describe();
    }

    private function guard(string $path): void
    {
        foreach ($this->failWritesUnder as $prefix) {
            if (str_contains($path, $prefix)) {
                throw new StorageUnavailable('Simulated remote write failure for '.$path);
            }
        }
    }
}
