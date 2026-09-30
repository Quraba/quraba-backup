<?php

declare(strict_types=1);

namespace Quraba\Backup\Storage;

/**
 * A remote object whose existence, size and content hash were proven.
 */
final readonly class StoredObject
{
    public function __construct(
        public string $locator,
        public string $sha256,
        public int $bytes,
        public bool $adopted,
    ) {}
}
