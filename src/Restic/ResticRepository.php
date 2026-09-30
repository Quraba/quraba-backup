<?php

declare(strict_types=1);

namespace Quraba\Backup\Restic;

use Quraba\Backup\Coordination\LockName;
use Quraba\Backup\Coordination\OperationCoordinator;
use Quraba\Backup\Domain\Identifiers;
use Quraba\Backup\Exceptions\ArtifactVerificationFailed;
use Quraba\Backup\Exceptions\ConfigurationException;
use Quraba\Backup\Exceptions\ProcessExecutionFailed;
use Quraba\Backup\Exceptions\ResticAuthenticationFailed;
use Quraba\Backup\Exceptions\ResticCommandFailed;
use Quraba\Backup\Exceptions\ResticRepositoryLocked;
use Quraba\Backup\Exceptions\ResticRepositoryUnavailable;
use Quraba\Backup\Exceptions\ResticRepositoryUninitialized;

/**
 * The Backblaze B2 (S3 API) Restic repository of this application.
 *
 * Initialization is only ever explicit ({@see self::initialize()}), happens
 * under the global operation lock, requires Restic's own "repository does
 * not exist" signal (exit code 10), and is immediately proven by reading the
 * new repository back. A network error, typo, wrong password or rejected
 * credential is never treated as "missing, create a new one".
 */
final readonly class ResticRepository
{
    public function __construct(
        private ResticRunner $runner,
        private RepositoryContextResolver $contexts,
        private OperationCoordinator $coordinator,
    ) {}

    public function location(): RepositoryLocation
    {
        return $this->contexts->location();
    }

    public function isConfigured(): bool
    {
        return $this->contexts->isConfigured();
    }

    /**
     * Cheap, read-only (lock-free) inspection of the repository state.
     *
     * Binary problems (missing binary, version mismatch) are not repository
     * states and propagate as exceptions.
     */
    public function inspect(): RepositoryInspection
    {
        $location = null;

        try {
            $location = $this->contexts->location()->display();
            $this->contexts->resolve();
        } catch (ConfigurationException $exception) {
            return new RepositoryInspection(RepositoryState::NotConfigured, $location, $exception->getMessage(), failureCode: $exception->failureCode());
        }

        try {
            $config = $this->runner->catConfig()->throwIfFailed()->json();
        } catch (ResticRepositoryUninitialized $exception) {
            return new RepositoryInspection(RepositoryState::Uninitialized, $location, $exception->getMessage(), failureCode: $exception->failureCode());
        } catch (ResticAuthenticationFailed $exception) {
            $state = $exception->failureCode() === 'restic.wrong_password' ? RepositoryState::WrongPassword : RepositoryState::CredentialsRejected;

            return new RepositoryInspection($state, $location, $exception->getMessage(), failureCode: $exception->failureCode());
        } catch (ResticRepositoryLocked $exception) {
            return new RepositoryInspection(RepositoryState::Locked, $location, $exception->getMessage(), failureCode: $exception->failureCode());
        } catch (ResticRepositoryUnavailable|ProcessExecutionFailed $exception) {
            return new RepositoryInspection(RepositoryState::Unreachable, $location, $exception->getMessage(), failureCode: $exception->failureCode());
        } catch (ResticCommandFailed $exception) {
            return new RepositoryInspection(RepositoryState::Error, $location, $exception->getMessage(), failureCode: $exception->failureCode());
        }

        $id = $config['id'] ?? null;
        $version = $config['version'] ?? null;

        if (! is_string($id) || ! Identifiers::isFullSnapshotId($id) || ! is_int($version)) {
            return new RepositoryInspection(RepositoryState::Error, $location, 'The repository config could not be understood; refusing to treat it as healthy.', failureCode: 'restic.output_invalid');
        }

        return new RepositoryInspection(RepositoryState::Ready, $location, 'Repository is initialized and readable with the configured password.', $id, $version);
    }

    /**
     * Explicitly initializes a genuinely absent repository and proves that it
     * is readable afterwards.
     */
    public function initialize(): RepositoryInspection
    {
        $locks = $this->coordinator->beginWriteOperation('restic repository initialization', LockName::Maintenance);

        try {
            $before = $this->inspect();

            if ($before->state === RepositoryState::Ready) {
                throw new ConfigurationException(
                    sprintf('The Restic repository at [%s] is already initialized (id %s). Refusing to initialize it again.', $before->location, $before->repositoryId),
                    'restic.repository_already_initialized',
                );
            }

            if ($before->state !== RepositoryState::Uninitialized) {
                throw new ResticRepositoryUnavailable(
                    sprintf('Refusing to initialize: the repository state is [%s], not a confirmed absence. %s', $before->state->value, $before->message),
                    'restic.init_refused',
                );
            }

            $this->runner->init()->throwIfFailed();

            $after = $this->inspect();

            if (! $after->state->isReady()) {
                throw new ResticRepositoryUnavailable(sprintf('Restic reported a successful init, but the repository could not be read back (%s): %s', $after->state->value, $after->message));
            }

            if ($this->snapshots() !== []) {
                throw new ArtifactVerificationFailed('A freshly initialized repository unexpectedly contains snapshots; refusing to trust it.');
            }

            return $after;
        } finally {
            $locks->release();
        }
    }

    /**
     * @param  list<string>  $tags
     * @return list<ResticSnapshot>
     */
    public function snapshots(array $tags = []): array
    {
        $document = $this->runner->snapshots($tags)->throwIfFailed()->json();

        if (! array_is_list($document)) {
            throw new ResticCommandFailed('`restic snapshots --json` did not return a list.');
        }

        $snapshots = [];

        foreach ($document as $entry) {
            if (! is_array($entry)) {
                throw new ResticCommandFailed('`restic snapshots --json` returned a non-object entry.');
            }

            $snapshots[] = ResticSnapshot::fromJson($entry);
        }

        return $snapshots;
    }

    /**
     * IDs of lock files currently present in the repository. Presence does
     * not necessarily mean "exclusively locked"; the package never removes
     * Restic locks automatically.
     *
     * @return list<string>
     */
    public function lockIds(): array
    {
        $output = $this->runner->listLocks()->throwIfFailed()->stdout;
        $ids = [];

        foreach (preg_split('/\r?\n/', trim($output)) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (! Identifiers::isFullSnapshotId($line)) {
                throw new ResticCommandFailed('`restic list locks` returned an unexpected line.');
            }

            $ids[] = $line;
        }

        return $ids;
    }
}
