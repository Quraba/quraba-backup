<?php

declare(strict_types=1);

namespace Quraba\Backup\Backup;

final readonly class ReconciliationReport
{
    /**
     * @param  list<array{run_uuid: string, profile: string, before: string, after: string, components: array<string, array<string, mixed>>}>  $items
     * @param  list<array<string, mixed>>  $maintenance  retention intents and maintenance audits
     */
    public function __construct(
        public string $auditUuid,
        public bool $dryRun,
        public array $items,
        public array $maintenance = [],
    ) {}

    public function unresolved(): int
    {
        $runs = count(array_filter($this->items, static fn (array $item): bool => $item['after'] === 'indeterminate'));
        $maintenance = count(array_filter($this->maintenance, fn (array $item): bool => ($item['after'] ?? null) === 'indeterminate'
            || (($item['type'] ?? null) === 'retention_intent' && ($item['outcome'] ?? null) !== 'settled' && ! $this->dryRun)));

        return $runs + $maintenance;
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
            'maintenance' => $this->maintenance,
            'unresolved' => $this->unresolved(),
        ];
    }
}
