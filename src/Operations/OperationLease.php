<?php

declare(strict_types=1);

namespace Quraba\Backup\Operations;

use Quraba\Backup\Domain\Identifiers;
use Quraba\Backup\Support\PackagePaths;
use Quraba\Backup\Support\PrivateFile;

final readonly class OperationLease
{
    public function __construct(private PackagePaths $paths) {}

    /** @return resource */
    public function acquire(string $uuid)
    {
        $uuid = Identifiers::assertUuid($uuid, 'The operation UUID');
        $directory = PackagePaths::ensureDirectory($this->paths->root.'/runtime');
        $path = $directory.'/operation-'.$uuid.'.lock';
        if (is_link($path)) {
            throw new \RuntimeException('The operation lease is a symbolic link.');
        }
        $handle = is_file($path) ? fopen($path, 'c+b') : PrivateFile::create($path);
        if ($handle === false) {
            throw new \RuntimeException('The operation lease cannot be opened.');
        }
        PrivateFile::assertStillPrivate($path, $handle);
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new \RuntimeException('The operation lease is busy.');
        }

        return $handle;
    }

    public function isHeld(string $uuid): bool
    {
        $handle = $this->acquire($uuid);
        flock($handle, LOCK_UN);
        fclose($handle);

        return false;
    }
}
