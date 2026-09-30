<?php

declare(strict_types=1);

namespace Quraba\Backup\Identity;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Str;
use Quraba\Backup\Domain\Identifiers;
use Quraba\Backup\Exceptions\ConfigurationException;

/**
 * Loads and validates the application identity from configuration.
 *
 * The resolver never generates, rotates or persists an identity on its own:
 * a missing or malformed app ID is a setup error with explicit guidance.
 */
final readonly class IdentityResolver
{
    public const string SETUP_GUIDANCE = 'Run "php artisan quraba:backup:identity --generate" once, add the printed QURABA_BACKUP_APP_ID line to .env, keep a copy with your recovery secrets, and never change it afterwards.';

    public function __construct(
        private Repository $config,
        private Application $app,
    ) {}

    public function current(): ApplicationIdentity
    {
        $appId = $this->config->get('quraba-backup.app_id');

        if ($appId === null || (is_string($appId) && trim($appId) === '')) {
            throw new ConfigurationException('QURABA_BACKUP_APP_ID is not configured. '.self::SETUP_GUIDANCE);
        }

        if (! is_string($appId) || ! Identifiers::isUuid($appId)) {
            throw new ConfigurationException('QURABA_BACKUP_APP_ID is not a canonical lowercase UUID. Fix the configured value; do not replace an identity that is already used by existing backups.');
        }

        [$environment, $source] = $this->environment();

        return new ApplicationIdentity($appId, $environment, $source);
    }

    public function isConfigured(): bool
    {
        $appId = $this->config->get('quraba-backup.app_id');

        return is_string($appId) && trim($appId) !== '';
    }

    /**
     * A fresh identity for explicit first-time setup. Callers must refuse to
     * use it when an identity is already configured.
     */
    public static function generate(): string
    {
        return (string) Str::uuid();
    }

    /**
     * @return array{0: string, 1: IdentitySource}
     */
    private function environment(): array
    {
        $configured = $this->config->get('quraba-backup.environment');

        if (is_string($configured) && trim($configured) !== '') {
            return [trim($configured), IdentitySource::PackageConfiguration];
        }

        return [(string) $this->app->environment(), IdentitySource::ApplicationEnvironment];
    }
}
