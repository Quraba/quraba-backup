<?php

declare(strict_types=1);

namespace Quraba\Backup\Retention;

use Carbon\CarbonImmutable;

/**
 * A deterministic retention plan. Planning never deletes anything.
 */
final readonly class RetentionPlan
{
    /**
     * @param  list<RetentionDecision>  $decisions  grouped by family, newest first
     * @param  array<string, array<string, int>>  $policies
     */
    public function __construct(
        public CarbonImmutable $evaluatedAt,
        public array $decisions,
        public array $policies,
    ) {}

    /**
     * @return list<RetentionDecision>
     */
    public function expired(): array
    {
        return array_values(array_filter($this->decisions, static fn (RetentionDecision $d): bool => $d->expire));
    }

    /**
     * @return list<RetentionDecision>
     */
    public function kept(): array
    {
        return array_values(array_filter($this->decisions, static fn (RetentionDecision $d): bool => ! $d->expire));
    }

    public function decisionFor(string $runUuid): ?RetentionDecision
    {
        foreach ($this->decisions as $decision) {
            if ($decision->candidate->runUuid === $runUuid) {
                return $decision;
            }
        }

        return null;
    }

    /**
     * @return array{evaluated_at: string, policies: array<string, array<string, int>>, keep: int, expire: int, decisions: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'evaluated_at' => $this->evaluatedAt->toIso8601ZuluString(),
            'policies' => $this->policies,
            'keep' => count($this->kept()),
            'expire' => count($this->expired()),
            'decisions' => array_map(static fn (RetentionDecision $d): array => $d->toArray(), $this->decisions),
        ];
    }
}
