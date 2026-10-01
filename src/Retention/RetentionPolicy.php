<?php

declare(strict_types=1);

namespace Quraba\Backup\Retention;

use Quraba\Backup\Exceptions\RetentionFailed;

/**
 * Keep rules of one policy family (database, media or recovery), with the
 * same meaning as Restic's `--keep-*` options:
 *
 *  - keep_latest N: the N newest backups;
 *  - keep_daily/weekly/monthly/yearly N: for the N most recent days / ISO
 *    weeks / months / years that HAVE backups, the newest backup of each.
 *
 * Periods are computed in UTC so plans are deterministic on every host.
 * A policy that could keep nothing is refused.
 */
final readonly class RetentionPolicy
{
    public const array FAMILIES = ['database', 'media', 'recovery'];

    public const array RULES = ['keep_latest', 'keep_daily', 'keep_weekly', 'keep_monthly', 'keep_yearly'];

    /**
     * @param  array<string, int>  $rules  rule => count (>= 0)
     */
    private function __construct(
        public string $family,
        public array $rules,
    ) {}

    public static function fromConfig(string $family, mixed $settings): self
    {
        if (! in_array($family, self::FAMILIES, true)) {
            throw RetentionFailed::invalidPolicy(sprintf('unknown family [%s]', $family));
        }

        if (! is_array($settings)) {
            throw RetentionFailed::invalidPolicy(sprintf('quraba-backup.retention.%s must be an array of keep_* rules', $family));
        }

        $rules = [];

        foreach ($settings as $rule => $count) {
            if (! in_array($rule, self::RULES, true)) {
                throw RetentionFailed::invalidPolicy(sprintf('unknown rule quraba-backup.retention.%s.%s', $family, (string) $rule));
            }

            if (is_string($count) && preg_match('/^\d+$/', $count) === 1) {
                $count = (int) $count;
            }

            if (! is_int($count) || $count < 0 || $count > 10000) {
                throw RetentionFailed::invalidPolicy(sprintf('quraba-backup.retention.%s.%s must be an integer from 0 to 10000', $family, (string) $rule));
            }

            $rules[(string) $rule] = $count;
        }

        foreach (self::RULES as $rule) {
            $rules[$rule] ??= 0;
        }

        if (array_sum($rules) < 1) {
            throw RetentionFailed::invalidPolicy(sprintf('the %s policy keeps nothing; at least one keep_* rule must be 1 or more', $family));
        }

        return new self($family, $rules);
    }

    public function count(string $rule): int
    {
        return $this->rules[$rule] ?? 0;
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return $this->rules;
    }
}
