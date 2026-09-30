<?php

declare(strict_types=1);

namespace Quraba\Backup\Workspace;

use Carbon\CarbonImmutable;

/**
 * Read-only description of a workspace found on disk.
 */
final readonly class WorkspaceInfo
{
    public function __construct(
        public string $id,
        public string $path,
        public CarbonImmutable $createdAt,
        public int $ageSeconds,
        public bool $active,
        public bool $abandoned,
    ) {}

    /**
     * @return array{id: string, path: string, created_at: string, age_seconds: int, active: bool, abandoned: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'path' => $this->path,
            'created_at' => $this->createdAt->toIso8601ZuluString(),
            'age_seconds' => $this->ageSeconds,
            'active' => $this->active,
            'abandoned' => $this->abandoned,
        ];
    }
}
