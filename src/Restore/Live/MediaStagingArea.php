<?php

declare(strict_types=1);

namespace Quraba\Backup\Restore\Live;

/**
 * A private, empty directory — on the SAME filesystem as the live media
 * roots it serves — into which Restic reconstructs a snapshot before the
 * staged trees are renamed into place. Only {@see MediaStaging} creates one,
 * after proving privacy, emptiness and rename capability.
 */
final readonly class MediaStagingArea
{
    public const string WORKSPACE = 'workspace';

    public const string CONFIGURED = 'configured';

    /**
     * @param  list<string>  $roots  logical names of the media roots staged here
     * @param  string|null  $owned  directory the package created for this restore and may remove afterwards (configured areas only)
     *
     * @internal use {@see MediaStaging::plan()}
     */
    public function __construct(
        public string $kind,
        public string $target,
        public array $roots,
        public ?string $owned,
    ) {}
}
