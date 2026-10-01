<?php

declare(strict_types=1);

namespace Quraba\Backup\Restore\Live;

use Closure;
use Quraba\Backup\Exceptions\RestoreFailed;
use Quraba\Backup\Media\MediaDestination;
use Quraba\Backup\Support\PathGuard;
use Quraba\Backup\Workspace\OperationWorkspace;
use Quraba\Backup\Workspace\WorkspaceArea;
use Quraba\Backup\Workspace\WorkspaceDeleter;

/**
 * Chooses where each media root is staged for a live restore. Media is
 * never restored directly into a live root.
 *
 * A staging location qualifies only when it is
 *  - PRIVATE: the package's own operation workspace, or a directory the
 *    operator configured for that root (`restic.media.roots.{name}.staging`)
 *    that is not web-public and not inside any media root; and
 *  - on the SAME FILESYSTEM as the root's live destination, proven twice:
 *    equal device IDs, and an actual directory rename from the staging
 *    location into the destination's parent (with a throwaway probe).
 *
 * The private workspace is preferred. A configured location is only used
 * when the workspace is on another filesystem. If neither qualifies the
 * live media restore is REFUSED: there is no fallback to a public
 * directory and no fallback to copying.
 */
final readonly class MediaStaging
{
    public const string OWNED_PREFIX = 'quraba-restore-';

    private const string OWNED_PATTERN = '/^quraba-restore-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /** @var Closure(string): (int|null) */
    private Closure $deviceOf;

    /**
     * @param  (Closure(string): (int|null))|null  $deviceOf  test seam: the device ID of a path
     */
    public function __construct(private string $publicPath, ?Closure $deviceOf = null)
    {
        $this->deviceOf = $deviceOf ?? static function (string $path): ?int {
            clearstatcache(true, $path);
            $stat = @stat($path);

            return $stat === false ? null : $stat['dev'];
        };
    }

    /**
     * @param  list<MediaDestination>  $destinations  the roots being restored
     * @param  list<string>  $allRoots  real paths of every configured media root
     * @return list<MediaStagingArea>
     *
     * @throws RestoreFailed
     */
    public function plan(array $destinations, array $allRoots, OperationWorkspace $workspace, string $restoreUuid, string $snapshotId): array
    {
        $inWorkspace = [];
        $configured = [];

        foreach ($destinations as $destination) {
            $parent = $this->parent($destination);

            if ($this->sameFilesystem($workspace->area(WorkspaceArea::Restore), $parent)) {
                $inWorkspace[] = $destination->name;

                continue;
            }

            if ($destination->staging === null) {
                throw RestoreFailed::stagingUnavailable(sprintf('media root [%s] is on another filesystem than the private package workspace and has no configured staging directory (restic.media.roots.%s.staging)', $destination->name, $destination->name));
            }

            $base = $this->configuredBase($destination, $allRoots);

            if (! $this->sameFilesystem($base, $parent)) {
                throw RestoreFailed::stagingUnavailable(sprintf('the staging directory configured for media root [%s] is not on the same filesystem as its destination (or a directory cannot be renamed between them)', $destination->name));
            }

            $configured[$base][] = $destination->name;
        }

        $areas = [];
        $leaf = 'snapshot-'.substr($snapshotId, 0, 16);

        if ($inWorkspace !== []) {
            $areas[] = new MediaStagingArea(MediaStagingArea::WORKSPACE, $workspace->directory(WorkspaceArea::Restore, $leaf), $inWorkspace, null);
        }

        foreach ($configured as $base => $names) {
            $owned = $base.'/'.self::OWNED_PREFIX.$restoreUuid;

            // Never reuse: a leftover directory may hold another restore's data.
            if (file_exists($owned) || is_link($owned) || ! @mkdir($owned, 0700) || ! @mkdir($owned.'/'.$leaf, 0700)) {
                throw RestoreFailed::stagingUnavailable(sprintf('a private staging directory could not be created below [%s]', $base));
            }

            @chmod($owned, 0700);
            $areas[] = new MediaStagingArea(MediaStagingArea::CONFIGURED, $owned.'/'.$leaf, $names, $owned);
        }

        return $areas;
    }

    /**
     * Whether a directory can be renamed from `$from` into `$to`: both are
     * on one device AND a real rename of a throwaway directory succeeds.
     */
    public function sameFilesystem(string $from, string $to): bool
    {
        $a = ($this->deviceOf)($from);
        $b = ($this->deviceOf)($to);

        if ($a === null || $b === null || $a !== $b) {
            return false;
        }

        $suffix = bin2hex(random_bytes(12));
        $source = rtrim($from, '/').'/.quraba-staging-probe-'.$suffix;
        $moved = rtrim($to, '/').'/.quraba-staging-probe-'.$suffix;

        if (! @mkdir($source, 0700)) {
            return false;
        }

        try {
            return @rename($source, $moved) && is_dir($moved) && ! is_dir($source);
        } finally {
            if (is_dir($moved)) {
                @rmdir($moved);
            }

            if (is_dir($source)) {
                @rmdir($source);
            }
        }
    }

    /**
     * Removes the directory this package created inside a configured staging
     * location, once it holds nothing but empty directories. Never follows
     * links and never removes a file: staged data that was not swapped in is
     * left for the operator.
     */
    public function release(MediaStagingArea $area): bool
    {
        if ($area->owned === null) {
            return true;
        }

        if (is_link($area->owned) || ! str_starts_with(basename($area->owned), self::OWNED_PREFIX)) {
            return false;
        }

        return self::removeEmptyDirectories($area->owned);
    }

    /**
     * Deletes the directory this package created for a restore that changed
     * nothing (staged data that will never be used). Link-safe; refuses any
     * directory that is not exactly `quraba-restore-{uuid}`.
     *
     * @return list<string> errors
     */
    public function discard(MediaStagingArea $area): array
    {
        if ($area->owned === null) {
            return [];
        }

        $base = PathGuard::real(dirname($area->owned));

        return $base === null ? ['The staging base directory cannot be resolved.'] : WorkspaceDeleter::deleteTree($area->owned, $base, self::OWNED_PATTERN);
    }

    private function parent(MediaDestination $destination): string
    {
        $parent = dirname($destination->path);

        if (! is_dir($parent) || is_link($parent) || ! is_writable($parent)) {
            throw RestoreFailed::stagingUnavailable(sprintf('the parent directory of media root [%s] does not exist, is a link or is not writable, so the root cannot be replaced by rename', $destination->name));
        }

        return $parent;
    }

    /**
     * @param  list<string>  $allRoots
     */
    private function configuredBase(MediaDestination $destination, array $allRoots): string
    {
        $configured = (string) $destination->staging;

        if (! PathGuard::isAbsolute($configured) || is_link($configured) || ! is_dir($configured) || ! is_writable($configured)) {
            throw RestoreFailed::stagingUnavailable(sprintf('the staging directory configured for media root [%s] must be an existing, writable, absolute directory that is not a link', $destination->name));
        }

        $base = PathGuard::real($configured) ?? throw RestoreFailed::stagingUnavailable('the configured staging directory cannot be resolved');
        $public = PathGuard::real($this->publicPath) ?? $this->publicPath;

        if (PathGuard::isWithin($base, $public)) {
            throw RestoreFailed::stagingUnavailable(sprintf('the staging directory configured for media root [%s] is inside the public web directory; staged media must stay private', $destination->name));
        }

        foreach ($allRoots as $root) {
            if (PathGuard::isWithin($base, $root) || PathGuard::isWithin($root, $base)) {
                throw RestoreFailed::stagingUnavailable(sprintf('the staging directory configured for media root [%s] overlaps a live media root', $destination->name));
            }
        }

        return $base;
    }

    private static function removeEmptyDirectories(string $directory): bool
    {
        if (is_link($directory) || ! is_dir($directory)) {
            return false;
        }

        foreach (@scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (! self::removeEmptyDirectories($directory.'/'.$entry)) {
                return false;
            }
        }

        return @rmdir($directory);
    }
}
