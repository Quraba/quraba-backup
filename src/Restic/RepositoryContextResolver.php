<?php

declare(strict_types=1);

namespace Quraba\Backup\Restic;

use Illuminate\Contracts\Config\Repository;
use Quraba\Backup\Exceptions\ConfigurationException;
use Quraba\Backup\Identity\IdentityResolver;

/**
 * Builds the repository location, credentials and password-file reference
 * from configuration. Default layout (per the architecture):
 *
 *     s3:{endpoint}/{bucket}/{prefix}/{app_id}/{restic prefix}
 */
final readonly class RepositoryContextResolver
{
    public function __construct(
        private Repository $config,
        private ResticConfig $restic,
        private IdentityResolver $identity,
        private PasswordFileInspector $passwordFiles,
    ) {}

    public function isConfigured(): bool
    {
        try {
            $this->location();

            return true;
        } catch (ConfigurationException) {
            return false;
        }
    }

    public function location(): RepositoryLocation
    {
        $override = $this->config->get('restic.repository.url');
        $region = $this->stringOrNull($this->config->get('quraba-backup.storage.b2.region'));

        if (is_string($override) && trim($override) !== '') {
            return RepositoryLocation::parse($override, $region);
        }

        $endpoint = $this->stringOrNull($this->config->get('quraba-backup.storage.b2.endpoint'));
        $bucket = $this->stringOrNull($this->config->get('quraba-backup.storage.b2.bucket'));
        $prefix = $this->stringOrNull($this->config->get('quraba-backup.storage.b2.prefix'));
        $resticPrefix = $this->stringOrNull($this->config->get('restic.repository.prefix'));

        $missing = array_keys(array_filter([
            'QURABA_BACKUP_B2_ENDPOINT' => $endpoint,
            'QURABA_BACKUP_B2_BUCKET' => $bucket,
            'QURABA_BACKUP_PREFIX' => $prefix,
            'QURABA_BACKUP_RESTIC_PREFIX' => $resticPrefix,
        ], static fn (?string $value): bool => $value === null));

        if ($missing !== []) {
            throw new ConfigurationException('The Restic repository is not configured; missing: '.implode(', ', $missing).'.');
        }

        /** @var string $endpoint */
        /** @var string $bucket */
        /** @var string $prefix */
        /** @var string $resticPrefix */
        $appId = $this->identity->current()->appId;

        return RepositoryLocation::s3($endpoint, $bucket, $prefix.'/'.$appId.'/'.$resticPrefix, $region);
    }

    public function credentials(RepositoryLocation $location): ?StorageCredentials
    {
        if ($location->isLocal) {
            return null;
        }

        $keyId = $this->stringOrNull($this->config->get('restic.repository.key_id'))
            ?? $this->stringOrNull($this->config->get('quraba-backup.storage.b2.key_id'));
        $secret = $this->stringOrNull($this->config->get('restic.repository.application_key'))
            ?? $this->stringOrNull($this->config->get('quraba-backup.storage.b2.application_key'));

        if ($keyId === null || $secret === null) {
            throw new ConfigurationException('B2 credentials are not configured (QURABA_BACKUP_B2_KEY_ID and QURABA_BACKUP_B2_APPLICATION_KEY).');
        }

        return new StorageCredentials($keyId, $secret);
    }

    /**
     * @return array{usable: bool, problems: list<string>, warnings: list<string>}
     */
    public function passwordFileStatus(): array
    {
        return $this->passwordFiles->inspect($this->restic->passwordFile);
    }

    public function resolve(): RepositoryContext
    {
        $location = $this->location();
        $credentials = $this->credentials($location);
        $status = $this->passwordFileStatus();

        if (! $status['usable'] || $this->restic->passwordFile === null) {
            throw new ConfigurationException('The Restic password file is not usable: '.implode(' ', $status['problems']));
        }

        return new RepositoryContext($location, $this->restic->passwordFile, $credentials);
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
