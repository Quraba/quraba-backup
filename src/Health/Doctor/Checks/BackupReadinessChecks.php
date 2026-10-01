<?php

declare(strict_types=1);

namespace Quraba\Backup\Health\Doctor\Checks;

use Composer\InstalledVersions;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Quraba\Backup\Archive\SpatieArchiveEngine;
use Quraba\Backup\Contracts\ArchiveEngine;
use Quraba\Backup\Contracts\QuiescenceProvider;
use Quraba\Backup\Health\CheckResult;
use Quraba\Backup\Health\Doctor\DoctorCheck;
use Quraba\Backup\Media\MediaRoot;
use Quraba\Backup\Media\MediaRootResolver;
use Quraba\Backup\Restic\RepositoryIdentityGuard;
use Quraba\Backup\Restic\ResticRepository;
use Quraba\Backup\Scheduling\BackupScheduler;
use Quraba\Backup\Scheduling\ScheduleDefinition;
use Quraba\Backup\Storage\RemoteStorage;
use Quraba\Backup\Support\ConfigValue;
use Quraba\Backup\Support\PackagePaths;
use Quraba\Backup\Support\PathGuard;
use Throwable;

/**
 * Readiness of the actual backup pipeline. Read-only: it never creates a
 * backup, never writes to B2 and never establishes a repository identity.
 */
