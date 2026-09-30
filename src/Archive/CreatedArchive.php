<?php

declare(strict_types=1);

namespace Quraba\Backup\Archive;

/**
 * An archive produced by the engine, not yet verified.
 */
final readonly class CreatedArchive
{
    /**
     * @param  array<string, mixed>  $metadata  the quraba-backup.json content
     */
    public function __construct(
        public string $path,
        public string $databaseEntry,
        public array $metadata,
    ) {}
}
