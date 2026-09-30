<?php

declare(strict_types=1);

namespace Quraba\Backup\Restic;

use InvalidArgumentException;
use Quraba\Backup\Exceptions\WorkspaceViolation;
use Quraba\Backup\Support\PathGuard;

/**
 * Typed input of a Restic backup. Media backup orchestration (which decides
 * the roots and identity tags) arrives in a later phase; this object only
 * guarantees the runner receives safe, explicit arguments.
 */
final readonly class ResticBackupRequest
{
    /**
     * @param  non-empty-list<string>  $paths  absolute, existing directories
     * @param  non-empty-list<string>  $tags
     */
    private function __construct(
        public array $paths,
        public array $tags,
        public string $host,
    ) {}

    /**
     * @param  list<string>  $paths
     * @param  list<string>  $tags
     */
    public static function make(array $paths, array $tags, string $host): self
    {
        if ($paths === [] || $tags === []) {
            throw new InvalidArgumentException('A Restic backup requires at least one path and one identity tag.');
        }

        $validated = [];

        foreach ($paths as $path) {
            try {
                $normalized = PathGuard::normalizeAbsolute($path);
            } catch (WorkspaceViolation $exception) {
                throw new InvalidArgumentException('Invalid backup path: '.$exception->getMessage());
            }

            if ($normalized === '/' || ! is_dir($normalized)) {
                throw new InvalidArgumentException(sprintf('Backup path [%s] must be an existing directory other than the filesystem root.', $path));
            }

            $validated[] = $normalized;
        }

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $host) !== 1) {
            throw new InvalidArgumentException('The Restic host label must be a simple identifier.');
        }

        /** @var non-empty-list<string> $tags */
        $tags = ResticTag::assertAll($tags);

        return new self($validated, $tags, $host);
    }
}
