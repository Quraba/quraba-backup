<?php

declare(strict_types=1);

namespace Quraba\Backup\Console;

use Illuminate\Contracts\Config\Repository;
use Quraba\Backup\Exceptions\ConfigurationException;
use Quraba\Backup\Identity\IdentityResolver;
use Quraba\Backup\Support\ConfigValue;
use Quraba\Backup\Support\PackageVersion;

final class IdentityCommand extends PackageCommand
{
    protected $signature = 'backup:identity
        {--generate : Print a new application ID for first-time setup (refused when one is configured)}
        {--json : Output machine-readable JSON}';

    protected $description = 'Show the stable Quraba Backup application identity (no secrets).';

    public function handle(IdentityResolver $resolver): int
    {
        if ((bool) $this->option('generate')) {
            return $this->generate($resolver);
        }

        try {
            $identity = $resolver->current();
        } catch (ConfigurationException $exception) {
            return $this->failWith($exception);
        }

        $payload = [
            'ok' => true,
            ...$identity->toArray(),
            'package_version' => PackageVersion::current(),
            'backup_prefix' => $this->laravel->make(Repository::class)->get('quraba-backup.storage.b2.prefix'),
            'laravel_environment' => $this->laravel->environment(),
        ];

        if ($this->wantsJson()) {
            $this->writeJson($payload);

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Application ID', $identity->appId);
        $this->components->twoColumnDetail('Environment', sprintf('%s <fg=gray>(%s)</>', $identity->environment, $identity->environmentSource->value));
        $this->components->twoColumnDetail('Package version', PackageVersion::current());
        $this->components->twoColumnDetail('Remote prefix', ConfigValue::stringOrNull($payload['backup_prefix']) ?? '(not set)');
        $this->newLine();
        $this->line('  Keep the application ID with your off-server recovery secrets. It must never change.');

        return self::SUCCESS;
    }

    private function generate(IdentityResolver $resolver): int
    {
        if ($resolver->isConfigured()) {
            return $this->failWith(new ConfigurationException('An application ID is already configured. It is never regenerated or replaced; existing backups belong to it.', 'identity.already_configured'));
        }

        $appId = IdentityResolver::generate();

        if ($this->wantsJson()) {
            $this->writeJson(['ok' => true, 'generated_app_id' => $appId, 'env_line' => 'QURABA_BACKUP_APP_ID='.$appId]);

            return self::SUCCESS;
        }

        $this->components->info('Generated a new application ID. Nothing was written; add this line to .env once:');
        $this->line('  QURABA_BACKUP_APP_ID='.$appId);
        $this->newLine();
        $this->line('  Then store it with your recovery secrets. If the configuration is cached, run "php artisan config:cache" again.');

        return self::SUCCESS;
    }
}
