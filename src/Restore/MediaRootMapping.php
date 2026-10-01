<?php

declare(strict_types=1);

namespace Quraba\Backup\Restore;

/**
 * One logical media root of a snapshot: where it came from, where it was
 * reconstructed (private staging) and where it belongs on this host.
 */
final readonly class MediaRootMapping
{
    public function __construct(
        public string $name,
        public string $snapshotSource,
        public string $workspaceSubtree,
        public string $liveDestination,
        public bool $liveExists = true,
        public bool $allowSymlinks = false,
    ) {}

    /** @return array{name: string, snapshot_source: string, workspace_subtree: string, live_destination: string, live_exists: bool} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'snapshot_source' => $this->snapshotSource,
            'workspace_subtree' => $this->workspaceSubtree,
            'live_destination' => $this->liveDestination,
            'live_exists' => $this->liveExists,
        ];
    }
}
