<?php

declare(strict_types=1);

namespace Quraba\Backup\Support;

use Closure;
use Quraba\Backup\Exceptions\WorkspaceViolation;

/**
 * Creates files that are private to the current OS user BEFORE any secret
 * is written into them.
 *
 * On POSIX systems (every supported production host) the file is created
 * exclusively under a 0077 umask, explicitly set to 0600, and then proven:
 * mode exactly 0600, owned by the effective user, a regular file and the
 * very inode that was opened. Any failure — including a chmod that returns
 * false — deletes the empty file and refuses; nothing falls back to a more
 * permissive file.
 *
 * Windows has no POSIX modes (NTFS ACLs apply and the file lives inside the
 * package's private workspace); it is a development platform only, so the
 * mode proof is skipped there.
 */
final class PrivateFile
{
    /**
     * @param  (Closure(string, int): bool)|null  $chmod  test seam for chmod()
     * @param  bool|null  $strict  null: strict on every non-Windows platform
     * @return resource an open write handle to the new, proven-private file
     */
    public static function create(string $path, ?Closure $chmod = null, ?bool $strict = null)
    {
        $strict ??= ! PathGuard::isWindows();
        $chmod ??= static fn (string $file, int $mode): bool => @chmod($file, $mode);

        $previousUmask = umask(0077);

        try {
            $handle = @fopen($path, 'xb');
        } finally {
            umask($previousUmask);
        }

        if ($handle === false) {
            throw new WorkspaceViolation(sprintf('Could not create the private file [%s] exclusively.', basename($path)), 'security.private_file');
        }

        if (! $strict) {
            // Windows development: best effort, NTFS ACLs of the private workspace apply.
            $chmod($path, 0600);

            return $handle;
        }

        $problem = $chmod($path, 0600) ? self::privacyProblem($path, $handle) : 'chmod 0600 failed';

        if ($problem !== null) {
            fclose($handle);
            @unlink($path);

            throw new WorkspaceViolation(sprintf('Refusing to write secrets: the private file [%s] could not be proven private (%s).', basename($path), $problem), 'security.private_file');
        }

        return $handle;
    }

    /** Re-proves that the path still names this opened private inode. */
    /** @param resource $handle */
    public static function assertStillPrivate(string $path, $handle): void
    {
        if (PathGuard::isWindows()) {
            return;
        }

        $problem = self::privacyProblem($path, $handle);
        if ($problem !== null) {
            throw new WorkspaceViolation('The private file changed after creation; refusing to use its path ('.$problem.').', 'security.private_file');
        }
    }

    /** Removes exactly the file name without following a replacement symlink. */
    public static function destroy(string $path): bool
    {
        clearstatcache(true, $path);
        if (is_link($path) || is_file($path)) {
            @unlink($path);
        }
        clearstatcache(true, $path);

        return ! is_link($path) && ! file_exists($path);
    }

    /**
     * @param  resource  $handle
     */
    private static function privacyProblem(string $path, $handle): ?string
    {
        clearstatcache(true, $path);

        if (is_link($path) || ! is_file($path)) {
            return 'not a regular file';
        }

        $stat = @stat($path);
        $opened = @fstat($handle);

        if ($stat === false || $opened === false) {
            return 'cannot stat';
        }

        if ($stat['ino'] !== $opened['ino'] || $stat['dev'] !== $opened['dev']) {
            return 'the path no longer refers to the opened file';
        }

        if (($stat['mode'] & 0777) !== 0600) {
            return sprintf('mode %04o instead of 0600', $stat['mode'] & 0777);
        }

        if (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid()) {
            return 'not owned by the current user';
        }

        return null;
    }
}
