<?php

declare(strict_types=1);

namespace Quraba\Backup\Health;

use Quraba\Backup\Enums\HealthState;

/**
 * One diagnostic finding. FAIL is reserved for required checks; optional
 * problems are WARN. Messages must already be free of secrets.
 */
final readonly class CheckResult
{
    /**
     * @param  array<string, mixed>  $details  non-secret structured data
     */
    public function __construct(
        public string $id,
        public string $label,
        public CheckStatus $status,
        public string $message,
        public array $details = [],
        public ?HealthState $impact = null,
    ) {}

    /**
     * @param  array<string, mixed>  $details
     */
    public static function pass(string $id, string $label, string $message, array $details = []): self
    {
        return new self($id, $label, CheckStatus::Pass, $message, $details);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function warn(string $id, string $label, string $message, array $details = [], ?HealthState $impact = null): self
    {
        return new self($id, $label, CheckStatus::Warn, $message, $details, $impact);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function fail(string $id, string $label, string $message, array $details = [], ?HealthState $impact = null): self
    {
        return new self($id, $label, CheckStatus::Fail, $message, $details, $impact);
    }

    public static function skip(string $id, string $label, string $message): self
    {
        return new self($id, $label, CheckStatus::Skip, $message);
    }

    /**
     * How this finding affects overall health.
     */
    public function healthImpact(): HealthState
    {
        return $this->impact ?? match ($this->status) {
            CheckStatus::Pass, CheckStatus::Skip => HealthState::Healthy,
            CheckStatus::Warn => HealthState::Degraded,
            CheckStatus::Fail => HealthState::Failed,
        };
    }

    /**
     * @return array{id: string, label: string, status: string, message: string, details: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'status' => $this->status->value,
            'message' => $this->message,
            'details' => $this->details,
        ];
    }
}
