<?php

declare(strict_types=1);

namespace Quraba\Backup\Restic;

use Quraba\Backup\Domain\Identifiers;
use Quraba\Backup\Identity\ApplicationIdentity;

/**
 * The single source of Quraba snapshot identity tags:
 *
 *   quraba-backup, app:{uuid}, env:{environment}, kind:{kind}, run:{uuid}
 *
 * Values are validated identifiers only; nothing free-form enters a tag.
 * A snapshot matches only when it carries exactly one tag of each identity
 * dimension and each equals the expected value, so a snapshot that claims two
 * runs or two environments can never be mistaken for ours.
 */
final readonly class SnapshotIdentity
{
    public const string MARKER = 'quraba-backup';

    private const array DIMENSIONS = ['app', 'env', 'kind', 'run'];

    private function __construct(
        public string $appId,
        public string $environment,
        public SnapshotKind $kind,
        public string $runUuid,
    ) {}

    public static function for(ApplicationIdentity $identity, SnapshotKind $kind, string $runUuid): self
    {
        return new self($identity->appId, $identity->environment, $kind, Identifiers::assertUuid($runUuid, 'The run UUID'));
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ResticTag::assertAll([
            self::MARKER,
            'app:'.$this->appId,
            'env:'.$this->environment,
            'kind:'.$this->kind->value,
            'run:'.$this->runUuid,
        ]);
    }

    /**
     * Tags that select every snapshot claiming this run, whatever its other
     * tags say (used to detect conflicting claims).
     *
     * @return list<string>
     */
    public function runSelector(): array
    {
        return [self::MARKER, 'run:'.$this->runUuid];
    }

    /**
     * AND filter selecting every Quraba snapshot of one application and
     * environment (retention proofs, consistency checks).
     *
     * @return list<string>
     */
    public static function applicationSelector(ApplicationIdentity $identity): array
    {
        return ResticTag::assertAll([self::MARKER, 'app:'.$identity->appId, 'env:'.$identity->environment]);
    }

    public function matches(ResticSnapshot $snapshot): bool
    {
        if (! $snapshot->hasTag(self::MARKER)) {
            return false;
        }

        $expected = [
            'app' => $this->appId,
            'env' => $this->environment,
            'kind' => $this->kind->value,
            'run' => $this->runUuid,
        ];

        foreach (self::DIMENSIONS as $dimension) {
            $values = [];

            foreach ($snapshot->tags as $tag) {
                if (str_starts_with($tag, $dimension.':')) {
                    $values[] = substr($tag, strlen($dimension) + 1);
                }
            }

            if ($values !== [$expected[$dimension]]) {
                return false;
            }
        }

        return true;
    }

    public function host(): string
    {
        return 'quraba-'.$this->appId;
    }
}
