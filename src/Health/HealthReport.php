<?php

declare(strict_types=1);

namespace Quraba\Backup\Health;

use Carbon\CarbonImmutable;
use Quraba\Backup\Enums\HealthState;

final readonly class HealthReport
{
    /**
     * @param  list<CheckResult>  $checks
     */
    public function __construct(
        public array $checks,
        public CarbonImmutable $checkedAt,
    ) {}

    public function state(): HealthState
    {
        $state = HealthState::Healthy;

        foreach ($this->checks as $check) {
            $state = $state->worst($check->healthImpact());
        }

        return $state;
    }

    public function hasFailures(): bool
    {
        foreach ($this->checks as $check) {
            if ($check->status === CheckStatus::Fail) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = array_fill_keys(array_map(static fn (CheckStatus $status): string => $status->value, CheckStatus::cases()), 0);

        foreach ($this->checks as $check) {
            $counts[$check->status->value]++;
        }

        return $counts;
    }

    /**
     * @return array{state: string, checked_at: string, counts: array<string, int>, checks: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state()->value,
            'checked_at' => $this->checkedAt->toIso8601ZuluString(),
            'counts' => $this->counts(),
            'checks' => array_map(static fn (CheckResult $check): array => $check->toArray(), $this->checks),
        ];
    }
}
