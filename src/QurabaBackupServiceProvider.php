<?php

declare(strict_types=1);

namespace Quraba\Backup;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Log\LogManager;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Quraba\Backup\Archive\ArchiveMetadata;
use Quraba\Backup\Archive\ArchiveStore;
use Quraba\Backup\Archive\ArchiveVerifier;
use Quraba\Backup\Archive\Database\MySqlDatabaseDumper;
use Quraba\Backup\Archive\SpatieArchiveEngine;
use Quraba\Backup\Backup\ApplicationArchiveService;
use Quraba\Backup\Backup\BackupManager;
use Quraba\Backup\Backup\BackupReconciler;
use Quraba\Backup\Consistency\LaravelMaintenanceProvider;
use Quraba\Backup\Consistency\NoneQuiescenceProvider;
use Quraba\Backup\Console\BootstrapEnvCommand;
use Quraba\Backup\Console\CatalogRebuildCommand;
use Quraba\Backup\Console\DiscoverCommand;
use Quraba\Backup\Console\DoctorCommand;
use Quraba\Backup\Console\HealthCommand;
use Quraba\Backup\Console\IdentityCommand;
use Quraba\Backup\Console\InstallResticCommand;
use Quraba\Backup\Console\ListCommand;
use Quraba\Backup\Console\PendingCommand;
use Quraba\Backup\Console\PendingOperationCommand;
use Quraba\Backup\Console\ReconcileCommand;
use Quraba\Backup\Console\RecoveryChecklistCommand;
use Quraba\Backup\Console\ResticCheckCommand;
use Quraba\Backup\Console\ResticHealthCommand;
use Quraba\Backup\Console\ResticInitCommand;
use Quraba\Backup\Console\ResticPruneCommand;
use Quraba\Backup\Console\RestoreCommand;
use Quraba\Backup\Console\RestoreReconcileCommand;
use Quraba\Backup\Console\RetentionCommand;
use Quraba\Backup\Console\RunCommand;
use Quraba\Backup\Console\WorkspaceCleanupCommand;
use Quraba\Backup\Console\WorkspaceListCommand;
use Quraba\Backup\Contracts\ArchiveEngine;
use Quraba\Backup\Contracts\DatabaseDumper;
use Quraba\Backup\Contracts\DatabaseReplacement;
use Quraba\Backup\Contracts\LockManager;
use Quraba\Backup\Contracts\ObjectStorage;
use Quraba\Backup\Contracts\PendingOperationActorResolver;
use Quraba\Backup\Contracts\QuiescenceProvider;
use Quraba\Backup\Contracts\ReleaseDownloader;
use Quraba\Backup\Contracts\RestoreStepObserver;
use Quraba\Backup\Coordination\FileLockManager;
use Quraba\Backup\Coordination\OperationCoordinator;
use Quraba\Backup\Database\DatabaseToolLocator;
use Quraba\Backup\Exceptions\ConfigurationException;
use Quraba\Backup\Health\BackupHealthService;
use Quraba\Backup\Health\Doctor\Checks\BackupReadinessChecks;
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
use Quraba\Backup\Maintenance\MaintenanceReconciler;
use Quraba\Backup\Maintenance\ResticMaintenanceService;
use Quraba\Backup\Manifest\ManifestStore;
use Quraba\Backup\Manifest\RemoteManifestCatalog;
use Quraba\Backup\Media\MediaRootResolver;
use Quraba\Backup\Operations\EloquentActorResolver;
use Quraba\Backup\Operations\PendingOperationProcessor;
use Quraba\Backup\Recovery\CatalogRebuilder;
use Quraba\Backup\Recovery\EnvBootstrapper;
use Quraba\Backup\Recovery\RecoveryChecklist;
use Quraba\Backup\Restic\Installer\Bzip2Decompressor;
use Quraba\Backup\Restic\Installer\HttpReleaseDownloader;
use Quraba\Backup\Restic\Installer\ResticInstaller;
use Quraba\Backup\Restic\Installer\VerifiedZipExtractor;
use Quraba\Backup\Restic\PasswordFileInspector;
use Quraba\Backup\Restic\PlatformDetector;
use Quraba\Backup\Restic\RepositoryContextResolver;
use Quraba\Backup\Restic\ResticBinaryResolver;
use Quraba\Backup\Restic\ResticConfig;
use Quraba\Backup\Restic\ResticRedactor;
use Quraba\Backup\Restic\ResticRepository;
use Quraba\Backup\Restic\ResticRunner;
use Quraba\Backup\Restore\ArchiveReconstructor;
use Quraba\Backup\Restore\Journal\RestoreJournalStore;
use Quraba\Backup\Restore\Live\CleanHostProof;
use Quraba\Backup\Restore\Live\ExactDatabaseReplacement;
use Quraba\Backup\Restore\Live\ExactDirectoryReplacement;
use Quraba\Backup\Restore\Live\LiveRestoreService;
use Quraba\Backup\Restore\Live\MediaStaging;
use Quraba\Backup\Restore\Live\RestoreReconciler;
use Quraba\Backup\Restore\Live\SafetyBackupInspector;
use Quraba\Backup\Restore\Live\SafetyBackupService;
use Quraba\Backup\Restore\NullRestoreStepObserver;
use Quraba\Backup\Restore\ResticReconstructor;
use Quraba\Backup\Restore\RestoreDatabaseValidator;
use Quraba\Backup\Restore\RestoreDryRunService;
use Quraba\Backup\Restore\RestorePreflight;
use Quraba\Backup\Restore\RestorePreparation;
use Quraba\Backup\Restore\RestoreSourceResolver;
use Quraba\Backup\Retention\RetentionExecutor;
use Quraba\Backup\Retention\RetentionInventory;
use Quraba\Backup\Retention\RetentionPlanner;
use Quraba\Backup\Retention\RetentionTombstoneStore;
use Quraba\Backup\Scheduling\BackupScheduler;
use Quraba\Backup\Scheduling\ScheduleSettings;
use Quraba\Backup\Security\KnownSecrets;
use Quraba\Backup\Security\SecretRedactor;
use Quraba\Backup\Storage\ObjectStorageFactory;
use Quraba\Backup\Storage\RemoteLayout;
use Quraba\Backup\Storage\RemoteStorage;
use Quraba\Backup\Support\ConfigValue;
use Quraba\Backup\Support\LocalCatalog;
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
        $this->app->bindIf(PendingOperationActorResolver::class, EloquentActorResolver::class);
        $this->app->singleton(PendingOperationProcessor::class);
        $this->app->singleton(ScheduleSettings::class);

        $this->app->singleton(WorkspaceManager::class, fn (Application $app): WorkspaceManager => new WorkspaceManager(
            $app->make(PackagePaths::class),
            self::logger($app),
        ));

        $this->registerRestic();
        $this->registerBackup();
        $this->registerHealth();
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'quraba-backup');
        // Registered whenever the scheduler is resolved (schedule:run,
        // schedule:list); BackupScheduler guards against duplicates.
        // A misconfigured package schedule is logged (and reported by the
        // doctor) but never breaks the host application's own scheduler.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            try {
                $this->app->make(BackupScheduler::class)->register($schedule);
            } catch (\Throwable $exception) {
                self::logger($this->app)->error('Quraba Backup schedules were not registered: '.$this->app->make(SecretRedactor::class)->redact($exception->getMessage()));
            }
        });

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
            DiscoverCommand::class,
            HealthCommand::class,
            RetentionCommand::class,
            ResticCheckCommand::class,
            ResticPruneCommand::class,
            RestoreCommand::class,
            RestoreReconcileCommand::class,
            BootstrapEnvCommand::class,
            CatalogRebuildCommand::class,
            RecoveryChecklistCommand::class,
            RunCommand::class,
            PendingCommand::class,
            PendingOperationCommand::class,
            ListCommand::class,
            ReconcileCommand::class,
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
            $app->make(VerifiedZipExtractor::class),
            $app->make(LockManager::class),
            self::logger($app),
        ));
    }

    private function registerBackup(): void
    {
        $this->app->singleton(ObjectStorageFactory::class, fn (Application $app): ObjectStorageFactory => new ObjectStorageFactory(
            $app->make(Repository::class),
            $app->make(FilesystemManager::class),
            $app->make(SecretRedactor::class),
        ));

        $this->app->singleton(RemoteStorage::class, fn (Application $app): RemoteStorage => new RemoteStorage(
            $app->make(Repository::class),
            $app->make(IdentityResolver::class),
            static fn (RemoteLayout $layout): ObjectStorage => $app->make(ObjectStorageFactory::class)->make($layout),
        ));

        $this->app->singleton(DatabaseDumper::class, MySqlDatabaseDumper::class);
        $this->app->singleton(ArchiveVerifier::class);
        $this->app->singleton(ArchiveMetadata::class);
        $this->app->singleton(ArchiveStore::class);
        $this->app->singleton(RemoteManifestCatalog::class);
        $this->app->singleton(ManifestStore::class);
        $this->app->singleton(RetentionTombstoneStore::class);
        $this->app->singleton(RetentionInventory::class);
        $this->app->singleton(RetentionPlanner::class);
        $this->app->singleton(RetentionExecutor::class);
        $this->app->singleton(MaintenanceReconciler::class);
        $this->app->singleton(ResticMaintenanceService::class);
        $this->app->singleton(BackupHealthService::class);
        $this->app->singleton(RestoreSourceResolver::class);
        $this->app->singleton(ArchiveReconstructor::class);
        $this->app->singleton(ResticReconstructor::class);
        $this->app->singleton(RestorePreflight::class);
        $this->app->singleton(RestoreDatabaseValidator::class);
        $this->app->singleton(RestorePreparation::class);
        $this->app->singleton(RestoreDryRunService::class);
        $this->app->singleton(LocalCatalog::class);

        // Live restore. The observer is replaceable (progress reporting,
        // failure injection in tests); everything else is fixed.
        $this->app->singletonIf(RestoreStepObserver::class, NullRestoreStepObserver::class);
        $this->app->singleton(RestoreJournalStore::class, fn (Application $app): RestoreJournalStore => new RestoreJournalStore($app->make(PackagePaths::class)));
        $this->app->singleton(DatabaseReplacement::class, ExactDatabaseReplacement::class);
        $this->app->singleton(MediaStaging::class, fn (Application $app): MediaStaging => new MediaStaging($app->publicPath()));
        $this->app->singleton(ExactDirectoryReplacement::class);
        $this->app->singleton(CleanHostProof::class);
        $this->app->singleton(SafetyBackupService::class);
        $this->app->singleton(SafetyBackupInspector::class);
        $this->app->singleton(LiveRestoreService::class);
        $this->app->singleton(RestoreReconciler::class);

        // Clean-host recovery.
        $this->app->singleton(CatalogRebuilder::class);
        $this->app->singleton(RecoveryChecklist::class);
        $this->app->singleton(EnvBootstrapper::class, fn (Application $app): EnvBootstrapper => new EnvBootstrapper(
            $app->make(Repository::class),
            $app->make(OperationCoordinator::class),
            $app->make(IdentityResolver::class),
            $app->make(RemoteManifestCatalog::class),
            $app->make(RetentionTombstoneStore::class),
            $app->make(ArchiveStore::class),
            $app->make(ArchiveVerifier::class),
            $app->make(WorkspaceManager::class),
            $app->publicPath(),
        ));

        $this->app->singleton(ArchiveEngine::class, fn (Application $app): SpatieArchiveEngine => new SpatieArchiveEngine(
            $app,
            $app->make(DatabaseDumper::class),
            $app->make(ArchiveMetadata::class),
            $app->make(SecretRedactor::class),
            (bool) $app->make(Repository::class)->get('quraba-backup.archive.include_env', true),
        ));

        $this->app->singleton(ApplicationArchiveService::class, fn (Application $app): ApplicationArchiveService => new ApplicationArchiveService(
            $app->make(ArchiveEngine::class),
            $app->make(ArchiveVerifier::class),
            $app->make(ArchiveStore::class),
            $app->make(DatabaseDumper::class),
            $app->make(Repository::class),
            $app->make(SecretRedactor::class),
            $app->environmentFilePath(),
        ));

        $this->app->singleton(MediaRootResolver::class, fn (Application $app): MediaRootResolver => new MediaRootResolver(
            $app->make(Repository::class),
            $app->make(PackagePaths::class),
            $app->basePath(),
            $app->publicPath(),
            $app->storagePath(),
        ));

        $this->app->singleton(QuiescenceProvider::class, function (Application $app): QuiescenceProvider {
            $config = $app->make(Repository::class);

            return match ($config->get('quraba-backup.consistency.provider', 'none')) {
                'none', null, '' => new NoneQuiescenceProvider,
                'laravel_maintenance' => new LaravelMaintenanceProvider(
                    $app,
                    $app->make(Kernel::class),
                    (bool) $config->get('quraba-backup.consistency.no_background_writers', false),
                    ConfigValue::positiveInt($config->get('quraba-backup.consistency.maintenance_retry_after', 60), 'quraba-backup.consistency.maintenance_retry_after'),
                ),
                default => throw new ConfigurationException('quraba-backup.consistency.provider must be "none" or "laravel_maintenance".'),
            };
        });

        $this->app->singleton(BackupScheduler::class);

        $this->app->when([BackupManager::class, BackupReconciler::class, BackupScheduler::class, RetentionExecutor::class, LiveRestoreService::class, RestoreReconciler::class, CatalogRebuilder::class])
            ->needs(LoggerInterface::class)
            ->give(fn (Application $app): LoggerInterface => self::logger($app));
    }

    private function registerHealth(): void
    {
        $this->app->bind(BackupReadinessChecks::class, fn (Application $app): BackupReadinessChecks => new BackupReadinessChecks(
            $app,
            $app->make(Repository::class),
            $app->make(PackagePaths::class),
            $app->environmentFilePath(),
        ));

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
                    BackupReadinessChecks::class,
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
        return $app->make(self::LOGGER);
    }
}
