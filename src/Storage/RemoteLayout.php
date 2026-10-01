<?php

declare(strict_types=1);

namespace Quraba\Backup\Storage;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Quraba\Backup\Domain\Identifiers;
use Quraba\Backup\Exceptions\ConfigurationException;
use Quraba\Backup\Identity\IdentityResolver;
use Quraba\Backup\Models\BackupRun;
use Quraba\Backup\Restic\RepositoryLocation;

/**
 * Deterministic remote object layout inside the customer's bucket:
 *
 *   {prefix}/{app_id}/archives/YYYY/MM/DD/{run_uuid}/application.zip
 *   {prefix}/{app_id}/manifests/YYYY/MM/DD/{run_uuid}.json
 *   {prefix}/{app_id}/retention/{run_uuid}/{component}.json  ← one immutable
 *       expiry record per physically removed component
 *   {prefix}/{app_id}/retention/{run_uuid}.json   ← legacy combined tombstone (read only)
 *   {prefix}/{app_id}/{restic}/...          ← owned exclusively by Restic
 *
 * Dates come from the run's immutable UTC request time, so every retry of a
 * run addresses exactly the same objects.
 */
final readonly class RemoteLayout
{
    public const string ARCHIVES = 'archives';

    public const string MANIFESTS = 'manifests';

    public const string RETENTION = 'retention';

    private const string ARCHIVE_PATTERN = '~^archives/\d{4}/\d{2}/\d{2}/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/application\.zip$~';

    private function __construct(
        public string $prefix,
        public string $appId,
        public string $resticPrefix,
    ) {}

    public static function fromConfig(Repository $config, IdentityResolver $identity): self
    {
        $prefix = $config->get('quraba-backup.storage.b2.prefix');
        $resticPrefix = $config->get('restic.repository.prefix');

        if (! is_string($prefix) || trim($prefix) === '' || ! is_string($resticPrefix) || trim($resticPrefix) === '') {
            throw new ConfigurationException('QURABA_BACKUP_PREFIX and QURABA_BACKUP_RESTIC_PREFIX must be non-empty.');
        }

        return self::of($prefix, $identity->current()->appId, $resticPrefix);
    }

    public static function of(string $prefix, string $appId, string $resticPrefix): self
    {
        $prefix = RepositoryLocation::objectPath($prefix);
        $resticPrefix = RepositoryLocation::objectPath($resticPrefix);

        $firstResticSegment = explode('/', $resticPrefix)[0];

        if (in_array($firstResticSegment, [self::ARCHIVES, self::MANIFESTS, self::RETENTION], true)) {
            throw new ConfigurationException('QURABA_BACKUP_RESTIC_PREFIX must not be "archives", "manifests" or "retention"; the Restic prefix belongs only to Restic.');
        }

        return new self($prefix, Identifiers::assertUuid($appId, 'The application ID'), $resticPrefix);
    }

    public function applicationRoot(): string
    {
        return $this->prefix.'/'.$this->appId.'/';
    }

    public function archivesRoot(): string
    {
        return $this->applicationRoot().self::ARCHIVES.'/';
    }

    public function manifestsRoot(): string
    {
        return $this->applicationRoot().self::MANIFESTS.'/';
    }

    public function retentionRoot(): string
    {
        return $this->applicationRoot().self::RETENTION.'/';
    }

    /**
     * The legacy combined tombstone of a run. Still read (older releases
     * wrote it); new expiries are recorded per component.
     */
    public function tombstone(string $runUuid): string
    {
        return $this->retentionRoot().Identifiers::assertUuid($runUuid, 'The run UUID').'.json';
    }

    /**
     * The immutable expiry record of ONE component of a run
     * (`application_archive` or `media_snapshot`).
     */
    public function componentTombstone(string $runUuid, string $component): string
    {
        if (preg_match('/^[a-z][a-z_]{0,40}$/', $component) !== 1) {
            throw new ConfigurationException('Invalid retention component name.');
        }

        return $this->retentionRoot().Identifiers::assertUuid($runUuid, 'The run UUID').'/'.$component.'.json';
    }

    /**
     * The run UUID of an exact archive locator of this application, or null
     * when the path is not precisely `archives/YYYY/MM/DD/{uuid}/application.zip`.
     */
    public function archiveRunUuid(string $path): ?string
    {
        if (! str_starts_with($path, $this->applicationRoot())) {
            return null;
        }

        return preg_match(self::ARCHIVE_PATTERN, substr($path, strlen($this->applicationRoot())), $matches) === 1 ? $matches[1] : null;
    }

    public function resticRoot(): string
    {
        return $this->applicationRoot().$this->resticPrefix.'/';
    }

    public function archive(BackupRun $run): string
    {
        return $this->archivesRoot().$this->datePath($run).'/'.$run->uuid.'/application.zip';
    }

    public function manifest(BackupRun $run): string
    {
        return $this->manifestsRoot().$this->datePath($run).'/'.$run->uuid.'.json';
    }

    /**
     * Refuses any path the package does not own. In particular nothing under
     * the Restic prefix can ever be addressed through object storage.
     */
    public function assertManaged(string $path): string
    {
        Identifiers::assertObjectLocator($path);

        if (str_starts_with($path, $this->resticRoot()) || $path.'/' === $this->resticRoot()) {
            throw new ConfigurationException('Refusing to access the Restic repository prefix through object storage.');
        }

        if (! str_starts_with($path, $this->archivesRoot()) && ! str_starts_with($path, $this->manifestsRoot()) && ! str_starts_with($path, $this->retentionRoot())) {
            throw new ConfigurationException('Refusing to access a remote path outside this application\'s archives/manifests/retention prefixes.');
        }

        return $path;
    }

    private function datePath(BackupRun $run): string
    {
        $requested = $run->requested_at ?? CarbonImmutable::now('UTC');

        return $requested->utc()->format('Y/m/d');
    }
}
