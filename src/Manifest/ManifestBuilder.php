<?php

declare(strict_types=1);

namespace Quraba\Backup\Manifest;

use JsonException;
use Quraba\Backup\Enums\ArtifactKind;
use Quraba\Backup\Enums\ArtifactStatus;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Enums\BackupStatus;
use Quraba\Backup\Exceptions\ManifestStoreFailed;
use Quraba\Backup\Identity\ApplicationIdentity;
use Quraba\Backup\Models\BackupArtifact;
use Quraba\Backup\Models\BackupRun;

/**
 * Builds the immutable, non-secret remote manifest of a run.
 *
 * The content is a pure function of the run's catalog state (verified
 * artifacts, request time, recorded package version), so rebuilding it
 * during reconciliation yields byte-identical JSON. Only verified
 * components are described in detail; a recovery point is claimed only when
 * both required components are verified.
 */
final class ManifestBuilder
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @return array<array-key, mixed>
     */
    public static function build(BackupRun $run, ApplicationIdentity $identity, BackupStatus $outcome): array
    {
        if (! in_array($outcome, [BackupStatus::Completed, BackupStatus::Partial], true)) {
            throw new ManifestStoreFailed('Manifests are only written for completed or partial runs.');
        }

        $artifacts = [];

        foreach ($run->artifacts()->get() as $artifact) {
            $artifacts[$artifact->kind->value] = $artifact;
        }

        $archive = $artifacts[ArtifactKind::ApplicationArchive->value] ?? null;
        $snapshot = $artifacts[ArtifactKind::ResticSnapshot->value] ?? null;

        $archiveVerified = $archive?->status === ArtifactStatus::Verified;
        $snapshotVerified = $snapshot?->status === ArtifactStatus::Verified;

        $archiveDetails = null;

        if ($archive !== null && $archiveVerified) {
            $archiveDetails = [
                'locator' => $archive->locator,
                'sha256' => $archive->sha256,
                'bytes' => $archive->byte_size,
                'status' => ArtifactStatus::Verified->value,
            ];
        }

        $snapshotDetails = null;

        if ($snapshot !== null && $snapshotVerified) {
            $snapshotDetails = [
                'id' => $snapshot->snapshot_id,
                'kind' => $snapshot->metadata['kind'] ?? null,
                'status' => ArtifactStatus::Verified->value,
                'roots' => $snapshot->metadata['roots'] ?? [],
            ];
        }

        $metadata = $run->metadata ?? [];

        $manifest = [
            'schema_version' => self::SCHEMA_VERSION,
            'run_uuid' => $run->uuid,
            'app_id' => $identity->appId,
            'environment' => $identity->environment,
            'profile' => $run->profile->value,
            'trigger' => $run->trigger->value,
            'status' => $outcome->value,
            'consistency' => $run->consistency->value,
            'recovery_point' => $run->profile === BackupProfile::Recovery && $archiveVerified && $snapshotVerified && $outcome === BackupStatus::Completed,
            'created_at' => $run->requested_at?->toIso8601ZuluString(),
            'package_version' => is_string($metadata['package_version'] ?? null) ? $metadata['package_version'] : 'unknown',
            'components' => [
                'application_archive' => self::componentState($run, BackupProfile::Database, $archive),
                'media_snapshot' => self::componentState($run, BackupProfile::Media, $snapshot),
            ],
            'archive' => $archiveDetails,
            'restic' => [
                'repository_id' => is_string($metadata['restic_repository_id'] ?? null) ? $metadata['restic_repository_id'] : null,
                'snapshot' => $snapshotDetails,
            ],
        ];

        return self::canonicalize($manifest);
    }

    /**
     * @param  array<array-key, mixed>  $manifest
     */
    public static function encode(array $manifest): string
    {
        try {
            return json_encode(self::canonicalize($manifest), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        } catch (JsonException $exception) {
            throw new ManifestStoreFailed('The manifest could not be encoded: '.$exception->getMessage());
        }
    }

    /**
     * Canonical form of existing JSON for comparison, or null when it is not
     * a JSON object.
     */
    public static function canonicalJson(string $json): ?string
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? self::encode($decoded) : null;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    public static function canonicalize(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonicalize($item);
            }
        }

        return $value;
    }

    private static function componentState(BackupRun $run, BackupProfile $componentProfile, ?BackupArtifact $artifact): string
    {
        $requested = $run->profile === BackupProfile::Recovery || $run->profile === $componentProfile;

        if (! $requested) {
            return 'not_requested';
        }

        return $artifact?->status === ArtifactStatus::Verified ? 'verified' : 'failed';
    }
}
