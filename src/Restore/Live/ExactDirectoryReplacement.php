<?php

declare(strict_types=1);

namespace Quraba\Backup\Restore\Live;

use Quraba\Backup\Exceptions\RestoreFailed;
use Quraba\Backup\Support\PathGuard;

/**
 * Exact replacement of ONE live media root by two directory renames:
 *
 *     live   → unique parked path (kept until the whole restore is verified)
 *     staged → live
 *
 * A rename either happens completely or not at all; there is deliberately
 * no fallback that copies or overlays files into the live root, so a
 * root is always entirely the old tree or entirely the restored tree. Each
 * step is a separate method because the journal records each one
 * separately, before it happens. Nothing here ever rolls back.
 */
final readonly class ExactDirectoryReplacement
{
    public const string PARKED_MARKER = '.quraba-parked-';

    /**
     * Where the current live root is parked: a sibling of the live root, so
     * the rename stays on one filesystem; unique per restore.
     */
    public function parkedPath(string $live, string $restoreUuid): string
    {
        return dirname($live).'/.'.basename($live).self::PARKED_MARKER.$restoreUuid;
    }

    /**
     * Device and inode of a directory, where the platform reports them.
     *
     * @return array{dev: int, ino: int}|null null when the path does not exist
     */
    public function identity(string $path): ?array
    {
        clearstatcache(true, $path);

        if (is_link($path) || ! is_dir($path)) {
            return null;
        }

        $stat = @stat($path);

        return $stat === false ? null : ['dev' => $stat['dev'], 'ino' => $stat['ino']];
    }

    /**
     * A deterministic description of a tree (names, types, sizes,
     * modification times, link targets), and proof that it holds no link
     * escaping the root. Contents are not hashed: the tree was produced by
     * Restic, which verifies every blob it restores; this description
     * detects a tree that is incomplete, was modified, or is another tree.
     *
     * @return array{files: int, directories: int, links: int, bytes: int, sha256: string}
     *
     * @throws RestoreFailed when the tree is missing, is a link, or contains an escaping link
     */
    public function fingerprint(string $tree, string $liveDestination, bool $allowEscapingLinks): array
    {
        clearstatcache();

        if (is_link($tree) || ! is_dir($tree)) {
            throw RestoreFailed::mappingFailed('an expected media tree is missing or is a link');
        }

        $realTree = PathGuard::real($tree) ?? throw RestoreFailed::mappingFailed('a media tree cannot be resolved');
        $hash = hash_init('sha256');
        $counts = ['files' => 0, 'directories' => 0, 'links' => 0, 'bytes' => 0];
        $pending = [''];

        while ($pending !== []) {
            $relative = array_pop($pending);
            $directory = $relative === '' ? $tree : $tree.'/'.$relative;
            $entries = @scandir($directory);

            if ($entries === false) {
                throw RestoreFailed::mappingFailed('a media directory cannot be read');
            }

            // scandir() sorts, so the walk — and the hash — is deterministic.
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $path = $directory.'/'.$entry;
                $name = $relative === '' ? $entry : $relative.'/'.$entry;

                if (is_link($path)) {
                    $target = (string) @readlink($path);

                    if (! $allowEscapingLinks && $this->escapes($target, dirname($name), $liveDestination)) {
                        throw RestoreFailed::mappingFailed(sprintf('the media tree contains a symbolic link [%s] that points outside its root; restoring it is refused unless the root sets allow_symlinks', $name));
                    }

                    $counts['links']++;
                    hash_update($hash, "L\0".$name."\0".$target."\n");
                } elseif (is_dir($path)) {
                    // A junction or mount that leaves the tree is an escape too.
                    $real = PathGuard::real($path);

                    if ($real === null || ! PathGuard::isWithin($real, $realTree)) {
                        throw RestoreFailed::mappingFailed(sprintf('the media directory [%s] resolves outside its root', $name));
                    }

                    $counts['directories']++;
                    hash_update($hash, "D\0".$name."\n");
                    $pending[] = $name;
                } else {
                    $size = (int) @filesize($path);
                    $counts['files']++;
                    $counts['bytes'] += $size;
                    hash_update($hash, "F\0".$name."\0".$size."\0".(int) @filemtime($path)."\n");
                }
            }
        }

        return [...$counts, 'sha256' => hash_final($hash)];
    }

    /**
     * live → parked. The parked path must not exist; the live root must be
     * a real directory.
     */
    public function park(string $live, string $parked): void
    {
        clearstatcache();

        if (is_link($live) || ! is_dir($live)) {
            throw RestoreFailed::mediaApplyFailed('the live media root is not a real directory');
        }

        if (file_exists($parked) || is_link($parked)) {
            throw RestoreFailed::mediaApplyFailed('the parked path of this restore already exists');
        }

        if (dirname($parked) !== dirname($live) || ! str_contains(basename($parked), self::PARKED_MARKER)) {
            throw RestoreFailed::mediaApplyFailed('the parked path is not a sibling of the live media root');
        }

        if (! @rename($live, $parked)) {
            throw RestoreFailed::mediaApplyFailed('the live media root could not be renamed to its parked path');
        }
    }

    /**
     * staged → live. The live path must be free (parked or absent).
     */
    public function activate(string $staged, string $live): void
    {
        clearstatcache();

        if (is_link($staged) || ! is_dir($staged)) {
            throw RestoreFailed::mediaApplyFailed('the staged media tree is missing or is a link');
        }

        if (file_exists($live) || is_link($live)) {
            throw RestoreFailed::mediaApplyFailed('the live media path is not free; refusing to overlay an existing directory');
        }

        if (! @rename($staged, $live)) {
            throw RestoreFailed::mediaApplyFailed('the staged media tree could not be renamed into the live path');
        }
    }

    /**
     * Proves the live root now IS the staged tree: a real directory at the
     * expected real path, the same inode the staged tree had (where inodes
     * exist) and the same content description.
     *
     * @param  array{files: int, directories: int, links: int, bytes: int, sha256: string}  $expected
     * @param  array{dev: int, ino: int}|null  $stagedIdentity
     * @return array<string, mixed>
     */
    public function verify(string $live, array $expected, ?array $stagedIdentity, bool $allowEscapingLinks): array
    {
        $identity = $this->identity($live);

        if ($identity === null) {
            throw RestoreFailed::mediaVerificationFailed('the live media root is missing or is a link after activation');
        }

        $parent = PathGuard::real(dirname($live));

        if ($parent === null || PathGuard::real($live) !== rtrim($parent, '/').'/'.basename($live)) {
            throw RestoreFailed::mediaVerificationFailed('the live media root does not resolve to its expected path');
        }

        if ($stagedIdentity !== null && $stagedIdentity['ino'] !== 0 && $identity !== $stagedIdentity) {
            throw RestoreFailed::mediaVerificationFailed('the live media root is not the directory that was staged');
        }

        $actual = $this->fingerprint($live, $live, $allowEscapingLinks);

        if ($actual !== $expected) {
            throw RestoreFailed::mediaVerificationFailed(sprintf('the live media root holds %d file(s), %d byte(s); %d file(s), %d byte(s) were staged', $actual['files'], $actual['bytes'], $expected['files'], $expected['bytes']));
        }

        return ['path' => $live, 'tree' => $actual, 'same_directory_as_staged' => $stagedIdentity !== null && $stagedIdentity['ino'] !== 0];
    }

    /**
     * Whether a link target leaves the tree. Relative targets are resolved
     * lexically from the link's directory; absolute targets are only inside
     * when they point below the live destination the tree will occupy.
     */
    private function escapes(string $target, string $linkDirectory, string $liveDestination): bool
    {
        if ($target === '') {
            return true;
        }

        $target = str_replace('\\', '/', $target);

        if (PathGuard::isAbsolute($target) || str_starts_with($target, '/')) {
            return ! PathGuard::isWithin($target, $liveDestination) || str_contains($target.'/', '/../');
        }

        $depth = $linkDirectory === '.' || $linkDirectory === '' ? 0 : count(explode('/', $linkDirectory));

        foreach (explode('/', $target) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            $depth += $segment === '..' ? -1 : 1;

            if ($depth < 0) {
                return true;
            }
        }

        return false;
    }
}
