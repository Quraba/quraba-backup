<?php

declare(strict_types=1);

namespace Quraba\Backup\Health\Doctor\Checks;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory;
use Quraba\Backup\Exceptions\ConfigurationException;
use Quraba\Backup\Health\CheckResult;
use Quraba\Backup\Health\Doctor\DoctorCheck;
use Quraba\Backup\Restic\RepositoryLocation;
use Quraba\Backup\Support\ConfigValue;
use Throwable;

/**
 * Backblaze B2 configuration and outbound HTTPS reachability.
 *
 * Reachability is probed without credentials (any HTTP answer over a valid
 * TLS connection proves the endpoint is reachable). Authenticated bucket
 * access is proven by the Restic repository probe in the restic checks.
 */
final readonly class ObjectStorageChecks implements DoctorCheck
{
    public function __construct(
        private Repository $config,
        private Factory $http,
    ) {}

    public function name(): string
    {
        return 'b2';
    }

    public function run(): array
    {
        $values = [
            'QURABA_BACKUP_B2_ENDPOINT' => $this->config->get('quraba-backup.storage.b2.endpoint'),
            'QURABA_BACKUP_B2_BUCKET' => $this->config->get('quraba-backup.storage.b2.bucket'),
            'QURABA_BACKUP_B2_KEY_ID' => $this->config->get('restic.repository.key_id') ?? $this->config->get('quraba-backup.storage.b2.key_id'),
            'QURABA_BACKUP_B2_APPLICATION_KEY' => $this->config->get('restic.repository.application_key') ?? $this->config->get('quraba-backup.storage.b2.application_key'),
        ];

        $missing = array_keys(array_filter($values, static fn (mixed $value): bool => ! is_string($value) || trim($value) === ''));

        $results = [
            $missing === []
                ? CheckResult::pass('b2.config', 'B2 configuration', 'Endpoint, bucket, key ID and application key are configured (secrets not shown).')
                : CheckResult::fail('b2.config', 'B2 configuration', 'Missing: '.implode(', ', $missing).'. The B2 account and bucket-scoped key belong to the customer.'),
        ];

        $endpoint = $values['QURABA_BACKUP_B2_ENDPOINT'];

        if (! is_string($endpoint) || trim($endpoint) === '') {
            $results[] = CheckResult::skip('b2.endpoint', 'B2 endpoint', 'No endpoint configured.');
            $results[] = CheckResult::skip('b2.https', 'Outbound HTTPS to B2', 'No endpoint configured.');
            $results[] = $this->bucketNote();

            return $results;
        }

        try {
            $normalized = RepositoryLocation::endpoint($endpoint);
        } catch (ConfigurationException $exception) {
            $results[] = CheckResult::fail('b2.endpoint', 'B2 endpoint', $exception->getMessage());
            $results[] = CheckResult::skip('b2.https', 'Outbound HTTPS to B2', 'Endpoint is invalid.');
            $results[] = $this->bucketNote();

            return $results;
        }

        $region = RepositoryLocation::regionFromEndpoint($normalized);
        $results[] = $region !== null
            ? CheckResult::pass('b2.endpoint', 'B2 endpoint', sprintf('%s (region %s).', $normalized, $region))
            : CheckResult::warn('b2.endpoint', 'B2 endpoint', sprintf('%s does not look like a Backblaze B2 S3 endpoint (s3.<region>.backblazeb2.com); set QURABA_BACKUP_B2_REGION if needed.', $normalized));

        $results[] = $this->reachability($normalized);
        $results[] = $this->bucketNote();

        return $results;
    }

    private function reachability(string $endpoint): CheckResult
    {
        try {
            $connect = ConfigValue::positiveInt($this->config->get('quraba-backup.timeouts.http_connect', 10), 'quraba-backup.timeouts.http_connect');
            $timeout = ConfigValue::positiveInt($this->config->get('quraba-backup.timeouts.http_probe', 20), 'quraba-backup.timeouts.http_probe');

            $response = $this->http->connectTimeout($connect)->timeout($timeout)->withOptions(['allow_redirects' => false])->get($endpoint);

            return CheckResult::pass('b2.https', 'Outbound HTTPS to B2', sprintf('TLS connection established; endpoint answered HTTP %d.', $response->status()));
        } catch (Throwable $exception) {
            return CheckResult::fail('b2.https', 'Outbound HTTPS to B2', 'The B2 endpoint could not be reached over HTTPS: '.(string) preg_replace('~https?://\S+~', '[url]', $exception->getMessage()));
        }
    }

    private function bucketNote(): CheckResult
    {
        return CheckResult::skip('b2.bucket', 'B2 bucket access', 'Authenticated bucket access is verified by the Restic repository checks; the direct archive-store check arrives with application archives.');
    }
}
