<?php

declare(strict_types=1);

namespace Quraba\Backup\Restore;

use Quraba\Backup\Enums\RestoreProfile;

/** Exact, frozen identities for one source run. */
final readonly class RestoreSource
{
    /** @param list<array{name: string, path: string}> $mediaRoots */
    public function __construct(
        public string $runUuid,
        public string $appId,
        public string $environment,
        public string $backupProfile,
        public string $status,
        public string $consistency,
        public ?string $archiveLocator,
        public ?string $archiveSha256,
        public ?int $archiveBytes,
        public ?string $repositoryId,
        public ?string $snapshotId,
        public ?string $snapshotKind,
        public array $mediaRoots,
        public ?int $manifestSchema,
        public string $origin,
    ) {}

    public function supports(RestoreProfile $profile): bool
    {
        return match ($profile) {
            RestoreProfile::Database => $this->archiveLocator !== null && $this->archiveSha256 !== null,
            RestoreProfile::Media => $this->snapshotId !== null && $this->repositoryId !== null,
            RestoreProfile::Full => $this->backupProfile === 'recovery' && $this->status === 'completed'
                && $this->archiveLocator !== null && $this->archiveSha256 !== null
                && $this->snapshotId !== null && $this->repositoryId !== null,
        };
    }

    public function withOrigin(string $origin): self
    {
        return new self(
            $this->runUuid, $this->appId, $this->environment, $this->backupProfile, $this->status,
            $this->consistency, $this->archiveLocator, $this->archiveSha256, $this->archiveBytes,
            $this->repositoryId, $this->snapshotId, $this->snapshotKind, $this->mediaRoots,
            $this->manifestSchema, $origin,
        );
    }

    /** @return array<string, mixed> */
    public function identities(): array
    {
        return [
            'run_uuid' => $this->runUuid,
            'app_id' => $this->appId,
            'environment' => $this->environment,
            'backup_profile' => $this->backupProfile,
            'status' => $this->status,
            'consistency' => $this->consistency,
            'archive_locator' => $this->archiveLocator,
            'archive_sha256' => $this->archiveSha256,
            'archive_bytes' => $this->archiveBytes,
            'repository_id' => $this->repositoryId,
            'snapshot_id' => $this->snapshotId,
            'snapshot_kind' => $this->snapshotKind,
            'media_roots' => $this->mediaRoots,
            'manifest_schema' => $this->manifestSchema,
        ];
    }
}
