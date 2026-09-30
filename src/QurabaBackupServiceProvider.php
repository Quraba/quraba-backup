<?php

declare(strict_types=1);

namespace Quraba\Backup;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Log\LogManager;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Quraba\Backup\Console\DoctorCommand;
use Quraba\Backup\Console\IdentityCommand;
use Quraba\Backup\Console\InstallResticCommand;
use Quraba\Backup\Console\ResticHealthCommand;
use Quraba\Backup\Console\ResticInitCommand;
use Quraba\Backup\Console\WorkspaceCleanupCommand;
use Quraba\Backup\Console\WorkspaceListCommand;
use Quraba\Backup\Contracts\LockManager;
use Quraba\Backup\Contracts\ReleaseDownloader;
use Quraba\Backup\Coordination\FileLockManager;
use Quraba\Backup\Coordination\OperationCoordinator;
use Quraba\Backup\Database\DatabaseToolLocator;
use Quraba\Backup\Exceptions\ConfigurationException;
use Quraba\Backup\Health\Doctor\Checks\DatabaseChecks;
use Quraba\Backup\Health\Doctor\Checks\IdentityChecks;
use Quraba\Backup\Health\Doctor\Checks\LockingChecks;
use Quraba\Backup\Health\Doctor\Checks\ObjectStorageChecks;
use Quraba\Backup\Health\Doctor\Checks\ResticChecks;
use Quraba\Backup\Health\Doctor\Checks\RuntimeChecks;
use Quraba\Backup\Health\Doctor\Checks\SafetyChecks;
use Quraba\Backup\Health\Doctor\Checks\StorageChecks;
use Quraba\Backup\Health\Doctor\DoctorService;
use Quraba\Backup\Health\ResticHealthService;
use Quraba\Backup\Identity\IdentityResolver;
use Quraba\Backup\Restic\Installer\Bzip2Decompressor;
use Quraba\Backup\Restic\Installer\HttpReleaseDownloader;
use Quraba\Backup\Restic\Installer\ResticInstaller;
use Quraba\Backup\Restic\PasswordFileInspector;
use Quraba\Backup\Restic\PlatformDetector;
use Quraba\Backup\Restic\RepositoryContextResolver;
use Quraba\Backup\Restic\ResticBinaryResolver;
use Quraba\Backup\Restic\ResticConfig;
use Quraba\Backup\Restic\ResticRedactor;
use Quraba\Backup\Restic\ResticRepository;
use Quraba\Backup\Restic\ResticRunner;
use Quraba\Backup\Security\KnownSecrets;
use Quraba\Backup\Security\SecretRedactor;
use Quraba\Backup\Support\ConfigValue;
use Quraba\Backup\Support\PackagePaths;
use Quraba\Backup\Support\Process\ProcessFactory;
use Quraba\Backup\Support\Process\SymfonyProcessFactory;
use Quraba\Backup\Workspace\WorkspaceManager;

/**
 * Registers the package. Configuration is validated lazily when a service is
 * first resolved, so a misconfigured package can never break the host
 * application's boot; it fails closed when it is actually used.
 */
final class QurabaBackupServiceProvider extends ServiceProvider
{
    public const string LOGGER = 'quraba-backup.logger';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/quraba-backup.php', 'quraba-backup');
        $this->mergeConfigFrom(__DIR__.'/../config/restic.php', 'restic');

        $this->app->singleton(self::LOGGER, function (Application $app): LoggerInterface {
            $channel = $app->make(Repository::class)->get('quraba-backup.logging.channel');

            /** @var LogManager $logs */
            $logs = $app->make('log');

            return is_string($channel) && $channel !== '' ? $logs->channel($channel) : $logs->channel();
        });

        $this->app->singleton(SecretRedactor::class, function (Application $app): SecretRedactor {
            $secrets = new KnownSecrets($app->make(Repository::class));

            return new SecretRedactor(static fn (): array => $secrets->all());
        });

        $this->app->singleton(ResticRedactor::class);

        $this->app->singleton(PackagePaths::class, fn (Application $app): PackagePaths => PackagePaths::fromConfig($app->make(Repository::class)));

        $this->app->singleton(ProcessFactory::class, SymfonyProcessFactory::class);

        $this->app->singleton(IdentityResolver::class);

        $this->app->singleton(LockManager::class, FileLockManager::class);
        $this->app->singleton(FileLockManager::class);
        $this->app->singleton(OperationCoordinator::class);

        $this->app->singleton(WorkspaceManager::class, fn (Application $app): WorkspaceManager => new WorkspaceManager(
            $app->make(PackagePaths::class),
            self::logger($app),
        ));