final readonly class BackupReadinessChecks implements DoctorCheck
{
    public function __construct(
        private Container $container,
        private Repository $config,
        private PackagePaths $paths,
        private string $envPath,
    ) {}

    public function name(): string
    {
        return 'backup';
    }

    public function run(): array
    {
        return [
            $this->spatie(),
            $this->archivePassword(),
            $this->encryption(),
            $this->envFile(),
            $this->mediaRoots(),
            ...$this->objectStorage(),
            $this->repositoryIdentity(),
            $this->workspaceDisk(),
            $this->consistency(),
            $this->schedule(),
        ];
    }

    private function spatie(): CheckResult
    {
        if (! SpatieArchiveEngine::isInstalled()) {
            return CheckResult::fail('backup.spatie', 'Archive engine', 'spatie/laravel-backup is not installed.');
        }

        $version = InstalledVersions::isInstalled('spatie/laravel-backup') ? InstalledVersions::getPrettyVersion('spatie/laravel-backup') : null;

        return CheckResult::pass('backup.spatie', 'Archive engine', sprintf('spatie/laravel-backup %s (archive engine only).', $version ?? '?'));
    }

    private function archivePassword(): CheckResult
    {
        $password = $this->config->get('quraba-backup.archive.password');

        return is_string($password) && trim($password) !== ''
            ? CheckResult::pass('backup.archive_password', 'Archive password', 'Configured (value not shown). Keep an off-server copy.')
            : CheckResult::fail('backup.archive_password', 'Archive password', 'QURABA_BACKUP_ARCHIVE_PASSWORD is missing or blank. Archives contain .env and are never created unencrypted.');
    }

    private function encryption(): CheckResult
    {
        try {
            $supported = $this->container->make(ArchiveEngine::class)->supportsStrongEncryption();
        } catch (Throwable $exception) {
            return CheckResult::fail('backup.encryption', 'AES-256 ZIP encryption', $exception->getMessage());
        }

        return $supported
            ? CheckResult::pass('backup.encryption', 'AES-256 ZIP encryption', 'Supported by this PHP/libzip build.')
            : CheckResult::fail('backup.encryption', 'AES-256 ZIP encryption', 'Not supported by this PHP/libzip build; archives would be refused.');
    }

    private function envFile(): CheckResult
    {
        if (! (bool) $this->config->get('quraba-backup.archive.include_env', true)) {
            return CheckResult::warn('backup.env_file', '.env file', 'The archive is configured without .env; a clean-host recovery will need .env from elsewhere.');
        }

        return is_file($this->envPath) && is_readable($this->envPath)
            ? CheckResult::pass('backup.env_file', '.env file', 'Readable; it is archived encrypted and never copied in plaintext.')
            : CheckResult::fail('backup.env_file', '.env file', sprintf('[%s] is missing or unreadable.', $this->envPath));
    }

    private function mediaRoots(): CheckResult
    {
        try {
            $roots = $this->container->make(MediaRootResolver::class)->resolve();
        } catch (Throwable $exception) {
            return CheckResult::fail('backup.media_roots', 'Media roots', $exception->getMessage());
        }

        return CheckResult::pass('backup.media_roots', 'Media roots', implode(', ', array_map(static fn (MediaRoot $root): string => $root->name.' → '.$root->path, $roots)));
    }

    /**
     * @return list<CheckResult>
     */
    private function objectStorage(): array
    {
        try {
            $remote = $this->container->make(RemoteStorage::class);
            $layout = $remote->layout();
            $objects = $remote->objects();
        } catch (Throwable $exception) {
            return [
                CheckResult::fail('backup.archive_storage', 'B2 archive storage', $exception->getMessage()),
                CheckResult::skip('backup.manifest_storage', 'B2 manifest storage', 'Object storage is not configured.'),
            ];
        }

        $results = [];

        foreach (['backup.archive_storage' => ['B2 archive storage', $layout->archivesRoot()], 'backup.manifest_storage' => ['B2 manifest storage', $layout->manifestsRoot()]] as $id => [$label, $root]) {
            try {
                // Read-only probe of a key that never exists: proves authenticated access.
                $objects->exists($root.'.quraba-doctor-probe');
                $results[] = CheckResult::pass($id, $label, sprintf('Authenticated access to %s under [%s].', $objects->describe(), $root));
            } catch (Throwable $exception) {
                $results[] = CheckResult::fail($id, $label, $exception->getMessage());
            }
        }

        return $results;
    }

    private function repositoryIdentity(): CheckResult
    {
        try {
            $expected = $this->container->make(RepositoryIdentityGuard::class)->expected();
        } catch (Throwable $exception) {
            return CheckResult::fail('backup.repository_identity', 'Repository identity', $exception->getMessage());
        }

        if ($expected === null) {
            return CheckResult::warn('backup.repository_identity', 'Repository identity', 'Not established yet: quraba:backup:restic:init or the first media backup binds this application to its repository.');
        }

        try {
            $inspection = $this->container->make(ResticRepository::class)->inspect();
        } catch (Throwable $exception) {
            return CheckResult::skip('backup.repository_identity', 'Repository identity', 'Expected '.$expected->repository_id.'; the repository could not be inspected: '.$exception->getMessage());
        }

        if ($inspection->repositoryId === null) {
            return CheckResult::skip('backup.repository_identity', 'Repository identity', sprintf('Expected %s; repository state is %s.', $expected->repository_id, $inspection->state->value));
        }

        return hash_equals($expected->repository_id, $inspection->repositoryId)
            ? CheckResult::pass('backup.repository_identity', 'Repository identity', sprintf('The configured repository is the expected one (%s, established from %s).', $expected->repository_id, $expected->source))
            : CheckResult::fail('backup.repository_identity', 'Repository identity', sprintf('Expected repository %s, but the configured location holds %s. Backups are refused until this is resolved.', $expected->repository_id, $inspection->repositoryId));
    }

    private function workspaceDisk(): CheckResult
    {
        $existing = PathGuard::nearestExistingAncestor($this->paths->workspaces);
        $free = $existing === null ? false : @disk_free_space($existing);

        if ($free === false) {
            return CheckResult::warn('backup.workspace_disk', 'Workspace free disk', 'Free disk space could not be determined (disk_free_space may be disabled).');
        }

        try {
            $minimumMb = ConfigValue::positiveInt($this->config->get('quraba-backup.workspace.min_free_mb', 1024), 'quraba-backup.workspace.min_free_mb');
        } catch (Throwable $exception) {
            return CheckResult::fail('backup.workspace_disk', 'Workspace free disk', $exception->getMessage());
        }

        $freeMb = (int) floor($free / 1048576);

        return $freeMb >= $minimumMb
            ? CheckResult::pass('backup.workspace_disk', 'Workspace free disk', sprintf('%d MB free (minimum %d MB). The database dump and archive are staged here.', $freeMb, $minimumMb))
            : CheckResult::warn('backup.workspace_disk', 'Workspace free disk', sprintf('Only %d MB free (minimum %d MB); the dump and archive may not fit.', $freeMb, $minimumMb));
    }

    private function consistency(): CheckResult
    {
        try {
            $provider = $this->container->make(QuiescenceProvider::class);
        } catch (Throwable $exception) {
            return CheckResult::fail('backup.consistency', 'Recovery Point consistency', $exception->getMessage());
        }

        $claims = $provider->name() === 'laravel_maintenance' && (bool) $this->config->get('quraba-backup.consistency.no_background_writers', false)
            ? 'quiesced'
            : 'best_effort';

        if ($claims !== 'quiesced' && (bool) $this->config->get('quraba-backup.consistency.require_quiesced', false) && ! (bool) $this->config->get('quraba-backup.consistency.allow_downgrade', false)) {
            return CheckResult::fail('backup.consistency', 'Recovery Point consistency', sprintf('Quiesced Recovery Points are required, but the "%s" provider can only prove best_effort; recovery backups will be refused.', $provider->name()));
        }

        return CheckResult::pass('backup.consistency', 'Recovery Point consistency', sprintf('Provider "%s"; Recovery Points are recorded as %s.', $provider->name(), $claims));
    }

    private function schedule(): CheckResult
    {
        try {
            $scheduler = $this->container->make(BackupScheduler::class);
            $definitions = $scheduler->definitions();
            $background = $scheduler->runsInBackground();
        } catch (Throwable $exception) {
            return CheckResult::fail('backup.schedule', 'Backup schedule', $exception->getMessage());
        }

        if ($definitions === []) {
            return CheckResult::warn('backup.schedule', 'Backup schedule', 'No backup schedule is active (package or scheduling disabled). Backups only run when started manually.');
        }

        $details = [
            'tasks' => array_map(static fn (ScheduleDefinition $d): string => $d->task.' '.$d->describe(), $definitions),
            'background' => $background,
            'background_supported' => BackupScheduler::platformSupportsBackground(),
            'runs_in_maintenance_mode' => $scheduler->runsInMaintenanceMode(),
        ];

        $summary = implode('; ', $details['tasks']).'. Requires a cron entry running "php artisan schedule:run" every minute (or every 5 minutes).';

        if (! $background) {
            return CheckResult::warn('backup.schedule', 'Backup schedule', $summary.' Scheduled tasks run in the FOREGROUND of schedule:run'.(BackupScheduler::platformSupportsBackground() ? ' (background disabled by configuration)' : ' (background execution is not supported on this platform)').', so a long backup delays the other scheduled tasks of the host.', $details);
        }

        return CheckResult::pass('backup.schedule', 'Backup schedule', $summary.' Tasks run in the background (Laravel runInBackground); overlap is refused by the package lock.', $details);
    }
}
