<?php

declare(strict_types=1);

namespace Quraba\Backup\Models\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Stores and reads timestamps as UTC regardless of the host application's
 * configured timezone. Eloquent's default datetime cast interprets stored
 * values in the application timezone, which would silently shift catalog
 * instants on any application not running in UTC.
 *
 * @implements CastsAttributes<CarbonImmutable|null, mixed>
 */
final class UtcDateTime implements CastsAttributes
{
    private const string FORMAT = 'Y-m-d H:i:s';

    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc();
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(sprintf('Unsupported stored timestamp for [%s].', $key));
        }

        // Tolerate fractional seconds some drivers return.
        $normalized = substr($value, 0, 19);
        $parsed = CarbonImmutable::createFromFormat(self::FORMAT, $normalized, 'UTC');

        if (! $parsed instanceof CarbonImmutable) {
            throw new InvalidArgumentException(sprintf('Unparseable stored timestamp for [%s].', $key));
        }

        return $parsed;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc()->format(self::FORMAT);
        }

        if (is_string($value)) {
            // Strings without an explicit offset are interpreted as UTC, never local time.
            return (new CarbonImmutable($value, 'UTC'))->utc()->format(self::FORMAT);
        }

        throw new InvalidArgumentException(sprintf('Timestamp [%s] must be a DateTimeInterface or string.', $key));
    }
}
