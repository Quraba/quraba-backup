<?php

declare(strict_types=1);

namespace Quraba\Backup\Manifest;

use InvalidArgumentException;
use Quraba\Backup\Domain\Identifiers;
use Quraba\Backup\Exceptions\ConfigurationException;
use Quraba\Backup\Identity\ApplicationIdentity;
use Quraba\Backup\Storage\RemoteStorage;

/**
 * Read-only access to the immutable remote manifests of this application.
 *
 * Works without the local catalog (clean host). Every document is parsed
 * strictly; a manifest of another application/environment, one at the wrong
 * path or one that fails validation never contributes to a decision.
 */
final readonly class RemoteManifestCatalog
{
    public const int MAX_MANIFEST_BYTES = 1048576;

    private const string MANIFEST_PATTERN = '~^\d{4}/\d{2}/\d{2}/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\.json$~';

    public function __construct(private RemoteStorage $remote) {}

    /** @throws ConfigurationException when remote storage is not configured */
    public function scan(ApplicationIdentity $identity): ManifestScan
    {
        $layout = $this->remote->layout();
        $objects = $this->remote->objects();
        $root = $layout->manifestsRoot();
        $manifests = [];
        $malformed = [];
        $otherEnvironments = 0;

        foreach ($objects->listFiles($root) as $path) {
            if (preg_match(self::MANIFEST_PATTERN, substr($path, strlen($root))) !== 1) {
                $malformed[] = ['locator' => $path, 'reason' => 'unexpected object in the manifests prefix'];

                continue;
            }

            try {
                $manifest = RemoteManifest::parse($path, $objects->read($layout->assertManaged($path), self::MAX_MANIFEST_BYTES));
                if ($manifest->archiveLocator !== null && $layout->archiveRunUuid($manifest->archiveLocator) !== $manifest->runUuid) {
                    throw new InvalidArgumentException('archive locator is not the exact managed object for this run');
                }
            } catch (InvalidArgumentException $exception) {
                $malformed[] = ['locator' => $path, 'reason' => $exception->getMessage()];

                continue;
            }

            if ($manifest->appId !== $identity->appId) {
                $malformed[] = ['locator' => $path, 'reason' => 'belongs to another application ID'];

                continue;
            }

            if ($manifest->environment !== $identity->environment) {
                $otherEnvironments++;

                continue;
            }

            $manifests[] = $manifest;
        }

        usort($manifests, static fn (RemoteManifest $a, RemoteManifest $b): int => [$a->createdAt->getTimestamp(), $a->runUuid] <=> [$b->createdAt->getTimestamp(), $b->runUuid]);

        return new ManifestScan($manifests, $malformed, $otherEnvironments);
    }

    /**
     * The manifest of one exact run of this application/environment, or null.
     *
     * @throws InvalidArgumentException when a manifest exists but is invalid or foreign
     */
    public function find(ApplicationIdentity $identity, string $runUuid): ?RemoteManifest
    {
        $runUuid = Identifiers::assertUuid($runUuid, 'The run UUID');
        $layout = $this->remote->layout();
        $objects = $this->remote->objects();
        $root = $layout->manifestsRoot();
        $matches = array_values(array_filter(
            $objects->listFiles($root),
            static fn (string $path): bool => preg_match(self::MANIFEST_PATTERN, substr($path, strlen($root)), $m) === 1 && $m[1] === $runUuid,
        ));

        if ($matches === []) {
            return null;
        }

        if (count($matches) > 1) {
            throw new InvalidArgumentException(sprintf('%d manifests exist for run %s; refusing to choose one.', count($matches), $runUuid));
        }

        $manifest = RemoteManifest::parse($matches[0], $objects->read($layout->assertManaged($matches[0]), self::MAX_MANIFEST_BYTES));
        if ($manifest->archiveLocator !== null && $layout->archiveRunUuid($manifest->archiveLocator) !== $manifest->runUuid) {
            throw new InvalidArgumentException('The archive locator is not the exact managed object for this run.');
        }

        if ($manifest->appId !== $identity->appId || $manifest->environment !== $identity->environment) {
            throw new InvalidArgumentException('The manifest belongs to another application or environment.');
        }

        return $manifest;
    }
}
