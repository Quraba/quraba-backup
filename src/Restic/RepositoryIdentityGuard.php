<?php

declare(strict_types=1);

namespace Quraba\Backup\Restic;

use Quraba\Backup\Exceptions\ConfigurationException;
use Quraba\Backup\Exceptions\QurabaBackupException;
use Quraba\Backup\Exceptions\RepositoryIdentityMismatch;
use Quraba\Backup\Exceptions\ResticAuthenticationFailed;
use Quraba\Backup\Exceptions\ResticCommandFailed;
use Quraba\Backup\Exceptions\ResticRepositoryLocked;
use Quraba\Backup\Exceptions\ResticRepositoryUnavailable;
use Quraba\Backup\Exceptions\ResticRepositoryUninitialized;
use Quraba\Backup\Identity\IdentityResolver;
use Quraba\Backup\Manifest\ManifestStore;
use Quraba\Backup\Models\RepositoryIdentityRecord;

/**
 * Repository identity continuity.
 *
 * The expected repository ID of this application/environment is taken from
 * (in order) the local catalog, the most recent remote manifests, or —
 * only when neither knows one — the first repository that is proven
 * readable. From then on every operation must open exactly that repository:
 * a different repository at the configured location (for example an empty
 * replacement created after the original vanished) is refused, never adopted.
 *
 * The repository ID itself always comes from the existing `restic cat config`
 * inspection; there is no second probing mechanism.
 */
final readonly class RepositoryIdentityGuard
{
    public function __construct(
        private ResticRepository $repository,
        private IdentityResolver $identity,
        private ManifestStore $manifests,
    ) {}

    public function expected(): ?RepositoryIdentityRecord
    {
        $identity = $this->identity->current();

        return RepositoryIdentityRecord::query()
            ->where('app_id', $identity->appId)
            ->where('environment', $identity->environment)
            ->first();
    }

    /**
     * Opens the repository, proves it is readable and that it is the expected
     * one. Returns the verified repository ID.
     */
    public function verifyOpenRepository(): string
    {
        $inspection = $this->repository->inspect();

        if (! $inspection->state->isReady() || $inspection->repositoryId === null) {
            throw self::exceptionFor($inspection);
        }

        return $this->verify($inspection->repositoryId, (string) $inspection->location);
    }

    public function verify(string $currentId, string $location): string
    {
        $expected = $this->expected() ?? $this->learnFromManifests($location);

        if ($expected === null) {
            $identity = $this->identity->current();
            RepositoryIdentityRecord::establish($identity->appId, $identity->environment, $currentId, $location, RepositoryIdentityRecord::SOURCE_FIRST_PROVEN);

            return $currentId;
        }

        if (! hash_equals($expected->repository_id, $currentId)) {
            throw new RepositoryIdentityMismatch(sprintf(
                'This application is bound to Restic repository %s (established from %s on %s), but [%s] now holds repository %s. A replaced repository is never adopted automatically; restore the original repository or its configuration.',
                $expected->repository_id,
                $expected->source,
                $expected->established_at->toIso8601ZuluString(),
                $location,
                $currentId,
            ));
        }

        return $currentId;
    }

    /**
     * Initialization is refused when the application is already bound to a
     * repository: that repository vanished or the location changed, and a new
     * empty repository must not silently take its place.
     */
    public function assertInitializationAllowed(): void
    {
        $expected = $this->expected() ?? $this->learnFromManifests(null);

        if ($expected !== null) {
            throw new RepositoryIdentityMismatch(sprintf(
                'This application is already bound to Restic repository %s (%s), but the configured location reports no repository. Refusing to initialize a replacement; check the endpoint, bucket and prefix.',
                $expected->repository_id,
                $expected->location,
            ));
        }
    }

    /**
     * Explicit initialization: refused when an identity is already bound;
     * the new identity is recorded only after init and read-back succeeded.
     */
    public function initialize(): RepositoryInspection
    {
        $this->assertInitializationAllowed();

        $inspection = $this->repository->initialize();
        $this->establishFromInitialization($inspection);

        return $inspection;
    }

    /**
     * Records the identity of a repository that was just initialized and read back.
     */
    public function establishFromInitialization(RepositoryInspection $inspection): RepositoryIdentityRecord
    {
        if (! $inspection->state->isReady() || $inspection->repositoryId === null) {
            throw new ResticRepositoryUnavailable('The initialized repository was not proven readable; its identity is not recorded.');
        }

        $this->assertInitializationAllowed();
        $identity = $this->identity->current();

        return RepositoryIdentityRecord::establish($identity->appId, $identity->environment, $inspection->repositoryId, (string) $inspection->location, RepositoryIdentityRecord::SOURCE_INITIALIZATION);
    }

    private function learnFromManifests(?string $location): ?RepositoryIdentityRecord
    {
        $identity = $this->identity->current();

        try {
            $repositoryId = $this->manifests->latestRepositoryId($identity);
        } catch (ConfigurationException) {
            // Object storage is not configured, so no manifest can exist yet.
            return null;
        }

        if ($repositoryId === null) {
            return null;
        }

        return RepositoryIdentityRecord::establish($identity->appId, $identity->environment, $repositoryId, $location ?? 'learned from remote manifests', RepositoryIdentityRecord::SOURCE_REMOTE_MANIFEST);
    }

    private static function exceptionFor(RepositoryInspection $inspection): QurabaBackupException
    {
        return match ($inspection->state) {
            RepositoryState::Uninitialized => new ResticRepositoryUninitialized($inspection->message),
            RepositoryState::WrongPassword => ResticAuthenticationFailed::wrongPassword($inspection->message),
            RepositoryState::CredentialsRejected => ResticAuthenticationFailed::storageCredentialsRejected($inspection->message),
            RepositoryState::Locked => new ResticRepositoryLocked($inspection->message),
            RepositoryState::Unreachable => new ResticRepositoryUnavailable($inspection->message),
            RepositoryState::NotConfigured => new ConfigurationException($inspection->message),
            RepositoryState::Error, RepositoryState::Ready => new ResticCommandFailed($inspection->message),
        };
    }
}
