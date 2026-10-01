<?php

declare(strict_types=1);

namespace Quraba\Backup\Restore;

use Quraba\Backup\Identity\ApplicationIdentity;

/**
 * Everything restore preparation established without touching the live
 * application: the frozen source, the reconstructed and verified archive,
 * the staged media trees and the database validation.
 */
final readonly class PreparedRestore
{
    /**
     * @param  array{archive_verified: true, archive_sha256: string, archive_bytes: int, database_dump: string, database_dump_bytes: int|false, database_dump_sha256: string, includes_env: bool, metadata: array<string, mixed>, app_key_compatibility: string, release_compatibility: string}|null  $archive
     * @param  list<MediaRootMapping>  $media
     * @param  array{level: string, dump_bytes: int, dump_tables: int, dump_definers: list<string>, dump_schema_fingerprint: ?string, live_schema_fingerprint: ?string, scratch_schema_fingerprint: ?string}|null  $validation
     */
    public function __construct(
        public RestoreSource $source,
        public ApplicationIdentity $identity,
        public ?array $archive,
        public array $media,
        public ?array $validation,
    ) {}

    /**
     * The `database` section of the archive metadata.
     *
     * @return array<string, mixed>
     */
    public function databaseMetadata(): array
    {
        $database = $this->archive['metadata']['database'] ?? null;
        $typed = [];

        foreach (is_array($database) ? $database : [] as $key => $value) {
            $typed[(string) $key] = $value;
        }

        return $typed;
    }
}
