<?php

declare(strict_types=1);

namespace Quraba\Backup\Support;

use Illuminate\Contracts\Config\Repository;
use Quraba\Backup\Exceptions\ConfigurationException;
use Quraba\Backup\Exceptions\WorkspaceViolation;

/**
 * Validated package-private filesystem locations.
 *
 * Directories are created lazily with owner-only permissions (0700) by the
 * components that need them; resolving this object never touches the disk.
 */
final readonly class PackagePaths
{
    private function __construct(
        public string $root,
        public string $workspaces,
        public string $locks,
        public string $cache,
        public string $journal,
    ) {}

    public static function fromConfig(Repository $config): self
    {
        $root = self::absolute($config->get('quraba-backup.paths.root'), 'quraba-backup.paths.root');

        $workspaces = $config->get('quraba-backup.paths.workspaces');
        $locks = $config->get('quraba-backup.paths.locks');
        $cache = $config->get('restic.cache_dir');

        return new self(
            root: $root,
            workspaces: self::absolute($workspaces ?? $root.'/work', 'quraba-backup.paths.workspaces'),
            locks: self::absolute($locks ?? $root.'/locks', 'quraba-backup.paths.locks'),
            cache: self::absolute($cache ?? $root.'/cache/restic', 'restic.cache_dir'),
            // Restore journals always live below the private root: they are the
            // authority about live restores and must never be relocated by accident.
            journal: $root.'/journal',
        );
    }

    /**
     * Creates a package-private directory (0700) if needed and returns it.
     */
    public static function ensureDirectory(string $directory): string
    {
        if (is_link($directory)) {
            throw new WorkspaceViolation(sprintf('Package directory [%s] must not be a symbolic link.', $directory));
        }

        if (! is_dir($directory)) {
            if (! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
                throw new ConfigurationException(sprintf('Could not create package directory [%s]; check that the parent is writable by the PHP user.', $directory));
            }

            @chmod($directory, 0700);
        }

        return $directory;
    }

    private static function absolute(mixed $value, string $key): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new ConfigurationException(sprintf('[%s] must be a non-empty absolute path.', $key));
        }

        try {
            $normalized = PathGuard::normalizeAbsolute($value);
        } catch (WorkspaceViolation $exception) {
            throw new ConfigurationException(sprintf('[%s] is invalid: %s', $key, $exception->getMessage()));
        }

        if ($normalized === '/' || preg_match('~^[A-Z]:/$~', $normalized) === 1) {
            throw new ConfigurationException(sprintf('[%s] must not be a filesystem root.', $key));
        }

        return $normalized;
    }
}
