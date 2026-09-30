<?php

declare(strict_types=1);

namespace Quraba\Backup\Health;

use Carbon\CarbonImmutable;
use Quraba\Backup\Coordination\OperationCoordinator;
use Quraba\Backup\Enums\HealthState;
use Quraba\Backup\Exceptions\ConfigurationException;
use Quraba\Backup\Exceptions\QurabaBackupException;
use Quraba\Backup\Exceptions\ResticUnavailable;
use Quraba\Backup\Exceptions\ResticVersionMismatch;
use Quraba\Backup\Restic\RepositoryContextResolver;
use Quraba\Backup\Restic\RepositoryState;
use Quraba\Backup\Restic\ResticConfig;
use Quraba\Backup\Restic\ResticRepository;
use Quraba\Backup\Restic\ResticRunner;
use Quraba\Backup\Restic\ResticSnapshot;

/**
 * Cheap Restic health: binary, exact version, configuration, password file,
 * repository reachability/initialization/readability and lock state.
 *
 * This is deliberately NOT `restic check`: it reads the repository config,
 * the snapshot list and the lock list without taking a repository lock.
 */
final readonly class ResticHealthService
{
    public function __construct(
        private ResticConfig $config,
        private ResticRunner $runner,
        private ResticRepository $repository,
        private RepositoryContextResolver $contexts,
        private OperationCoordinator $coordinator,
    ) {}

    public function check(): HealthReport
    {
        $checks = [];

        if (! $this->config->enabled) {
            $checks[] = CheckResult::fail('restic.enabled', 'Restic enabled', 'Restic is disabled (QURABA_BACKUP_RESTIC_ENABLED=false).');

            return new HealthReport($checks, CarbonImmutable::now('UTC'));
        }

        $binaryOk = $this->binaryChecks($checks);
        $configOk = $this->configurationChecks($checks);

        if (! $binaryOk || ! $configOk) {
            foreach (['restic.repository_reachable' => 'Repository reachable', 'restic.repository_initialized' => 'Repository initialized', 'restic.repository_readable' => 'Repository readable'] as $id => $label) {
                $checks[] = CheckResult::skip($id, $label, 'Skipped until the binary and configuration checks pass.');
            }

            return new HealthReport($checks, CarbonImmutable::now('UTC'));
        }

        $this->repositoryChecks($checks);
        $this->packageLockCheck($checks);

        return new HealthReport($checks, CarbonImmutable::now('UTC'));
    }

    /**
     * @param  list<CheckResult>  $checks
     */
    private function binaryChecks(array &$checks): bool
    {
        try {
            $binary = $this->runner->binary();
        } catch (ResticVersionMismatch $exception) {
            $checks[] = CheckResult::pass('restic.binary', 'Restic binary', 'A Restic binary exists and is executable.');
            $checks[] = CheckResult::fail('restic.version', 'Restic version', $exception->getMessage(), ['pinned' => $this->config->version]);

            return false;
        } catch (ResticUnavailable $exception) {
            $checks[] = CheckResult::fail('restic.binary', 'Restic binary', $exception->getMessage(), ['managed_path' => $this->config->managedBinary]);
            $checks[] = CheckResult::skip('restic.version', 'Restic version', 'No verifiable binary.');

            return false;
        }

        $checks[] = CheckResult::pass('restic.binary', 'Restic binary', sprintf('%s (%s)', $binary->path, $binary->source->value), ['path' => $binary->path, 'source' => $binary->source->value]);
        $checks[] = CheckResult::pass('restic.version', 'Restic version', sprintf('Restic %s matches the pinned version.', $binary->version->version), $binary->version->toArray());

        return true;
    }

    /**
     * @param  list<CheckResult>  $checks
     */
    private function configurationChecks(array &$checks): bool
    {
        $ok = true;

        try {
            $location = $this->contexts->location();
            $checks[] = CheckResult::pass('restic.repository_config', 'Repository configuration', $location->display(), [
                'location' => $location->display(),
                'local' => $location->isLocal,
                'region' => $location->region,
            ]);

            if ($location->isLocal) {
                $checks[] = CheckResult::warn('restic.offsite', 'Offsite repository', 'The repository is a local path; it is not an offsite backup (intended for tests only).');
            }

            try {
                $credentials = $this->contexts->credentials($location);
                $checks[] = $credentials === null
                    ? CheckResult::skip('restic.credentials', 'Storage credentials', 'Not needed for a local repository.')
                    : CheckResult::pass('restic.credentials', 'Storage credentials', 'B2 key ID and application key are configured (values not shown).');
            } catch (ConfigurationException $exception) {
                $checks[] = CheckResult::fail('restic.credentials', 'Storage credentials', $exception->getMessage());
                $ok = false;
            }
        } catch (ConfigurationException $exception) {
            $checks[] = CheckResult::fail('restic.repository_config', 'Repository configuration', $exception->getMessage());
            $ok = false;
        }

        $password = $this->contexts->passwordFileStatus();

        if (! $password['usable']) {
            $checks[] = CheckResult::fail('restic.password_file', 'Password file', implode(' ', $password['problems']));
            $ok = false;
        } elseif ($password['warnings'] !== []) {
            $checks[] = CheckResult::warn('restic.password_file', 'Password file', implode(' ', $password['warnings']));
        } else {
            $checks[] = CheckResult::pass('restic.password_file', 'Password file', 'Configured, readable, private and non-empty (contents not shown).');
        }

        return $ok;
    }

    /**
     * @param  list<CheckResult>  $checks
     */
    private function repositoryChecks(array &$checks): void
    {
        $inspection = $this->repository->inspect();
        $details = $inspection->toArray();

        [$reachable, $initialized, $readable] = match ($inspection->state) {
            RepositoryState::Ready => [
                CheckResult::pass('restic.repository_reachable', 'Repository reachable', 'The repository backend responded.'),
                CheckResult::pass('restic.repository_initialized', 'Repository initialized', sprintf('Repository %s (format v%d).', $inspection->repositoryId, (int) $inspection->formatVersion), $details),
                CheckResult::pass('restic.repository_readable', 'Repository readable', 'The configured password opens the repository.'),
            ],
            RepositoryState::Uninitialized => [
                CheckResult::pass('restic.repository_reachable', 'Repository reachable', 'The repository backend responded.'),
                CheckResult::fail('restic.repository_initialized', 'Repository initialized', 'No repository exists at the configured location. If this location is correct, run "php artisan backup:restic:init".', $details),
                CheckResult::skip('restic.repository_readable', 'Repository readable', 'No repository to read.'),
            ],
            RepositoryState::WrongPassword => [
                CheckResult::pass('restic.repository_reachable', 'Repository reachable', 'The repository backend responded.'),
                CheckResult::pass('restic.repository_initialized', 'Repository initialized', 'A repository exists at the configured location.'),
                CheckResult::fail('restic.repository_readable', 'Repository readable', $inspection->message, $details),
            ],
            RepositoryState::Locked => [
                CheckResult::pass('restic.repository_reachable', 'Repository reachable', 'The repository backend responded.'),
                CheckResult::pass('restic.repository_initialized', 'Repository initialized', 'A repository exists at the configured location.'),
                CheckResult::warn('restic.repository_readable', 'Repository readable', $inspection->message, $details, HealthState::Unknown),
            ],
            RepositoryState::Unreachable => [
                CheckResult::fail('restic.repository_reachable', 'Repository reachable', $inspection->message, $details, HealthState::Unknown),
                CheckResult::skip('restic.repository_initialized', 'Repository initialized', 'Unknown while the repository is unreachable.'),
                CheckResult::skip('restic.repository_readable', 'Repository readable', 'Unknown while the repository is unreachable.'),
            ],
            RepositoryState::CredentialsRejected, RepositoryState::Error, RepositoryState::NotConfigured => [
                CheckResult::fail('restic.repository_reachable', 'Repository reachable', $inspection->message, $details),
                CheckResult::skip('restic.repository_initialized', 'Repository initialized', 'Unknown.'),
                CheckResult::skip('restic.repository_readable', 'Repository readable', 'Unknown.'),
            ],
        };

        array_push($checks, $reachable, $initialized, $readable);

        if (! $inspection->state->isReady()) {
            return;
        }

        try {
            $snapshots = $this->repository->snapshots();
            $latest = array_reduce($snapshots, static fn (?ResticSnapshot $carry, ResticSnapshot $snapshot): ResticSnapshot => $carry === null || $snapshot->time->greaterThan($carry->time) ? $snapshot : $carry);

            $checks[] = CheckResult::pass('restic.snapshots', 'Snapshot listing', sprintf('%d snapshot(s)%s.', count($snapshots), $latest === null ? '' : ', latest at '.$latest->time->toIso8601ZuluString()), [
                'count' => count($snapshots),
                'latest_id' => $latest?->id,
                'latest_time' => $latest?->time->toIso8601ZuluString(),
            ]);
        } catch (QurabaBackupException $exception) {
            $checks[] = CheckResult::fail('restic.snapshots', 'Snapshot listing', $exception->getMessage(), [], HealthState::Unknown);
        }

        try {
            $locks = $this->repository->lockIds();
            $checks[] = $locks === []
                ? CheckResult::pass('restic.repository_locks', 'Repository locks', 'No Restic lock files present.')
                : CheckResult::warn('restic.repository_locks', 'Repository locks', sprintf('%d Restic lock file(s) present: another process may be running, or a stale lock remains. Locks are never removed automatically.', count($locks)), ['lock_ids' => $locks]);
        } catch (QurabaBackupException $exception) {
            $checks[] = CheckResult::warn('restic.repository_locks', 'Repository locks', 'Lock state could not be determined: '.$exception->getMessage(), [], HealthState::Unknown);
        }
    }

    /**
     * @param  list<CheckResult>  $checks
     */
    private function packageLockCheck(array &$checks): void
    {
        try {
            $busy = $this->coordinator->isWriteOperationRunning();
            $checks[] = $busy
                ? CheckResult::warn('restic.package_lock', 'Package operation lock', 'A write-affecting Quraba Backup operation is currently running.', [], HealthState::Healthy)
                : CheckResult::pass('restic.package_lock', 'Package operation lock', 'No write-affecting operation is running.');
        } catch (QurabaBackupException $exception) {
            $checks[] = CheckResult::fail('restic.package_lock', 'Package operation lock', $exception->getMessage());
        }
    }
}
