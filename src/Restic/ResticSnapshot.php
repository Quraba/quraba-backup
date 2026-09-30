<?php

declare(strict_types=1);

namespace Quraba\Backup\Restic;

use Carbon\CarbonImmutable;
use Quraba\Backup\Domain\Identifiers;
use Quraba\Backup\Exceptions\ResticCommandFailed;
use Throwable;

/**
 * Minimal snapshot metadata from `restic snapshots --json`. Only snapshots
 * with a full canonical ID are accepted.
 */
final readonly class ResticSnapshot
{
    /**
     * @param  list<string>  $tags
     * @param  list<string>  $paths
     */
    public function __construct(
        public string $id,
        public CarbonImmutable $time,
        public array $tags,
        public array $paths,
        public ?string $hostname,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromJson(array $data): self
    {
        $id = $data['id'] ?? null;

        if (! is_string($id) || ! Identifiers::isFullSnapshotId($id)) {
            throw new ResticCommandFailed('Restic returned a snapshot without a full canonical ID.');
        }

        $time = $data['time'] ?? null;

        try {
            $parsed = is_string($time) ? CarbonImmutable::parse($time)->utc() : null;
        } catch (Throwable) {
            $parsed = null;
        }

        if ($parsed === null) {
            throw new ResticCommandFailed(sprintf('Restic snapshot %s has no valid time.', $id));
        }

        $strings = static fn (mixed $values): array => is_array($values)
            ? array_values(array_filter($values, static fn (mixed $value): bool => is_string($value)))
            : [];

        $hostname = $data['hostname'] ?? null;

        return new self($id, $parsed, $strings($data['tags'] ?? []), $strings($data['paths'] ?? []), is_string($hostname) ? $hostname : null);
    }

    public function hasTag(string $tag): bool
    {
        return in_array($tag, $this->tags, true);
    }

    /**
     * @return array{id: string, time: string, tags: list<string>, paths: list<string>, hostname: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'time' => $this->time->toIso8601ZuluString(),
            'tags' => $this->tags,
            'paths' => $this->paths,
            'hostname' => $this->hostname,
        ];
    }
}
