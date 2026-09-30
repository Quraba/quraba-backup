<?php

declare(strict_types=1);

namespace Quraba\Backup\Archive;

/**
 * Structured proof that an archive is intact, decryptable and belongs to the
 * expected run/application/environment.
 */
final readonly class ArchiveVerification
{
    /**
     * @param  list<string>  $entries
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $path,
        public string $sha256,
        public int $bytes,
        public array $entries,
        public string $encryption,
        public array $metadata,
    ) {}

    /**
     * @return array{sha256: string, bytes: int, entries: list<string>, encryption: string, schema_version: mixed}
     */
    public function summary(): array
    {
        return [
            'sha256' => $this->sha256,
            'bytes' => $this->bytes,
            'entries' => $this->entries,
            'encryption' => $this->encryption,
            'schema_version' => $this->metadata['schema_version'] ?? null,
        ];
    }
}
