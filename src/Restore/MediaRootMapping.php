<?php

declare(strict_types=1);

namespace Quraba\Backup\Restore;

final readonly class MediaRootMapping
{
    public function __construct(
        public string $name,
        public string $snapshotSource,
        public string $workspaceSubtree,
        public string $liveDestination,
    ) {}

    /** @return array{name: string, snapshot_source: string, workspace_subtree: string, live_destination: string} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'snapshot_source' => $this->snapshotSource,
            'workspace_subtree' => $this->workspaceSubtree,
            'live_destination' => $this->liveDestination,
        ];
    }
}
