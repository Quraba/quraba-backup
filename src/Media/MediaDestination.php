<?php

declare(strict_types=1);

namespace Quraba\Backup\Media;

/**
 * Where a logical media root lives on THIS host, whether or not the
 * directory currently exists (a clean host has none yet). Used by restores
 * to map a snapshot root to its destination by name.
 */
final readonly class MediaDestination
{
    public function __construct(
        public string $name,
        public string $path,
        public bool $exists,
        public bool $optional,
        public bool $allowSymlinks,
        public ?string $staging,
    ) {}
}
