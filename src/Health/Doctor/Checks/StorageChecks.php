<?php

declare(strict_types=1);

namespace Quraba\Backup\Health\Doctor\Checks;

use Quraba\Backup\Health\CheckResult;
use Quraba\Backup\Health\Doctor\DoctorCheck;
use Quraba\Backup\Support\PackagePaths;
use Quraba\Backup\Support\PathGuard;
use Quraba\Backup\Workspace\WorkspaceManager;
use Symfony\Component\Uid\Ulid;

/**
 * Private storage and workspace checks. The doctor does not create package
 * directories: when they do not exist yet, the nearest existing parent is
 * probed with a temporary file that is removed immediately.
 */
final readonly class StorageChecks implements DoctorCheck
{
    public function __construct(
        private PackagePaths $paths,
        private WorkspaceManager $workspaces,
        private string $publicPath,
    ) {}

    public function name(): string
    {
        return 'storage';
    }

    public function run(): array
    {
        return [
            $this->notPublic(),
            $this->writable('storage.private_root', 'Private storage', $this->paths->root),
            $this->workspace(),
        ];
    }

    private function notPublic(): CheckResult
    {
        $public = PathGuard::real($this->publicPath) ?? $this->publicPath;

        foreach (['root' => $this->paths->root, 'workspaces' => $this->paths->workspaces, 'locks' => $this->paths->locks, 'cache' => $this->paths->cache] as $name => $path) {
            $resolved = PathGuard::real(PathGuard::nearestExistingAncestor($path) ?? $path) ?? $path;

            if (PathGuard::isWithin($path, $public) || (file_exists($path) && PathGuard::isWithin($resolved, $public))) {
                return CheckResult::fail('storage.not_public', 'Private storage location', sprintf('The package %s directory [%s] is inside the public web root. Move QURABA_BACKUP_STORAGE_ROOT outside public/.', $name, $path));
            }
        }

        return CheckResult::pass('storage.not_public', 'Private storage location', 'Package directories are outside the public web root.');
    }

    private function writable(string $id, string $label, string $directory): CheckResult
    {
        if (is_link($directory)) {
            return CheckResult::fail($id, $label, sprintf('[%s] is a symbolic link; package directories must be real directories.', $directory));
        }

        $existing = PathGuard::nearestExistingAncestor($directory);

        if ($existing === null || ! is_dir($existing)) {
            return CheckResult::fail($id, $label, sprintf('No existing parent directory for [%s].', $directory));
        }

        $probe = $existing.'/.quraba-doctor-'.(string) new Ulid;
        $written = @file_put_contents($probe, 'probe') === 5;
        @unlink($probe);

        if (! $written) {
            return CheckResult::fail($id, $label, sprintf('[%s] is not writable by the PHP user.', $existing));
        }

        return $existing === $directory || PathGuard::real($existing) === PathGuard::real($directory)
            ? CheckResult::pass($id, $label, sprintf('[%s] is writable.', $directory))
            : CheckResult::pass($id, $label, sprintf('[%s] does not exist yet; its parent [%s] is writable, so it will be created (0700) on first use.', $directory, $existing));
    }

    private function workspace(): CheckResult
    {
        if (! is_dir($this->paths->workspaces)) {
            $parent = $this->writable('storage.workspace', 'Operation workspaces', $this->paths->workspaces);

            return $parent;
        }

        $workspace = $this->workspaces->create();
        $report = $workspace->cleanup();

        return $report->succeeded()
            ? CheckResult::pass('storage.workspace', 'Operation workspaces', sprintf('A probe workspace was created and removed in [%s].', $this->paths->workspaces))
            : CheckResult::fail('storage.workspace', 'Operation workspaces', 'A probe workspace could not be cleaned up: '.implode(' ', $report->errors));
    }
}
