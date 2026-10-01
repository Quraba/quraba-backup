<?php

declare(strict_types=1);

namespace Quraba\Backup\Manifest;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Quraba\Backup\Domain\Identifiers;
use Quraba\Backup\Enums\BackupProfile;
use Quraba\Backup\Enums\BackupStatus;
use Quraba\Backup\Enums\BackupTrigger;
use Quraba\Backup\Enums\ConsistencyLevel;
use Quraba\Backup\Identity\ApplicationIdentity;
use Throwable;

/**
 * A strictly validated remote manifest (schema version 1).
 *
 * Manifests are data read from remote storage, so nothing is trusted: every
 * identity is re-validated, unknown shapes are rejected, and a manifest must
 * sit at the path its own run UUID dictates.
 */
final readonly class RemoteManifest
{
    /**
     * @param  list<array{name: string, path: string}>  $snapshotRoots
     */
    private function __construct(
        public string $locator,
        public int $schemaVersion,
        public string $runUuid,
        public string $appId,
        public string $environment,
        public BackupProfile $profile,
        public BackupStatus $status,
        public ConsistencyLevel $consistency,
        public bool $recoveryPoint,
        public CarbonImmutable $createdAt,
        public string $packageVersion,
        public string $archiveState,
        public string $snapshotState,
        public ?string $archiveLocator,
        public ?string $archiveSha256,
        public ?int $archiveBytes,
        public ?string $repositoryId,
        public ?string $snapshotId,
        public ?string $snapshotKind,
        public array $snapshotRoots,
        public BackupTrigger $trigger = BackupTrigger::Manual,
        public ?bool $databaseEventsIncluded = null,
        public ?bool $databaseExact = null,
    ) {}

    /**
     * @throws InvalidArgumentException when the document is not a valid manifest
     */
    public static function parse(string $locator, string $json): self
    {
        $data = json_decode($json, true);

        if (! is_array($data)) {
            throw new InvalidArgumentException('not a JSON object');
        }

        if (($data['schema_version'] ?? null) !== ManifestBuilder::SCHEMA_VERSION) {
            throw new InvalidArgumentException('unsupported schema version');
        }

        $runUuid = self::string($data, 'run_uuid');
        $appId = self::string($data, 'app_id');
        $environment = self::string($data, 'environment');

        if (! Identifiers::isUuid($runUuid) || ! Identifiers::isUuid($appId) || ! ApplicationIdentity::isValidEnvironment($environment)) {
            throw new InvalidArgumentException('invalid identity fields');
        }

        if (! str_ends_with($locator, '/'.$runUuid.'.json')) {
            throw new InvalidArgumentException('the manifest path does not match its run UUID');
        }

        $profile = BackupProfile::tryFrom(self::string($data, 'profile')) ?? throw new InvalidArgumentException('unknown profile');
        $status = BackupStatus::tryFrom(self::string($data, 'status'));

        if (! in_array($status, [BackupStatus::Completed, BackupStatus::Partial], true)) {
            throw new InvalidArgumentException('invalid status');
        }

        $consistency = ConsistencyLevel::tryFrom(self::string($data, 'consistency')) ?? throw new InvalidArgumentException('unknown consistency');

        if (! is_bool($data['recovery_point'] ?? null)) {
            throw new InvalidArgumentException('invalid recovery_point');
        }

        try {
            $createdAt = CarbonImmutable::parse(self::string($data, 'created_at'))->utc();
        } catch (Throwable) {
            throw new InvalidArgumentException('invalid created_at');
        }

        if (! str_contains($locator, '/'.$createdAt->format('Y/m/d').'/'.$runUuid.'.json')) {
            throw new InvalidArgumentException('manifest path date differs from created_at');
        }

        $components = is_array($data['components'] ?? null) ? $data['components'] : [];
        $archiveState = self::state($components['application_archive'] ?? null);
        $snapshotState = self::state($components['media_snapshot'] ?? null);

        [$archiveLocator, $archiveSha, $archiveBytes] = self::archive($data['archive'] ?? null, $archiveState);

        $restic = $data['restic'] ?? null;

        if (! is_array($restic)) {
            throw new InvalidArgumentException('missing restic section');
        }

        $repositoryId = $restic['repository_id'] ?? null;

        if ($repositoryId !== null && (! is_string($repositoryId) || ! Identifiers::isRepositoryId($repositoryId))) {
            throw new InvalidArgumentException('invalid repository_id');
        }

        [$snapshotId, $snapshotKind, $roots] = self::snapshot($restic['snapshot'] ?? null, $snapshotState);

        if ($snapshotId !== null && $repositoryId === null) {
            throw new InvalidArgumentException('a snapshot without a repository ID');
        }

        $recoveryPoint = $data['recovery_point'];

        if ($recoveryPoint && ($profile !== BackupProfile::Recovery || $status !== BackupStatus::Completed || $archiveLocator === null || $snapshotId === null)) {
            throw new InvalidArgumentException('claims a Recovery Point without both verified components');
        }

        $validComponents = match ($profile) {
            BackupProfile::Database => $status === BackupStatus::Completed && $archiveState === 'verified' && $snapshotState === 'not_requested' && ! $recoveryPoint,
            BackupProfile::Media => $status === BackupStatus::Completed && $archiveState === 'not_requested' && $snapshotState === 'verified' && $snapshotKind === 'media' && ! $recoveryPoint,
            BackupProfile::Recovery => ($status === BackupStatus::Completed && $archiveState === 'verified' && $snapshotState === 'verified' && $snapshotKind === 'recovery_media' && $recoveryPoint)
                || ($status === BackupStatus::Partial && ! $recoveryPoint && (($archiveState === 'verified' && $snapshotState === 'failed') || ($archiveState === 'failed' && $snapshotState === 'verified' && $snapshotKind === 'recovery_media'))),
        };
        if (! $validComponents) {
            throw new InvalidArgumentException('profile, status and component claims conflict');
        }

        $databaseData = is_array($data['database'] ?? null) ? $data['database'] : [];

        return new self(
            $locator,
            ManifestBuilder::SCHEMA_VERSION,
            $runUuid,
            $appId,
            $environment,
            $profile,
            $status,
            $consistency,
            $recoveryPoint,
            $createdAt,
            is_string($data['package_version'] ?? null) ? mb_substr($data['package_version'], 0, 64) : 'unknown',
            $archiveState,
            $snapshotState,
            $archiveLocator,
            $archiveSha,
            $archiveBytes,
            $repositoryId,
            $snapshotId,
            $snapshotKind,
            $roots,
            BackupTrigger::tryFrom(is_string($data['trigger'] ?? null) ? $data['trigger'] : '') ?? BackupTrigger::Manual,
            is_bool($databaseData['events_included'] ?? null) ? $databaseData['events_included'] : null,
            is_bool($databaseData['exact_object_completeness'] ?? null) ? $databaseData['exact_object_completeness'] : null,
        );
    }

    public function hasVerifiedArchive(): bool
    {
        return $this->archiveLocator !== null;
    }

    public function hasVerifiedSnapshot(): bool
    {
        return $this->snapshotId !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'run_uuid' => $this->runUuid,
            'environment' => $this->environment,
            'profile' => $this->profile->value,
            'trigger' => $this->trigger->value,
            'status' => $this->status->value,
            'consistency' => $this->consistency->value,
            'recovery_point' => $this->recoveryPoint,
            'database_events_included' => $this->databaseEventsIncluded,
            'database_exact' => $this->databaseExact,
            'created_at' => $this->createdAt->toIso8601ZuluString(),
            'package_version' => $this->packageVersion,
            'components' => ['application_archive' => $this->archiveState, 'media_snapshot' => $this->snapshotState],
            'archive' => $this->archiveLocator === null ? null : ['locator' => $this->archiveLocator, 'sha256' => $this->archiveSha256, 'bytes' => $this->archiveBytes],
            'repository_id' => $this->repositoryId,
            'snapshot_id' => $this->snapshotId,
            'snapshot_kind' => $this->snapshotKind,
            'manifest' => $this->locator,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException(sprintf('missing %s', $key));
        }

        return $value;
    }

    private static function state(mixed $value): string
    {
        if (! in_array($value, ['verified', 'failed', 'not_requested'], true)) {
            throw new InvalidArgumentException('invalid component state');
        }

        return $value;
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?int}
     */
    private static function archive(mixed $archive, string $state): array
    {
        if ($archive === null) {
            if ($state === 'verified') {
                throw new InvalidArgumentException('a verified archive without details');
            }

            return [null, null, null];
        }

        if (! is_array($archive) || $state !== 'verified' || ($archive['status'] ?? null) !== 'verified') {
            throw new InvalidArgumentException('inconsistent archive section');
        }

        $locator = $archive['locator'] ?? null;
        $sha = $archive['sha256'] ?? null;
        $bytes = $archive['bytes'] ?? null;

        if (! is_string($locator) || ! is_string($sha) || ! Identifiers::isSha256($sha) || ! is_int($bytes) || $bytes <= 0) {
            throw new InvalidArgumentException('invalid archive details');
        }

        Identifiers::assertObjectLocator($locator);

        return [$locator, $sha, $bytes];
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: list<array{name: string, path: string}>}
     */
    private static function snapshot(mixed $snapshot, string $state): array
    {
        if ($snapshot === null) {
            if ($state === 'verified') {
                throw new InvalidArgumentException('a verified snapshot without details');
            }

            return [null, null, []];
        }

        if (! is_array($snapshot) || $state !== 'verified' || ($snapshot['status'] ?? null) !== 'verified') {
            throw new InvalidArgumentException('inconsistent snapshot section');
        }

        $id = $snapshot['id'] ?? null;
        $kind = $snapshot['kind'] ?? null;

        if (! is_string($id) || ! Identifiers::isFullSnapshotId($id) || ! is_string($kind) || $kind === '') {
            throw new InvalidArgumentException('invalid snapshot details');
        }

        $roots = [];

        $names = [];
        $paths = [];
        foreach (is_array($snapshot['roots'] ?? null) ? $snapshot['roots'] : [] as $root) {
            if (! is_array($root) || ! is_string($root['name'] ?? null) || ! is_string($root['path'] ?? null) || $root['name'] === '' || $root['path'] === '') {
                throw new InvalidArgumentException('invalid snapshot roots');
            }

            if (preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $root['name']) !== 1
                || (! str_starts_with($root['path'], '/') && preg_match('~^[A-Za-z]:/~', $root['path']) !== 1)
                || str_contains($root['path'], '/../') || str_ends_with($root['path'], '/..')
                || isset($names[$root['name']]) || isset($paths[$root['path']])) {
                throw new InvalidArgumentException('ambiguous or unsafe snapshot roots');
            }
            $names[$root['name']] = true;
            $paths[$root['path']] = true;

            $roots[] = ['name' => $root['name'], 'path' => $root['path']];
        }

        if ($roots === []) {
            throw new InvalidArgumentException('a verified snapshot without roots');
        }

        return [$id, $kind, $roots];
    }
}
