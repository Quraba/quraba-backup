<?php

declare(strict_types=1);

namespace Quraba\Backup\Enums;

enum ArtifactKind: string
{
    case ApplicationArchive = 'application_archive';
    case ResticSnapshot = 'restic_snapshot';
    case RemoteManifest = 'remote_manifest';

    /**
     * Each artifact kind lives in exactly one storage location; the Restic
     * prefix is never manipulated by anything but Restic.
     */
    public function storage(): ArtifactStorage
    {
        return match ($this) {
            self::ApplicationArchive => ArtifactStorage::B2Archive,
            self::ResticSnapshot => ArtifactStorage::Restic,
            self::RemoteManifest => ArtifactStorage::B2Manifest,
        };
    }
}
