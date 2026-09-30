<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Support;

use Closure;
use Quraba\Backup\Contracts\ReleaseDownloader;
use Quraba\Backup\Exceptions\StorageUnavailable;

/**
 * Serves release assets from memory so installer tests never touch the network.
 */
final class FakeReleaseDownloader implements ReleaseDownloader
{
    /** @var list<string> */
    public array $requested = [];

    /**
     * @param  array<string, string|Closure(string): void>  $assets  url => content, or a callback that simulates a failure
     */
    public function __construct(private array $assets) {}

    public function download(string $url, string $destination, int $maxBytes): void
    {
        $this->requested[] = $url;
        $asset = $this->assets[$url] ?? null;

        if ($asset === null) {
            throw new StorageUnavailable('404 for '.$url);
        }

        if ($asset instanceof Closure) {
            $asset($destination);

            return;
        }

        if (strlen($asset) > $maxBytes) {
            throw new StorageUnavailable('size limit exceeded');
        }

        file_put_contents($destination, $asset);
    }
}
