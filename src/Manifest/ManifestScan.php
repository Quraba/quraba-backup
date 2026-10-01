<?php

declare(strict_types=1);

namespace Quraba\Backup\Manifest;

/**
 * The result of reading every remote manifest of one application and
 * environment. Malformed documents are reported, never trusted.
 */
final readonly class ManifestScan
{
    /**
     * @param  list<RemoteManifest>  $manifests  valid manifests, oldest first
     * @param  list<array{locator: string, reason: string}>  $malformed
     */
    public function __construct(
        public array $manifests,
        public array $malformed,
        public int $otherEnvironments,
    ) {}

    /**
     * Distinct repository IDs recorded by the valid manifests.
     *
     * @return list<string>
     */
    public function repositoryIds(): array
    {
        $ids = [];

        foreach ($this->manifests as $manifest) {
            if ($manifest->repositoryId !== null) {
                $ids[$manifest->repositoryId] = true;
            }
        }

        return array_keys($ids);
    }
}
