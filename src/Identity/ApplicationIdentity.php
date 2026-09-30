<?php

declare(strict_types=1);

namespace Quraba\Backup\Identity;

use Quraba\Backup\Domain\Identifiers;
use Quraba\Backup\Exceptions\ConfigurationException;

/**
 * The stable identity of this application's backup family.
 *
 * `appId` comes exclusively from QURABA_BACKUP_APP_ID and is never derived
 * from mutable labels (APP_NAME, APP_URL, hostname, domain, directory).
 * `environment` separates production from staging backups and participates
 * in Restic tags and remote manifests.
 */
final readonly class ApplicationIdentity
{
    private const string ENVIRONMENT_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,31}$/';

    public function __construct(
        public string $appId,
        public string $environment,
        public IdentitySource $environmentSource,
    ) {
        if (! Identifiers::isUuid($appId)) {
            throw new ConfigurationException('QURABA_BACKUP_APP_ID must be a canonical lowercase UUID.');
        }

        if (! self::isValidEnvironment($environment)) {
            throw new ConfigurationException(sprintf(
                'The backup environment identity [%s] is invalid; use 1-32 lowercase letters, digits, "-" or "_" (e.g. "production").',
                mb_substr($environment, 0, 40),
            ));
        }
    }

    public static function isValidEnvironment(string $environment): bool
    {
        return preg_match(self::ENVIRONMENT_PATTERN, $environment) === 1;
    }

    /**
     * @return array{app_id: string, environment: string, environment_source: string}
     */
    public function toArray(): array
    {
        return [
            'app_id' => $this->appId,
            'environment' => $this->environment,
            'environment_source' => $this->environmentSource->value,
        ];
    }
}
