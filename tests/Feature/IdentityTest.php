<?php

declare(strict_types=1);

namespace Quraba\Backup\Tests\Feature;

use Quraba\Backup\Domain\Identifiers;
use Quraba\Backup\Exceptions\ConfigurationException;
use Quraba\Backup\Identity\IdentityResolver;
use Quraba\Backup\Identity\IdentitySource;
use Quraba\Backup\Tests\TestCase;

final class IdentityTest extends TestCase
{
    private function resolver(): IdentityResolver
    {
        $this->app->forgetInstance(IdentityResolver::class);

        return $this->app->make(IdentityResolver::class);
    }

    public function test_valid_identity(): void
    {
        $identity = $this->resolver()->current();

        self::assertSame(self::APP_ID, $identity->appId);
        self::assertSame('testing', $identity->environment);
        self::assertSame(IdentitySource::PackageConfiguration, $identity->environmentSource);
    }

    public function test_identity_is_never_derived_from_mutable_labels(): void
    {
        $this->config()->set('quraba-backup.app_id', null);
        $this->config()->set('app.name', 'Some Shop');
        $this->config()->set('app.url', 'https://shop.example.com');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('backup:identity --generate');
        $this->resolver()->current();
    }

    public function test_malformed_identities_are_refused(): void
    {
        foreach (['not-a-uuid', '6F614A0B-C447-4E36-9758-347858CBB46B', ' ', '00000000-0000-0000-0000-000000000000'] as $value) {
            $this->config()->set('quraba-backup.app_id', $value);

            try {
                $this->resolver()->current();
                self::fail(sprintf('[%s] must be refused.', $value));
            } catch (ConfigurationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_environment_falls_back_to_app_env_and_is_validated(): void
    {
        $this->config()->set('quraba-backup.environment', null);
        $identity = $this->resolver()->current();

        self::assertSame($this->app->environment(), $identity->environment);
        self::assertSame(IdentitySource::ApplicationEnvironment, $identity->environmentSource);

        $this->config()->set('quraba-backup.environment', 'Prod Env!');
        $this->expectException(ConfigurationException::class);
        $this->resolver()->current();
    }

    public function test_identity_command_shows_safe_information_only(): void
    {
        $this->artisan('backup:identity')
            ->expectsOutputToContain(self::APP_ID)
            ->expectsOutputToContain('testing')
            ->assertSuccessful();

        $this->artisan('backup:identity', ['--json' => true])->assertSuccessful();
    }

    public function test_identity_command_guides_setup_when_missing(): void
    {
        $this->config()->set('quraba-backup.app_id', null);

        $this->artisan('backup:identity')
            ->expectsOutputToContain('--generate')
            ->assertFailed();
    }

    public function test_generate_refuses_to_replace_a_configured_identity(): void
    {
        $this->artisan('backup:identity', ['--generate' => true])
            ->expectsOutputToContain('never regenerated')
            ->assertFailed();

        self::assertSame(self::APP_ID, $this->config()->get('quraba-backup.app_id'));
    }

    public function test_generate_prints_a_fresh_uuid_when_none_is_configured(): void
    {
        $this->config()->set('quraba-backup.app_id', null);

        $this->artisan('backup:identity', ['--generate' => true])
            ->expectsOutputToContain('QURABA_BACKUP_APP_ID=')
            ->assertSuccessful();

        self::assertTrue(Identifiers::isUuid(IdentityResolver::generate()));
        self::assertNull($this->config()->get('quraba-backup.app_id'), 'Generation never writes configuration.');
    }
}
