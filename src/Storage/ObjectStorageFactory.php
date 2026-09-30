<?php

declare(strict_types=1);

namespace Quraba\Backup\Storage;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Quraba\Backup\Contracts\ObjectStorage;
use Quraba\Backup\Exceptions\ConfigurationException;
use Quraba\Backup\Restic\RepositoryLocation;
use Quraba\Backup\Security\SecretRedactor;
use Quraba\Backup\Support\ConfigValue;

/**
 * Builds the Backblaze B2 (S3 API) object storage from package config.
 *
 * The disk is built on demand with FilesystemManager::build(); it is never
 * registered as a named application disk, so credentials do not leak into
 * the host's filesystem configuration.
 */
final readonly class ObjectStorageFactory
{
    public function __construct(
        private Repository $config,
        private FilesystemManager $filesystems,
        private SecretRedactor $redactor,
    ) {}

    public function isConfigured(): bool
    {
        try {
            $this->settings();

            return true;
        } catch (ConfigurationException) {
            return false;
        }
    }

    public function make(RemoteLayout $layout): ObjectStorage
    {
        $settings = $this->settings();

        $disk = $this->filesystems->build([
            'driver' => 's3',
            'key' => $settings['key_id'],
            'secret' => $settings['application_key'],
            'region' => $settings['region'],
            'bucket' => $settings['bucket'],
            'endpoint' => $settings['endpoint'],
            'use_path_style_endpoint' => false,
            'throw' => true,
            'visibility' => 'private',
            // Backblaze B2 does not accept the flexible checksum headers that
            // current AWS SDKs send by default.
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
        ]);

        if (! $disk instanceof FilesystemAdapter) {
            throw new ConfigurationException('Could not build the B2 object storage.');
        }

        return new FlysystemObjectStorage(
            $disk->getDriver(),
            [$layout->resticRoot()],
            sprintf('%s/%s', $settings['endpoint'], $settings['bucket']),
            $this->redactor,
        );
    }

    /**
     * @return array{endpoint: string, bucket: string, region: string, key_id: string, application_key: string}
     */
    private function settings(): array
    {
        $endpoint = ConfigValue::stringOrNull($this->config->get('quraba-backup.storage.b2.endpoint'));
        $bucket = ConfigValue::stringOrNull($this->config->get('quraba-backup.storage.b2.bucket'));
        $keyId = ConfigValue::stringOrNull($this->config->get('quraba-backup.storage.b2.key_id'));
        $secret = ConfigValue::stringOrNull($this->config->get('quraba-backup.storage.b2.application_key'));

        $missing = array_keys(array_filter([
            'QURABA_BACKUP_B2_ENDPOINT' => $endpoint,
            'QURABA_BACKUP_B2_BUCKET' => $bucket,
            'QURABA_BACKUP_B2_KEY_ID' => $keyId,
            'QURABA_BACKUP_B2_APPLICATION_KEY' => $secret,
        ], static fn (?string $value): bool => $value === null));

        if ($missing !== [] || $endpoint === null || $bucket === null || $keyId === null || $secret === null) {
            throw new ConfigurationException('B2 object storage is not configured; missing: '.implode(', ', $missing).'.');
        }

        $endpoint = RepositoryLocation::endpoint($endpoint);
        $region = ConfigValue::stringOrNull($this->config->get('quraba-backup.storage.b2.region'))
            ?? RepositoryLocation::regionFromEndpoint($endpoint)
            ?? throw new ConfigurationException('Set QURABA_BACKUP_B2_REGION; it cannot be derived from the endpoint.');

        return [
            'endpoint' => $endpoint,
            'bucket' => RepositoryLocation::bucket($bucket),
            'region' => $region,
            'key_id' => $keyId,
            'application_key' => $secret,
        ];
    }
}
