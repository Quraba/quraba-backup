<?php

declare(strict_types=1);

namespace Quraba\Backup\Backup;

final readonly class ReconciliationReport
{
    /**
     * @param  list<array{run_uuid: string, profile: string, before: string, after: string, components: array<string, array<string, mixed>>}>  $items
     */
    public function __construct(
        public string $auditUuid,
        public bool $dryRun,
        public array $items,
    ) {}

    public function unresolved(): int
    {
        return count(array_filter($this->items, static fn (array $item): bool => $item['after'] === 'indeterminate'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'audit_uuid' => $this->auditUuid,
            'dry_run' => $this->dryRun,
            'runs' => $this->items,
            'unresolved' => $this->unresolved(),
        ];
    }
}
