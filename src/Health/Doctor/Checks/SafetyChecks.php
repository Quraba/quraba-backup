<?php

declare(strict_types=1);

namespace Quraba\Backup\Health\Doctor\Checks;

use Illuminate\Contracts\Config\Repository;
use Quraba\Backup\Health\CheckResult;
use Quraba\Backup\Health\Doctor\DoctorCheck;
use Quraba\Backup\Support\PackagePaths;
use Quraba\Backup\Support\PathGuard;

/**
 * Dangerous configuration combinations that no single check would catch.
 * Secret values are compared in memory only and never reported.
 */
final readonly class SafetyChecks implements DoctorCheck
{
    public function __construct(
        private Repository $config,
        private PackagePaths $paths,
        private string $basePath,
        private string $publicPath,
        private string $storagePath,
    ) {}

    public function name(): string
    {
        return 'safety';
    }

    public function run(): array
    {
        return [
            $this->secretIndependence(),
            $this->archivePassword(),
            $this->passwordFileLocation(),
            $this->managedBinaryLocation(),
            ...$this->mediaRoots(),
            $this->recoverySecretsAcknowledged(),
            CheckResult::skip('safety.scheduler', 'Scheduler', 'Scheduled backups are not implemented in this version; no cron entry is needed yet.'),
        ];
    }

    private function secretIndependence(): CheckResult
    {
        $appKey = $this->string($this->config->get('app.key'));
        $archive = $this->string($this->config->get('quraba-backup.archive.password'));
        $restic = $this->resticPassword();

        $pairs = [
            'the archive password and the Restic password' => [$archive, $restic],
            'APP_KEY and the archive password' => [$appKey, $archive],
            'APP_KEY and the Restic password' => [$appKey, $restic],
        ];

        foreach ($pairs as $description => [$first, $second]) {
            if ($first !== null && $second !== null && hash_equals($first, $second)) {
                return CheckResult::fail('safety.secret_independence', 'Independent secrets', sprintf('%s are identical. The three secrets must be independent; never reuse APP_KEY.', ucfirst($description)));
            }
        }

        return CheckResult::pass('safety.secret_independence', 'Independent secrets', 'APP_KEY, the archive password and the Restic password are not reused (compared in memory only).');
    }

    private function archivePassword(): CheckResult
    {
        return $this->string($this->config->get('quraba-backup.archive.password')) === null
            ? CheckResult::warn('safety.archive_password', 'Archive password', 'QURABA_BACKUP_ARCHIVE_PASSWORD is not set. Application archives (arriving in a later phase) will be refused without it.')
            : CheckResult::pass('safety.archive_password', 'Archive password', 'Configured (value not shown).');
    }

    private function passwordFileLocation(): CheckResult
    {
        $file = $this->string($this->config->get('restic.password_file'));

        if ($file === null) {
            return CheckResult::skip('safety.password_file_location', 'Password file location', 'No password file configured.');
        }

        $real = PathGuard::real($file) ?? $file;

        if (PathGuard::isWithin($real, PathGuard::real($this->publicPath) ?? $this->publicPath)) {
            return CheckResult::fail('safety.password_file_location', 'Password file location', 'The Restic password file is inside the public web root.');
        }

        $base = PathGuard::real($this->basePath) ?? $this->basePath;
        $storage = PathGuard::real($this->storagePath) ?? $this->storagePath;

        if (PathGuard::isWithin($real, $base) && ! PathGuard::isWithin($real, $storage)) {
            return CheckResult::warn('safety.password_file_location', 'Password file location', 'The Restic password file lives in the application directory outside storage/, where it may be committed or deployed. Prefer a path outside the application, e.g. ~/.quraba-backup/restic-password.');
        }

        return CheckResult::pass('safety.password_file_location', 'Password file location', 'Outside the public web root. Keep an off-server copy: the repository cannot be opened without it.');
    }

    private function managedBinaryLocation(): CheckResult
    {
        $binary = $this->string($this->config->get('restic.managed_binary'));

        if ($binary !== null && PathGuard::isWithin($binary, PathGuard::real($this->publicPath) ?? $this->publicPath)) {
            return CheckResult::fail('safety.binary_location', 'Managed binary location', 'The managed Restic binary path is inside the public web root.');
        }

        return CheckResult::pass('safety.binary_location', 'Managed binary location', 'Outside the public web root.');
    }

    /**
     * @return list<CheckResult>
     */
    private function mediaRoots(): array
    {
        $roots = $this->config->get('restic.media.roots', []);

        if (! is_array($roots) || $roots === []) {
            return [CheckResult::skip('safety.media_roots', 'Media roots', 'No media roots configured.')];
        }

        $forbidden = [
            'the application root' => PathGuard::real($this->basePath) ?? $this->basePath,
            'the parent of the application' => dirname(PathGuard::real($this->basePath) ?? $this->basePath),
            'the storage directory' => PathGuard::real($this->storagePath) ?? $this->storagePath,
            'the public directory' => PathGuard::real($this->publicPath) ?? $this->publicPath,
        ];

        $packageRoot = PathGuard::real($this->paths->root) ?? $this->paths->root;
        $problems = [];
        $warnings = [];

        foreach ($roots as $name => $root) {
            $path = is_array($root) ? ($root['path'] ?? null) : $root;

            if (! is_string($path) || ! PathGuard::isAbsolute($path)) {
                $problems[] = sprintf('Media root [%s] must be an absolute path.', (string) $name);

                continue;
            }

            $real = PathGuard::real($path) ?? $path;

            if ($real === '/' || preg_match('~^[A-Za-z]:/?$~', $real) === 1) {
                $problems[] = sprintf('Media root [%s] is the filesystem root.', (string) $name);
            }

            foreach ($forbidden as $description => $forbiddenPath) {
                if (PathGuard::isWithin($forbiddenPath, $real)) {
                    $problems[] = sprintf('Media root [%s] contains %s.', (string) $name, $description);
                }
            }

            if (PathGuard::isWithin($real, $packageRoot) || PathGuard::isWithin($packageRoot, $real)) {
                $problems[] = sprintf('Media root [%s] overlaps the package private storage.', (string) $name);
            }

            if (! is_dir($path)) {
                $warnings[] = sprintf('Media root [%s] (%s) does not exist yet.', (string) $name, $path);
            }
        }

        if ($problems !== []) {
            return [CheckResult::fail('safety.media_roots', 'Media roots', implode(' ', $problems))];
        }

        return [$warnings === []
            ? CheckResult::pass('safety.media_roots', 'Media roots', sprintf('%d media root(s) are narrowly scoped.', count($roots)))
            : CheckResult::warn('safety.media_roots', 'Media roots', implode(' ', $warnings))];
    }

    private function recoverySecretsAcknowledged(): CheckResult
    {
        return (bool) $this->config->get('quraba-backup.recovery_secrets_acknowledged', false)
            ? CheckResult::pass('safety.recovery_secrets', 'Off-server recovery secrets', 'The operator has acknowledged storing the recovery secrets outside this server.')
            : CheckResult::warn('safety.recovery_secrets', 'Off-server recovery secrets', 'Cannot confirm that the B2 credentials, archive password, Restic password and QURABA_BACKUP_APP_ID are stored outside this server. Without them no backup can be restored after server loss. Set QURABA_BACKUP_RECOVERY_SECRETS_ACKNOWLEDGED=true once they are.');
    }

    private function resticPassword(): ?string
    {
        $file = $this->string($this->config->get('restic.password_file'));

        if ($file === null || ! is_file($file) || ! is_readable($file)) {
            return null;
        }

        $contents = @file_get_contents($file, false, null, 0, 65536);

        return is_string($contents) ? $this->string(rtrim($contents, "\r\n")) : null;
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
