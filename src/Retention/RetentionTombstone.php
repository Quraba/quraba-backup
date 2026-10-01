<?php

declare(strict_types=1);

namespace Quraba\Backup\Retention;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Quraba\Backup\Domain\Identifiers;
use Quraba\Backup\Identity\ApplicationIdentity;
use Quraba\Backup\Manifest\ManifestBuilder;
use Throwable;

/**
 * The immutable remote record that retention physically removed some
 * components of a run. Remote manifests are immutable, so a clean host
 * reads these to avoid presenting an expired backup as available.
 *
 * Records are written PER COMPONENT, each one as soon as that component's
 * absence is proven, so a retention pass that removes the archive and then
 * fails to forget the snapshot still tells the truth remotely. The object
 * returned by the store is the union of a run's component records (and of
 * a legacy combined tombstone written by older releases).
 *
 * Only non-secret facts: schema version, run/app/environment identity, the
 * expired component names, the time and the maintenance run that proved the
 * deletion.
 */
final readonly class RetentionTombstone
{
    public const int SCHEMA_VERSION = 1;

    public const string TYPE = 'quraba-backup-retention-tombstone';

    public const array COMPONENTS = ['application_archive', 'media_snapshot'];

    /**
     * @param  list<string>  $components  sorted, subset of COMPONENTS
     */
    public function __construct(
        public string $runUuid,
        public string $appId,
        public string $environment,
        public array $components,
        public CarbonImmutable $expiredAt,
        public string $maintenanceRunUuid,
    ) {
        Identifiers::assertUuid($runUuid, 'The run UUID');
        Identifiers::assertUuid($appId, 'The application ID');
        Identifiers::assertUuid($maintenanceRunUuid, 'The maintenance run UUID');

        if (! ApplicationIdentity::isValidEnvironment($environment)) {
            throw new InvalidArgumentException('Invalid environment.');
        }

        if ($components === [] || array_diff($components, self::COMPONENTS) !== [] || $components !== array_values(array_unique($components))) {
            throw new InvalidArgumentException('Invalid expired components.');
        }
    }

    /**
     * @param  list<string>  $components
     */
    public static function make(string $runUuid, ApplicationIdentity $identity, array $components, CarbonImmutable $expiredAt, string $maintenanceRunUuid): self
    {
        $components = array_values(array_unique($components));
        sort($components);

        return new self($runUuid, $identity->appId, $identity->environment, $components, $expiredAt->utc(), $maintenanceRunUuid);
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function parse(string $json): self
    {
        $data = json_decode($json, true);

        if (! is_array($data) || ($data['schema_version'] ?? null) !== self::SCHEMA_VERSION || ($data['type'] ?? null) !== self::TYPE) {
            throw new InvalidArgumentException('not a retention tombstone');
        }

        $components = $data['expired_components'] ?? null;

        if (! is_array($components) || ! array_is_list($components) || array_filter($components, is_string(...)) !== $components) {
            throw new InvalidArgumentException('invalid expired components');
        }

        $expiredAtValue = $data['expired_at'] ?? null;
        $runUuid = $data['run_uuid'] ?? null;
        $appId = $data['app_id'] ?? null;
        $environment = $data['environment'] ?? null;
        $maintenanceRunUuid = $data['maintenance_run_uuid'] ?? null;

        if (! is_string($expiredAtValue) || ! is_string($runUuid) || ! is_string($appId) || ! is_string($environment) || ! is_string($maintenanceRunUuid)) {
            throw new InvalidArgumentException('invalid tombstone identity or timestamp');
        }

        try {
            $expiredAt = CarbonImmutable::parse($expiredAtValue)->utc();
        } catch (Throwable) {
            throw new InvalidArgumentException('invalid expired_at');
        }

        /** @var list<string> $components */
        return new self(
            $runUuid,
            $appId,
            $environment,
            $components,
            $expiredAt,
            $maintenanceRunUuid,
        );
    }

    /**
     * The facts that must be identical for an existing tombstone to be
     * adopted (time and maintenance run of a retry may differ).
     */
    public function sameExpiry(self $other): bool
    {
        return $this->runUuid === $other->runUuid
            && $this->appId === $other->appId
            && $this->environment === $other->environment
            && $this->components === $other->components;
    }

    public function covers(string $component): bool
    {
        return in_array($component, $this->components, true);
    }

    /**
     * The union of two records of the same run, application and environment.
     *
     * @throws InvalidArgumentException when they describe different runs or applications
     */
    public function merge(self $other): self
    {
        if ($this->runUuid !== $other->runUuid || $this->appId !== $other->appId || $this->environment !== $other->environment) {
            throw new InvalidArgumentException('expiry records of one run name different runs, applications or environments');
        }

        $components = array_values(array_unique([...$this->components, ...$other->components]));
        sort($components);
        $newest = $other->expiredAt->greaterThan($this->expiredAt) ? $other : $this;

        return new self($this->runUuid, $this->appId, $this->environment, $components, $newest->expiredAt, $newest->maintenanceRunUuid);
    }

    public function encode(): string
    {
        return ManifestBuilder::encode([
            'schema_version' => self::SCHEMA_VERSION,
            'type' => self::TYPE,
            'run_uuid' => $this->runUuid,
            'app_id' => $this->appId,
            'environment' => $this->environment,
            'expired_components' => $this->components,
            'expired_at' => $this->expiredAt->toIso8601ZuluString(),
            'maintenance_run_uuid' => $this->maintenanceRunUuid,
        ]);
    }
}
