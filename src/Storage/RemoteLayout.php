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
 *   {prefix}/{app_id}/{restic}/...          ← owned exclusively by Restic
 *
 * Dates come from the run's immutable UTC request time, so every retry of a
 * run addresses exactly the same objects.
 */
final readonly class RemoteLayout
{
    public const string ARCHIVES = 'archives';

    public const string MANIFESTS = 'manifests';

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

        if (in_array($firstResticSegment, [self::ARCHIVES, self::MANIFESTS], true)) {
            throw new ConfigurationException('QURABA_BACKUP_RESTIC_PREFIX must not be "archives" or "manifests"; the Restic prefix belongs only to Restic.');
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

        if (! str_starts_with($path, $this->archivesRoot()) && ! str_starts_with($path, $this->manifestsRoot())) {
            throw new ConfigurationException('Refusing to access a remote path outside this application\'s archives/manifests prefixes.');
        }

        return $path;
    }

    private function datePath(BackupRun $run): string
    {
        $requested = $run->requested_at ?? CarbonImmutable::now('UTC');

        return $requested->utc()->format('Y/m/d');
    }
}
