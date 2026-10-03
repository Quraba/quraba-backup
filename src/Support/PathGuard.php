<?php

declare(strict_types=1);

namespace Quraba\Backup\Support;

use Quraba\Backup\Exceptions\WorkspaceViolation;

/**
 * Path normalization and containment checks.
 *
 * Containment is decided on real (symlink-resolved) paths, so a symlink
 * inside a package directory that points elsewhere is treated as an escape.
 */
final class PathGuard
{
    public static function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    public static function isAbsolute(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if (! self::isWindows() && str_starts_with($path, '/')) {
            return true;
        }

        return self::isWindows() && preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1;
    }

    /**
     * Normalizes separators and `.` segments of an absolute path and refuses
     * `..` segments, NUL bytes and relative paths outright (no resolution is
     * attempted: a `..` in a package path is always a configuration error).
     */
    public static function normalizeAbsolute(string $path): string
    {
        if (str_contains($path, "\0")) {
            throw new WorkspaceViolation('Paths must not contain NUL bytes.');
        }

        if (! self::isAbsolute($path)) {
            throw new WorkspaceViolation(sprintf('Path [%s] must be absolute.', $path));
        }

        if (self::isWindows() && (str_starts_with($path, '\\\\') || str_starts_with($path, '//'))) {
            throw new WorkspaceViolation('UNC and device paths are not supported for package paths.');
        }

        $unified = str_replace('\\', '/', $path);
        $prefix = '';

        if (preg_match('~^([A-Za-z]:)/~', $unified, $matches) === 1) {
            $prefix = strtoupper($matches[1]);
            $unified = substr($unified, 2);
        }

        $segments = [];

        foreach (explode('/', $unified) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                throw new WorkspaceViolation(sprintf('Path [%s] must not contain ".." segments.', $path));
            }

            if (self::isWindows() && (str_contains($segment, ':') || preg_match('/[. ]$/', $segment) === 1)) {
                throw new WorkspaceViolation(sprintf('Path [%s] has an unsafe Windows segment.', $path));
            }

            $segments[] = $segment;
        }

        return $prefix.'/'.implode('/', $segments);
    }

    /**
     * Validates a relative path supplied by package code (never by users):
     * no absolute paths, no `..`, no NUL, no backslashes.
     */
    public static function assertSafeRelative(string $relative): string
    {
        $valid = $relative !== ''
            && ! str_contains($relative, "\0")
            && ! str_contains($relative, '\\')
            && ! self::isAbsolute($relative)
            && preg_match('~^[A-Za-z]:~', $relative) !== 1;

        if ($valid) {
            foreach (explode('/', $relative) as $segment) {
                if ($segment === '' || $segment === '.' || $segment === '..') {
                    $valid = false;
                    break;
                }
            }
        }

        if (! $valid) {
            throw new WorkspaceViolation(sprintf('Relative path [%s] is not a clean contained path.', $relative));
        }

        return $relative;
    }

    /**
     * A path as it is compared with a path reported by another tool (Restic
     * reports Windows paths with backslashes): forward slashes and an
     * upper-case drive letter. Purely lexical.
     */
    public static function comparable(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return self::isWindows() ? strtolower($path) : $path;
    }

    /**
     * Canonical real path with forward slashes, or null when it does not exist.
     */
    public static function real(string $path): ?string
    {
        // Silenced: under open_basedir a probe outside the allowed tree must
        // simply mean "unknown", not a converted warning exception.
        $real = @realpath($path);

        if ($real === false) {
            return null;
        }

        $real = str_replace('\\', '/', $real);

        if (preg_match('~^([A-Za-z]:)(.*)$~', $real, $matches) === 1) {
            $real = strtoupper($matches[1]).($matches[2] === '' ? '/' : $matches[2]);
        }

        return $real;
    }

    /**
     * Whether $path equals or is below $parent. Both are compared as given
     * (callers pass canonical real paths).
     */
    public static function isWithin(string $path, string $parent): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $parent = rtrim(str_replace('\\', '/', $parent), '/');

        if (self::isWindows()) {
            $path = strtolower($path);
            $parent = strtolower($parent);
        }

        if ($parent === '') {
            return true;
        }

        return $path === $parent || str_starts_with($path, $parent.'/');
    }

    /**
     * Asserts that an existing $path really (after resolving symlinks) lives
     * inside the existing directory $root.
     */
    public static function assertRealWithin(string $path, string $root): string
    {
        $realRoot = self::real($root);
        $realPath = self::real($path);

        if ($realRoot === null || $realPath === null || ! self::isWithin($realPath, $realRoot)) {
            throw new WorkspaceViolation(sprintf('Path [%s] escapes its containing directory.', $path));
        }

        return $realPath;
    }

    /**
     * The nearest existing ancestor of a (possibly not yet existing) path.
     */
    public static function nearestExistingAncestor(string $path): ?string
    {
        $current = $path;

        while (! @file_exists($current)) {
            $parent = dirname($current);

            if ($parent === $current) {
                return null;
            }

            $current = $parent;
        }

        return $current;
    }
}
