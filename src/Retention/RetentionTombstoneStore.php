<?php

declare(strict_types=1);

namespace Quraba\Backup\Retention;

use InvalidArgumentException;
use Quraba\Backup\Exceptions\RetentionFailed;
use Quraba\Backup\Exceptions\StorageUnavailable;
use Quraba\Backup\Identity\ApplicationIdentity;
use Quraba\Backup\Storage\RemoteStorage;

/**
 * Immutable remote retention tombstones at
 * `{prefix}/{app_id}/retention/{run_uuid}.json`.
 *
 * Written only after physical deletion was proven. An identical expiry is
 * adopted (idempotent retries); a different one is a hard collision and is
 * never overwritten.
 */
final readonly class RetentionTombstoneStore
{
    private const int MAX_BYTES = 65536;

    public function __construct(private RemoteStorage $remote) {}

    public function put(RetentionTombstone $tombstone): RetentionTombstone
    {
        $layout = $this->remote->layout();
        $path = $layout->assertManaged($layout->tombstone($tombstone->runUuid));
        $objects = $this->remote->objects();

        try {
            if ($objects->exists($path)) {
                return $this->adopt($path, $tombstone);
            }

            $objects->write($path, $tombstone->encode());
            $readBack = RetentionTombstone::parse($objects->read($path, self::MAX_BYTES));
        } catch (StorageUnavailable $exception) {
            throw new RetentionFailed('The retention tombstone could not be written: '.$exception->getMessage(), 'retention.tombstone_failed');
        } catch (InvalidArgumentException $exception) {
            throw new RetentionFailed('The retention tombstone could not be read back: '.$exception->getMessage(), 'retention.tombstone_failed');
        }

        if (! $readBack->sameExpiry($tombstone)) {
            throw RetentionFailed::tombstoneCollision('the tombstone read back differs from the one written');
        }

        return $readBack;
    }

    /**
     * @throws InvalidArgumentException when the stored tombstone is malformed
     */
    public function find(string $runUuid): ?RetentionTombstone
    {
        $layout = $this->remote->layout();
        $path = $layout->assertManaged($layout->tombstone($runUuid));
        $objects = $this->remote->objects();

        return $objects->exists($path) ? RetentionTombstone::parse($objects->read($path, self::MAX_BYTES)) : null;
    }

    /**
     * Every valid tombstone of this application/environment, keyed by run
     * UUID. Malformed or foreign documents are ignored (and returned
     * separately), so they can never hide a live backup.
     *
     * @return array{tombstones: array<string, RetentionTombstone>, malformed: list<string>}
     */
    public function all(ApplicationIdentity $identity): array
    {
        $layout = $this->remote->layout();
        $objects = $this->remote->objects();
        $tombstones = [];
        $malformed = [];

        foreach ($objects->listFiles($layout->retentionRoot()) as $path) {
            try {
                $tombstone = RetentionTombstone::parse($objects->read($layout->assertManaged($path), self::MAX_BYTES));
            } catch (InvalidArgumentException) {
                $malformed[] = $path;

                continue;
            }

            if ($path !== $layout->tombstone($tombstone->runUuid) || $tombstone->appId !== $identity->appId) {
                $malformed[] = $path;

                continue;
            }

            if ($tombstone->environment === $identity->environment) {
                $tombstones[$tombstone->runUuid] = $tombstone;
            }
        }

        return ['tombstones' => $tombstones, 'malformed' => $malformed];
    }

    private function adopt(string $path, RetentionTombstone $tombstone): RetentionTombstone
    {
        try {
            $existing = RetentionTombstone::parse($this->remote->objects()->read($path, self::MAX_BYTES));
        } catch (InvalidArgumentException $exception) {
            throw RetentionFailed::tombstoneCollision('an unreadable document occupies the tombstone path ('.$exception->getMessage().')');
        }

        if (! $existing->sameExpiry($tombstone)) {
            throw RetentionFailed::tombstoneCollision(sprintf('existing components [%s], new [%s]', implode(', ', $existing->components), implode(', ', $tombstone->components)));
        }

        return $existing;
    }
}
