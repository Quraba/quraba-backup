<?php

declare(strict_types=1);

namespace Quraba\Backup\Workspace;

use Quraba\Backup\Support\PathGuard;

/**
 * Deletes one operation workspace tree and nothing else.
 *
 * - the target must be named `op-{ULID}` and really live directly inside the
 *   workspace base directory;
 * - symbolic links are removed as links and never followed, so a link that
 *   points outside the workspace cannot cause foreign files to be deleted;
 * - errors are collected, not thrown.
 *
 * @internal
 */
final class WorkspaceDeleter
{
    /**
     * @return list<string> errors (empty on success)
     */
    public static function deleteTree(string $root, string $realBase): array
    {
        if (preg_match(WorkspaceManager::DIRECTORY_PATTERN, basename($root)) !== 1) {
            return [sprintf('Refusing to delete [%s]: not an operation workspace directory.', $root)];
        }

        if (is_link($root)) {
            return [sprintf('Refusing to delete [%s]: workspace root is a symbolic link.', $root)];
        }

        if (! file_exists($root)) {
            return [];
        }

        $realRoot = PathGuard::real($root);

        $parent = $realRoot === null ? null : dirname($realRoot);
        $isDirectChild = $parent !== null
            && PathGuard::isWithin($parent, $realBase)
            && PathGuard::isWithin($realBase, $parent);

        if (! $isDirectChild) {
            return [sprintf('Refusing to delete [%s]: it does not live directly inside the workspace directory.', $root)];
        }

        /** @var string $realRoot */
        $errors = [];
        self::deleteContents($realRoot, $realRoot, $errors);

        if (! @rmdir($realRoot) && file_exists($realRoot)) {
            $errors[] = sprintf('Could not remove workspace directory [%s].', $realRoot);
        }

        return $errors;
    }

    /**
     * @param  list<string>  $errors
     */
    private static function deleteContents(string $directory, string $realRoot, array &$errors): void
    {
        $entries = @scandir($directory);

        if ($entries === false) {
            $errors[] = sprintf('Could not read directory [%s].', $directory);

            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;

            if (is_link($path) || self::isForeignDirectory($path, $realRoot)) {
                // Remove the link itself; never descend into its target.
                if (! @unlink($path) && ! @rmdir($path)) {
                    $errors[] = sprintf('Could not remove link [%s].', $path);
                }

                continue;
            }

            if (is_dir($path)) {
                self::deleteContents($path, $realRoot, $errors);

                if (! @rmdir($path)) {
                    $errors[] = sprintf('Could not remove directory [%s].', $path);
                }

                continue;
            }

            if (! @unlink($path)) {
                @chmod($path, 0600);

                if (! @unlink($path)) {
                    $errors[] = sprintf('Could not remove file [%s].', $path);
                }
            }
        }
    }

    /**
     * Catches directory junctions and similar reparse points that is_link()
     * may not report: a directory whose real path leaves the workspace.
     */
    private static function isForeignDirectory(string $path, string $realRoot): bool
    {
        if (! is_dir($path)) {
            return false;
        }

        $real = PathGuard::real($path);

        return $real === null || ! PathGuard::isWithin($real, $realRoot);
    }
}
