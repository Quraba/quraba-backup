<?php

declare(strict_types=1);

namespace Quraba\Backup\Support;

use Quraba\Backup\Exceptions\ConfigurationException;

/**
 * Strict readers for configuration values.
 */
final class ConfigValue
{
    /**
     * A positive integer (numeric strings from env() are accepted). Zero,
     * negative and non-numeric values are refused; nothing means "unlimited".
     */
    public static function positiveInt(mixed $value, string $key): int
    {
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value <= 0) {
            throw new ConfigurationException(sprintf('[%s] must be a positive integer; zero, negative or "unlimited" values are refused.', $key));
        }

        return $value;
    }

    public static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
