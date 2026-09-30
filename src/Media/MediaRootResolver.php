<?php

declare(strict_types=1);

namespace Quraba\Backup\Media;

use Illuminate\Contracts\Config\Repository;
use Quraba\Backup\Exceptions\MediaPathUnsafe;
use Quraba\Backup\Support\PackagePaths;
use Quraba\Backup\Support\PathGuard;

/**
 * Validates `restic.media.roots` before anything is backed up.
 *
 * Each root needs a stable logical name (recorded in manifests so a restore
 * can map it to a different absolute path later) and must be a narrowly
 * scoped, application-owned directory:
 *
 *  - existing and readable (unless marked `optional`, then it is skipped);
 *  - not `/`, not HOME, not the application root or any of its ancestors,
 *    not the storage directory or public directory as a whole;
 *  - not inside or containing the package's private storage/workspaces,
 *    and not containing a local Restic repository;
 *  - not overlapping another root;
 *  - its configured path must not traverse a symlink (unless the root sets
 *    `allow_symlinks`), so a link cannot redirect the backup elsewhere;
 *  - Laravel's `public/storage` link is recreatable (`storage:link`) and is
 *    refused in favour of `storage/app/public`.
 *
 * Symlinks inside a root are stored by Restic as links, never followed.
 */
final readonly class MediaRootResolver
{
    private const string NAME_PATTERN = '/^[a-z][a-z0-9_-]{0,31}$/';

    public function __construct(
        private Repository $config,
        private PackagePaths $paths,
        private string $basePath,
        private string $publicPath,
        private string $storagePath,
    ) {}

    /**
     * @return list<MediaRoot>
     */
    public function resolve(): array
    {
        $configured = $this->config->get('restic.media.roots', []);

        if (! is_array($configured) || $configured === []) {
            throw new MediaPathUnsafe('No media roots are configured (restic.media.roots).');
        }

        $roots = [];

        foreach ($configured as $name => $definition) {
            $root = $this->resolveOne((string) $name, $definition);

            if ($root !== null) {
                $roots[] = $root;
            }
        }

        if ($roots === []) {
            throw new MediaPathUnsafe('No configured media root exists; nothing can be backed up.');
        }

        $this->assertNoOverlap($roots);

        return $roots;
    }

    private function resolveOne(string $name, mixed $definition): ?MediaRoot
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new MediaPathUnsafe(sprintf('Media root name [%s] must be a stable lowercase identifier.', mb_substr($name, 0, 40)));
        }

        $path = is_array($definition) ? ($definition['path'] ?? null) : $definition;
        $optional = is_array($definition) && (bool) ($definition['optional'] ?? false);
        $allowSymlinks = is_array($definition) && (bool) ($definition['allow_symlinks'] ?? false);

        if (! is_string($path) || ! PathGuard::isAbsolute($path)) {
            throw new MediaPathUnsafe(sprintf('Media root [%s] must be an absolute path.', $name));
        }

        $normalized = PathGuard::normalizeAbsolute($path);

        if ($this->same($normalized, $this->publicPath.'/storage')) {
            throw new MediaPathUnsafe(sprintf('Media root [%s] is Laravel\'s public/storage link, which is recreatable with "php artisan storage:link". Back up storage/app/public instead.', $name));
        }

        if (! is_dir($normalized)) {
            if ($optional) {
                return null;
            }

            throw new MediaPathUnsafe(sprintf('Media root [%s] (%s) does not exist. Create it or mark it "optional".', $name, $normalized));
        }

        if (! is_readable($normalized)) {
            throw new MediaPathUnsafe(sprintf('Media root [%s] (%s) is not readable by the PHP user.', $name, $normalized));
        }

        $real = PathGuard::real($normalized) ?? throw new MediaPathUnsafe(sprintf('Media root [%s] cannot be resolved.', $name));

        if (! $allowSymlinks && ! $this->same($real, $normalized)) {
            throw new MediaPathUnsafe(sprintf('Media root [%s] (%s) traverses a symbolic link to %s; symlinked roots are refused unless allow_symlinks is set.', $name, $normalized, $real));
        }

        $this->assertNotDangerous($name, $real);

        return new MediaRoot($name, $real);
    }

    private function assertNotDangerous(string $name, string $real): void
    {
        if ($real === '/' || preg_match('~^[A-Za-z]:/?$~', $real) === 1) {
            throw new MediaPathUnsafe(sprintf('Media root [%s] is the filesystem root.', $name));
        }

        $home = getenv('HOME');

        if (is_string($home) && $home !== '' && ($realHome = PathGuard::real($home)) !== null && PathGuard::isWithin($realHome, $real)) {
            throw new MediaPathUnsafe(sprintf('Media root [%s] is or contains the HOME directory.', $name));
        }

        $base = PathGuard::real($this->basePath) ?? $this->basePath;

        foreach ([
            'the application root' => $base,
            'the storage directory' => PathGuard::real($this->storagePath) ?? $this->storagePath,
            'the public directory' => PathGuard::real($this->publicPath) ?? $this->publicPath,
        ] as $description => $forbidden) {
            if (PathGuard::isWithin($forbidden, $real)) {
                throw new MediaPathUnsafe(sprintf('Media root [%s] is or contains %s; configure narrowly scoped application-owned directories only.', $name, $description));
            }
        }

        foreach ([$this->paths->root, $this->paths->workspaces, $this->paths->locks, $this->paths->cache] as $private) {
            $privateReal = PathGuard::real(PathGuard::nearestExistingAncestor($private) ?? $private) ?? $private;

            if (PathGuard::isWithin($real, $private) || PathGuard::isWithin($private, $real) || (is_dir($private) && (PathGuard::isWithin($real, $privateReal) || PathGuard::isWithin($privateReal, $real)))) {
                throw new MediaPathUnsafe(sprintf('Media root [%s] overlaps the package private storage.', $name));
            }
        }

        $repository = $this->config->get('restic.repository.url');

        if (is_string($repository) && PathGuard::isAbsolute($repository)) {
            $repositoryPath = PathGuard::real($repository) ?? $repository;

            if (PathGuard::isWithin($repositoryPath, $real) || PathGuard::isWithin($real, $repositoryPath)) {
                throw new MediaPathUnsafe(sprintf('Media root [%s] overlaps the local Restic repository.', $name));
            }
        }
    }

    /**
     * @param  list<MediaRoot>  $roots
     */
    private function assertNoOverlap(array $roots): void
    {
        foreach ($roots as $i => $a) {
            foreach ($roots as $j => $b) {
                if ($i !== $j && PathGuard::isWithin($a->path, $b->path)) {
                    throw new MediaPathUnsafe(sprintf('Media roots [%s] and [%s] overlap.', $a->name, $b->name));
                }
            }
        }
    }

    private function same(string $a, string $b): bool
    {
        return PathGuard::isWithin($a, $b) && PathGuard::isWithin($b, $a);
    }
}
