<?php

declare(strict_types=1);

namespace Quraba\Backup\Storage;

use League\Flysystem\FilesystemOperator;
use League\Flysystem\StorageAttributes;
use Quraba\Backup\Contracts\ObjectStorage;
use Quraba\Backup\Exceptions\ConfigurationException;
use Quraba\Backup\Exceptions\StorageUnavailable;
use Quraba\Backup\Security\SecretRedactor;
use Throwable;

/**
 * Object storage over a Flysystem operator (S3 adapter for Backblaze B2 in
 * production, a local adapter in tests).
 *
 * Every provider error is converted to a sanitized StorageUnavailable and
 * never chained, so SDK messages (which can carry request details) cannot
 * leak. Any path inside a forbidden prefix (the Restic repository) is refused
 * before the provider is contacted.
 */
final readonly class FlysystemObjectStorage implements ObjectStorage
{
    /**
     * @param  list<string>  $forbiddenPrefixes
     */
    public function __construct(
        private FilesystemOperator $filesystem,
        private array $forbiddenPrefixes,
        private string $description,
        private SecretRedactor $redactor,
    ) {}

    public function exists(string $path): bool
    {
        $path = $this->guard($path);

        return $this->attempt('check', $path, fn (): bool => $this->filesystem->fileExists($path));
    }

    public function size(string $path): int
    {
        $path = $this->guard($path);

        return $this->attempt('size', $path, fn (): int => $this->filesystem->fileSize($path));
    }

    public function writeStream(string $path, $stream): void
    {
        $path = $this->guard($path);

        $this->attempt('upload', $path, function () use ($path, $stream): bool {
            $this->filesystem->writeStream($path, $stream);

            return true;
        });
    }

    public function write(string $path, string $contents): void
    {
        $path = $this->guard($path);

        $this->attempt('write', $path, function () use ($path, $contents): bool {
            $this->filesystem->write($path, $contents);

            return true;
        });
    }

    public function readStream(string $path)
    {
        $path = $this->guard($path);

        return $this->attempt('download', $path, fn () => $this->filesystem->readStream($path));
    }

    public function read(string $path, int $maxBytes): string
    {
        if ($this->size($path) > $maxBytes) {
            throw new StorageUnavailable(sprintf('Remote object [%s] is larger than the %d byte limit for small reads.', $path, $maxBytes));
        }

        return $this->attempt('read', $path, fn (): string => $this->filesystem->read($path));
    }

    public function delete(string $path): void
    {
        $path = $this->guard($path);

        if ($path === '' || str_ends_with($path, '/') || str_contains($path, '*')) {
            throw new ConfigurationException('Refusing to delete anything but one exact object key.');
        }

        $this->attempt('delete', $path, function () use ($path): bool {
            $this->filesystem->delete($path);

            return true;
        });
    }

    public function listFiles(string $prefix): array
    {
        $prefix = $this->guard(rtrim($prefix, '/'));

        return $this->attempt('list', $prefix, function () use ($prefix): array {
            $paths = [];

            /** @var StorageAttributes $item */
            foreach ($this->filesystem->listContents($prefix, true) as $item) {
                if ($item->isFile()) {
                    $paths[] = $item->path();
                }
            }

            sort($paths);

            return $paths;
        });
    }

    public function describe(): string
    {
        return $this->description;
    }

    private function guard(string $path): string
    {
        foreach ($this->forbiddenPrefixes as $forbidden) {
            $forbidden = rtrim($forbidden, '/');

            if ($path === $forbidden || str_starts_with($path, $forbidden.'/')) {
                throw new ConfigurationException('Refusing to access the Restic repository prefix through object storage; only Restic manages its repository.');
            }
        }

        return $path;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function attempt(string $action, string $path, callable $operation): mixed
    {
        try {
            return $operation();
        } catch (Throwable $exception) {
            $message = $this->redactor->redact($exception->getMessage());
            $message = (string) preg_replace('~https?://\S+~i', '[url]', $message);

            throw new StorageUnavailable(sprintf('Remote storage %s failed for [%s] on %s: %s', $action, $path, $this->description, mb_substr($message, 0, 500)));
        }
    }
}
