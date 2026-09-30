<?php

declare(strict_types=1);

namespace Quraba\Backup\Contracts;

use Quraba\Backup\Exceptions\StorageUnavailable;

/**
 * Downloads a release asset to a local file. Implementations must only
 * follow https URLs and must enforce the size limit while streaming.
 */
interface ReleaseDownloader
{
    /**
     * @throws StorageUnavailable
     */
    public function download(string $url, string $destination, int $maxBytes): void;
}
