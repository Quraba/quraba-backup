<?php

declare(strict_types=1);

namespace Quraba\Backup\Health\Doctor\Checks;

use Illuminate\Contracts\Config\Repository;
use Quraba\Backup\Exceptions\ConfigurationException;
use Quraba\Backup\Health\CheckResult;
use Quraba\Backup\Health\Doctor\DoctorCheck;
use Quraba\Backup\Identity\IdentityResolver;
use Quraba\Backup\Identity\IdentitySource;

final readonly class IdentityChecks implements DoctorCheck
{
    public function __construct(
        private IdentityResolver $identity,
        private Repository $config,
    ) {}

    public function name(): string
    {
        return 'identity';
    }

    public function run(): array
    {
        $results = [];

        try {
            $identity = $this->identity->current();
        } catch (ConfigurationException $exception) {
            return [
                CheckResult::fail('identity.app_id', 'Application ID', $exception->getMessage()),
                CheckResult::skip('identity.environment', 'Environment identity', 'Requires a valid application ID.'),
                $this->enabled(),
            ];
        }

        $results[] = CheckResult::pass('identity.app_id', 'Application ID', $identity->appId);

        $results[] = $identity->environmentSource === IdentitySource::PackageConfiguration
            ? CheckResult::pass('identity.environment', 'Environment identity', $identity->environment)
            : CheckResult::warn('identity.environment', 'Environment identity', sprintf('"%s" is taken from APP_ENV. Set QURABA_BACKUP_ENVIRONMENT explicitly so the backup family cannot change when APP_ENV does.', $identity->environment));

        $results[] = $this->enabled();

        return $results;
    }

    private function enabled(): CheckResult
    {
        return (bool) $this->config->get('quraba-backup.enabled', true)
            ? CheckResult::pass('identity.enabled', 'Backups enabled', 'QURABA_BACKUP_ENABLED is on.')
            : CheckResult::warn('identity.enabled', 'Backups enabled', 'QURABA_BACKUP_ENABLED is off; automated backup activity is disabled.');
    }
}
