<?php

declare(strict_types=1);

namespace Quraba\Backup\Restore;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Quraba\Backup\Enums\RestoreProfile;
use Quraba\Backup\Exceptions\RestoreFailed;
use Quraba\Backup\Identity\IdentityResolver;
use Quraba\Backup\Media\MediaDestination;
use Quraba\Backup\Restore\Live\MediaStagingArea;
use Quraba\Backup\Workspace\OperationWorkspace;

/**
 * The ONE non-destructive restore preparation, shared by the dry run and by
 * the live restore (which never trusts an earlier dry run and repeats all of
 * it immediately before it changes anything):
 *
 *   resolve the exact immutable source → freeze its identities
 *   → capacity and rename preflight
 *   → download the archive, verify its SHA-256, decrypt and extract privately
 *   → APP_KEY and release compatibility (an APP_KEY mismatch blocks)
 *   → verify repository and exact snapshot identity, reconstruct into
 *     private staging, map every logical media root
 *   → validate the database dump at the configured level.
 *
 * Nothing here writes to the live database, a live media root or `.env`.
 */
final readonly class RestorePreparation
{
    public function __construct(
        private Repository $config,
        private IdentityResolver $identities,
        private RestoreSourceResolver $sources,
        private RestorePreflight $preflight,
        private ArchiveReconstructor $archives,
        private ResticReconstructor $media,
        private RestoreDatabaseValidator $database,
    ) {}

    /**
     * @param  array<string, mixed>  $report  filled progressively, so a failure still reports what was established
     * @param  Closure(string, ?RestoreSource): void  $stage  called at `resolved`, `reconstructing` and `validating`
     * @param  (Closure(list<MediaDestination>, list<string>): list<MediaStagingArea>)|null  $staging  live restores stage media on the filesystem of its destination
     *
     * @throws RestoreFailed
     */
    public function prepare(string $runUuid, RestoreProfile $profile, OperationWorkspace $workspace, array &$report, Closure $stage, ?Closure $staging = null): PreparedRestore
    {
        $identity = $this->identities->current();
        $source = $this->sources->resolve($runUuid, $profile);
        $report['source'] = $source->origin;
        $report['consistency'] = $source->consistency;
        $report['repository_id'] = $profile === RestoreProfile::Database ? null : $source->repositoryId;
        $report['snapshot_id'] = $profile === RestoreProfile::Database ? null : $source->snapshotId;
        $report['archive_sha256'] = $profile === RestoreProfile::Media ? null : $source->archiveSha256;
        $stage('resolved', $source);

        $preflight = $this->preflight->check($source, $profile, $workspace);
        $report['required_disk_bytes'] = $preflight['required_bytes'];
        $report['free_disk_bytes'] = $preflight['free_bytes'];
        $report['atomic_rename'] = $preflight['atomic_rename'];
        $report['warnings'] = [...self::strings($report['warnings'] ?? []), ...$preflight['warnings']];

        if ($preflight['blockers'] !== []) {
            $report['blockers'] = $preflight['blockers'];

            throw RestoreFailed::reconstructionFailed('preflight found blockers');
        }

        $stage('reconstructing', $source);
        $archive = null;
        $blockers = [];

        if ($profile !== RestoreProfile::Media) {
            $archive = $this->archives->reconstruct($source, $identity, $workspace);
            $report['archive_verified'] = $archive['archive_verified'];
            $report['archive_sha256'] = $archive['archive_sha256'];
            $report['app_key_compatibility'] = $archive['app_key_compatibility'];
            $report['release_compatibility'] = $archive['release_compatibility'];

            if ($archive['release_compatibility'] === 'warning') {
                $report['warnings'] = [...self::strings($report['warnings']), 'The backup release fingerprint differs from this installation; review compatibility before relying on the restored application.'];
            }

            if ($archive['app_key_compatibility'] === 'mismatch') {
                $blockers[] = 'The backup APP_KEY fingerprint differs from the current application.';
            }
        }

        $mappings = [];

        if ($profile !== RestoreProfile::Database) {
            $mappings = $this->media->reconstruct($source, $identity, $workspace, $staging);
            $report['media_roots'] = array_map(static fn (MediaRootMapping $mapping): array => $mapping->toArray(), $mappings);
        }

        $stage('validating', $source);
        $validation = null;

        if ($archive !== null) {
            $setting = $this->config->get('quraba-backup.restore.db_validation_level', 'artifact');
            $level = is_string($setting) ? DbValidationLevel::tryFrom($setting) : null;

            if ($level === null) {
                throw RestoreFailed::reconstructionFailed('invalid restore DB validation level');
            }

            $validation = $this->database->validate($archive['database_dump'], $archive['metadata'], $level, $workspace);
            $report['db_validation_level'] = $validation['level'];
            $report['database_validation'] = $validation;
        }

        if ($blockers !== []) {
            $report['blockers'] = $blockers;

            throw RestoreFailed::reconstructionFailed('validation found blockers');
        }

        return new PreparedRestore($source, $identity, $archive, $mappings, $validation);
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        return array_values(array_filter(is_array($values) ? $values : [], is_string(...)));
    }
}
