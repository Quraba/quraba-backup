<?php

declare(strict_types=1);

namespace Quraba\Backup\Retention;

use InvalidArgumentException;
use Quraba\Backup\Exceptions\RetentionFailed;
use Quraba\Backup\Exceptions\StorageUnavailable;
use Quraba\Backup\Identity\ApplicationIdentity;
use Quraba\Backup\Storage\RemoteStorage;

/**
 * Immutable, append-only remote expiry records:
 *
 *   {prefix}/{app_id}/retention/{run_uuid}/application_archive.json
 *   {prefix}/{app_id}/retention/{run_uuid}/media_snapshot.json
 *
 * One record per component, written as soon as THAT component's physical
 * absence is proven — never before, and without waiting for the other
 * component. A retention pass that deletes the archive and then fails to
 * forget the snapshot therefore already tells remote readers (discovery,
 * restore source resolution on a clean host) that the archive is gone.
 *
 * A record is never overwritten: an identical one is adopted (idempotent
 * retries), anything else at its path is a hard collision. Readers get the
 * UNION of a run's component records and of the legacy combined tombstone
 * `retention/{run_uuid}.json` written by older releases (still readable,
 * no longer written).
 */
final readonly class RetentionTombstoneStore
{
    private const int MAX_BYTES = 65536;

    private const string UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function __construct(private RemoteStorage $remote) {}

    /**
     * Records the proven absence of exactly ONE component.
     */
    public function put(RetentionTombstone $record): RetentionTombstone
    {
        if (count($record->components) !== 1) {
            throw new RetentionFailed('An expiry record describes exactly one component.', 'retention.tombstone_failed');
        }

        $layout = $this->remote->layout();
        $path = $layout->assertManaged($layout->componentTombstone($record->runUuid, $record->components[0]));
        $objects = $this->remote->objects();

        try {
            if ($objects->exists($path)) {
                return $this->adopt($path, $record);
            }

            $objects->write($path, $record->encode());
            $readBack = RetentionTombstone::parse($objects->read($path, self::MAX_BYTES));
        } catch (StorageUnavailable $exception) {
            throw new RetentionFailed('The retention expiry record could not be written: '.$exception->getMessage(), 'retention.tombstone_failed');
        } catch (InvalidArgumentException $exception) {
            throw new RetentionFailed('The retention expiry record could not be read back: '.$exception->getMessage(), 'retention.tombstone_failed');
        }

        if (! $readBack->sameExpiry($record)) {
            throw RetentionFailed::tombstoneCollision('the expiry record read back differs from the one written');
        }

        return $readBack;
    }

    /**
     * Everything recorded as expired for one run (union of its component
     * records and a legacy tombstone), or null when nothing is recorded.
     *
     * @throws InvalidArgumentException when a stored record is malformed or names another run
     */
    public function find(string $runUuid): ?RetentionTombstone
    {
        $layout = $this->remote->layout();
        $objects = $this->remote->objects();
        $paths = [$layout->tombstone($runUuid) => null];

        foreach (RetentionTombstone::COMPONENTS as $component) {
            $paths[$layout->componentTombstone($runUuid, $component)] = $component;
        }

        $union = null;

        foreach ($paths as $path => $component) {
            $path = $layout->assertManaged($path);

            if (! $objects->exists($path)) {
                continue;
            }

            $record = self::validated(RetentionTombstone::parse($objects->read($path, self::MAX_BYTES)), $runUuid, $component);
            $union = $union === null ? $record : $union->merge($record);
        }

        return $union;
    }

    /**
     * Every valid expiry of this application/environment, keyed by run UUID.
     * Malformed or foreign documents are ignored (and returned separately),
     * so they can never hide a live backup.
     *
     * @return array{tombstones: array<string, RetentionTombstone>, malformed: list<string>}
     */
    public function all(ApplicationIdentity $identity): array
    {
        $layout = $this->remote->layout();
        $objects = $this->remote->objects();
        $root = $layout->retentionRoot();
        $tombstones = [];
        $malformed = [];

        foreach ($objects->listFiles($root) as $path) {
            $relative = substr($path, strlen($root));

            if (preg_match('~^('.self::UUID.')\.json$~', $relative, $matches) === 1) {
                $component = null;
            } elseif (preg_match('~^('.self::UUID.')/([a-z_]+)\.json$~', $relative, $matches) === 1 && in_array($matches[2], RetentionTombstone::COMPONENTS, true)) {
                $component = $matches[2];
            } else {
                $malformed[] = $path;

                continue;
            }

            try {
                $record = self::validated(RetentionTombstone::parse($objects->read($layout->assertManaged($path), self::MAX_BYTES)), $matches[1], $component);

                if ($record->appId !== $identity->appId) {
                    throw new InvalidArgumentException('belongs to another application');
                }

                if ($record->environment !== $identity->environment) {
                    continue;
                }

                $tombstones[$record->runUuid] = isset($tombstones[$record->runUuid]) ? $tombstones[$record->runUuid]->merge($record) : $record;
            } catch (InvalidArgumentException) {
                $malformed[] = $path;
            }
        }

        return ['tombstones' => $tombstones, 'malformed' => $malformed];
    }

    /**
     * A record must describe the run its path names, and a component record
     * exactly the component its path names.
     */
    private static function validated(RetentionTombstone $record, string $runUuid, ?string $component): RetentionTombstone
    {
        if ($record->runUuid !== $runUuid) {
            throw new InvalidArgumentException('the expiry record names another run than its path');
        }

        if ($component !== null && $record->components !== [$component]) {
            throw new InvalidArgumentException('the expiry record names another component than its path');
        }

        return $record;
    }

    private function adopt(string $path, RetentionTombstone $record): RetentionTombstone
    {
        try {
            $existing = RetentionTombstone::parse($this->remote->objects()->read($path, self::MAX_BYTES));
        } catch (InvalidArgumentException $exception) {
            throw RetentionFailed::tombstoneCollision('an unreadable document occupies the expiry record path ('.$exception->getMessage().')');
        }

        if (! $existing->sameExpiry($record)) {
            throw RetentionFailed::tombstoneCollision(sprintf('existing record [%s] of run %s, new [%s] of run %s', implode(', ', $existing->components), $existing->runUuid, implode(', ', $record->components), $record->runUuid));
        }

        return $existing;
    }
}