        $this->registerRestic();
        $this->registerHealth();
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/quraba-backup.php' => config_path('quraba-backup.php'),
            __DIR__.'/../config/restic.php' => config_path('restic.php'),
        ], 'quraba-backup-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'quraba-backup-migrations');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->commands([
            IdentityCommand::class,
            WorkspaceListCommand::class,
            WorkspaceCleanupCommand::class,
            InstallResticCommand::class,
            ResticInitCommand::class,
            ResticHealthCommand::class,
            DoctorCommand::class,
        ]);
    }

    private function registerRestic(): void
    {
        $this->app->singleton(ResticConfig::class, fn (Application $app): ResticConfig => ResticConfig::fromConfig($app->make(Repository::class)));

        $this->app->singleton(PlatformDetector::class, fn (): PlatformDetector => new PlatformDetector);

        $this->app->singleton(ResticBinaryResolver::class, fn (Application $app): ResticBinaryResolver => new ResticBinaryResolver($app->make(ResticConfig::class)));

        $this->app->singleton(PasswordFileInspector::class, fn (Application $app): PasswordFileInspector => new PasswordFileInspector([$app->publicPath()]));

        $this->app->singleton(RepositoryContextResolver::class);

        $this->app->singleton(ResticRunner::class, fn (Application $app): ResticRunner => new ResticRunner(
            $app->make(ResticConfig::class),
            $app->make(ResticBinaryResolver::class),
            $app->make(RepositoryContextResolver::class),
            $app->make(ProcessFactory::class),
            $app->make(ResticRedactor::class),
            $app->make(PackagePaths::class),
            self::logger($app),
        ));

        $this->app->singleton(ResticRepository::class);

        $this->app->singleton(ReleaseDownloader::class, function (Application $app): HttpReleaseDownloader {
            $config = $app->make(Repository::class);

            return new HttpReleaseDownloader(
                $app->make(HttpFactory::class),
                ConfigValue::positiveInt($config->get('quraba-backup.timeouts.http_connect'), 'quraba-backup.timeouts.http_connect'),
                ConfigValue::positiveInt($config->get('quraba-backup.timeouts.download'), 'quraba-backup.timeouts.download'),
            );
        });

        $this->app->singleton(Bzip2Decompressor::class, fn (Application $app): Bzip2Decompressor => new Bzip2Decompressor($app->make(ProcessFactory::class)));

        $this->app->singleton(ResticInstaller::class, fn (Application $app): ResticInstaller => new ResticInstaller(
            $app->make(ResticConfig::class),
            $app->make(ResticRunner::class),
            $app->make(PlatformDetector::class),
            $app->make(ReleaseDownloader::class),
            $app->make(Bzip2Decompressor::class),
            $app->make(LockManager::class),
            self::logger($app),
        ));
    }

    private function registerHealth(): void
    {
        $this->app->singleton(DatabaseToolLocator::class, fn (Application $app): DatabaseToolLocator => new DatabaseToolLocator(
            $app->make(Repository::class),
            $app->make(ProcessFactory::class),
        ));

        $this->app->singleton(ResticHealthService::class);

        $this->app->bind(StorageChecks::class, fn (Application $app): StorageChecks => new StorageChecks(
            $app->make(PackagePaths::class),
            $app->make(WorkspaceManager::class),
            $app->publicPath(),
        ));

        $this->app->bind(SafetyChecks::class, fn (Application $app): SafetyChecks => new SafetyChecks(
            $app->make(Repository::class),
            $app->make(PackagePaths::class),
            $app->basePath(),
            $app->publicPath(),
            $app->storagePath(),
        ));

        $this->app->bind(DoctorService::class, fn (Application $app): DoctorService => new DoctorService(
            (function () use ($app): \Generator {
                // Each group is resolved lazily so one misconfigured subsystem
                // produces a FAIL entry instead of aborting the whole doctor.
                foreach ([
                    RuntimeChecks::class,
                    IdentityChecks::class,
                    StorageChecks::class,
                    LockingChecks::class,
                    DatabaseChecks::class,
                    ObjectStorageChecks::class,
                    ResticChecks::class,
                    SafetyChecks::class,
                ] as $check) {
                    yield new Health\Doctor\LazyDoctorCheck($app, $check);
                }
            })(),
            $app->make(SecretRedactor::class),
        ));
    }

    private static function logger(Application $app): LoggerInterface
    {
        $logger = $app->make(self::LOGGER);

        if (! $logger instanceof LoggerInterface) {
            throw new ConfigurationException('The Quraba Backup log channel could not be resolved.');
        }

        return $logger;
    }
}
